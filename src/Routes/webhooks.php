<?php
/**
 * Webhook routes - Handles Shopify webhook notifications
 */

use App\Router;
use App\Config;
use App\Services\LaarService;
use App\Services\ShopifyService;
use App\Utils\Logger;
use App\Utils\VerifyShopifyHmac;

$logger = Logger::create('webhooks');

// Load shipping rates for cost calculation
$shippingRates = null;
try {
    $ratesPath = dirname(__DIR__, 2) . '/data/shipping-rates.json';
    if (file_exists($ratesPath)) {
        $shippingRates = json_decode(file_get_contents($ratesPath), true);
    }
} catch (\Exception $e) {
    $logger->error('Failed to load shipping-rates.json in webhooks: ' . $e->getMessage());
}

/**
 * Calculate the real LAAR shipping cost for an order
 * Used to inform the store owner how much free-shipping orders actually cost
 */
function calculateRealShippingCost(array $order, ?array $shippingRates, $logger): ?array
{
    if (!$shippingRates) {
        $logger->warning('shippingRates not loaded, cannot calculate real cost');
        return null;
    }

    $shippingLine = ($order['shipping_lines'] ?? [])[0] ?? null;
    if (!$shippingLine) {
        $logger->warning('No shipping_lines found in order');
        return null;
    }

    $logger->info('shipping_lines[0] data:', [
        'code' => $shippingLine['code'] ?? '',
        'title' => $shippingLine['title'] ?? '',
        'source' => $shippingLine['source'] ?? '',
        'price' => $shippingLine['price'] ?? '',
    ]);

    // Try to extract zone from code (e.g., "LAAR_TL" -> "TL")
    $code = $shippingLine['code'] ?? '';
    $zone = str_replace('LAAR_', '', $code);
    $zoneConfig = $shippingRates['zones'][$zone] ?? null;

    // Fallback: if zone not found, use default zone
    if (!$zoneConfig) {
        $logger->warning("Zone \"{$zone}\" from code \"{$code}\" not found in rates, using default: {$shippingRates['default_zone']}");
        $zone = $shippingRates['default_zone'];
        $zoneConfig = $shippingRates['zones'][$zone] ?? null;
    }

    if (!$zoneConfig) {
        $logger->error('Could not determine zone config for shipping cost calculation');
        return null;
    }

    // Calculate weight in kg
    $totalWeightGrams = 0;
    foreach ($order['line_items'] ?? [] as $item) {
        $totalWeightGrams += (($item['grams'] ?? 0) * ($item['quantity'] ?? 1));
    }
    $totalWeightKg = max(1, (int)ceil($totalWeightGrams / 1000));

    // Calculate real cost: base + extra kg + IVA
    $extraKg = max(0, $totalWeightKg - ($zoneConfig['included_kg'] ?? 1));
    $subtotal = $zoneConfig['base_price'] + ($extraKg * $zoneConfig['price_per_extra_kg']);
    $ivaRate = $shippingRates['iva_rate'] ?? 0;
    $cost = round($subtotal * (1 + $ivaRate), 2);

    $logger->info('Real shipping cost calculated:', ['cost' => $cost, 'zone' => $zone, 'zoneName' => $zoneConfig['name'], 'weightKg' => $totalWeightKg]);

    return [
        'cost' => $cost,
        'zone' => $zone,
        'zoneName' => $zoneConfig['name'],
        'weightKg' => $totalWeightKg,
    ];
}

/**
 * POST /webhooks/orders_paid
 * 
 * Handles Shopify orders/paid webhook
 * Pipeline:
 * 1. Verify HMAC signature
 * 2. Parse order data
 * 3. Check for existing LAAR guide (idempotency)
 * 4. Create LAAR guide
 * 5. Save metafields to order
 * 6. Create fulfillment with tracking
 */
$router->post('/webhooks/orders_paid', function () use ($logger, $shippingRates) {
    // Step 0: Verify HMAC
    if (!VerifyShopifyHmac::middleware()) {
        return; // Response already sent by middleware
    }

    // Parse order from raw body
    $rawBody = VerifyShopifyHmac::getRawBody();
    $order = json_decode($rawBody, true);

    if (!$order) {
        $logger->error('Failed to parse webhook body');
        http_response_code(400);
        Router::json(['error' => 'Invalid JSON body']);
        return;
    }

    $orderId = $order['id'] ?? null;
    $orderName = $order['name'] ?? '#' . ($order['order_number'] ?? '');

    $logger->info('Received orders/paid webhook', [
        'orderId' => $orderId,
        'orderName' => $orderName,
        'email' => $order['email'] ?? '',
    ]);

    // Log shipping address for debugging
    $shippingAddr = $order['shipping_address'] ?? $order['shippingAddress'] ?? null;
    if ($shippingAddr) {
        $logger->info('Shipping address found', $shippingAddr);
    } else {
        $logger->warning('NO SHIPPING ADDRESS IN ORDER');
    }

    try {
        $shopifyService = ShopifyService::getInstance();
        $laarService = LaarService::getInstance();

        // Step 1: Check for existing guide (idempotency)
        $logger->info('Checking for existing LAAR guide...', ['orderId' => $orderId]);
        $existingMetafields = $shopifyService->getOrderLaarMetafields($orderId);

        if ($existingMetafields && ($existingMetafields['exists'] ?? false)) {
            $existingGuia = $existingMetafields['guia'] ?? '';
            $existingLabelUrl = $existingMetafields['labelUrl'] ?? (Config::get('shopify.appUrl') . "/labels/{$existingGuia}");

            // Do not create a duplicate guide, but repair missing store-visible data.
            $costInfo = calculateRealShippingCost($order, $shippingRates, $logger);
            $shippingCost = $costInfo['cost'] ?? null;

            if (empty($existingMetafields['costoEnvio']) || empty($existingMetafields['labelUrl'])) {
                $logger->info('Order already has LAAR guide; repairing missing LAAR metafields', [
                    'orderId' => $orderId,
                    'existingGuia' => $existingGuia,
                    'shippingCost' => $shippingCost,
                ]);
                $shopifyService->saveOrderMetafields(
                    $orderId,
                    $existingGuia,
                    $existingMetafields['pdfUrl'] ?? null,
                    $existingLabelUrl,
                    $shippingCost
                );
            }

            // If the order is still unfulfilled, try to prepare it again with the existing guide.
            try {
                $trackingUrl = "https://fenix.laarcourier.com/Tracking/?guia={$existingGuia}";
                $shopifyService->createFulfillment($orderId, $existingGuia, $trackingUrl);
            } catch (\Exception $fulfillmentError) {
                $logger->warning('Existing guide found, but fulfillment repair failed: ' . $fulfillmentError->getMessage(), [
                    'orderId' => $orderId,
                    'existingGuia' => $existingGuia,
                ]);
            }

            $logger->info('Order already has LAAR guide, skipped duplicate guide creation', [
                'orderId' => $orderId,
                'existingGuia' => $existingGuia,
            ]);
            Router::json([
                'ok' => true,
                'skipped' => true,
                'message' => 'Guide already exists; missing data repaired when possible',
                'guia' => $existingGuia,
            ]);
            return;
        }

        // Step 2: Build and create LAAR guide
        $logger->info('Creating LAAR guide...', ['orderId' => $orderId]);
        $guidePayload = $laarService->buildGuidePayload($order);
        $logger->info('LAAR guide payload', $guidePayload);
        $guideResult = $laarService->createGuide($guidePayload);

        $guia = $guideResult['guia'];
        $pdfUrl = $guideResult['pdfUrl'];
        $logger->info('LAAR guide created', ['orderId' => $orderId, 'guia' => $guia, 'pdfUrl' => $pdfUrl]);

        // Build proxy label URL
        $labelUrl = Config::get('shopify.appUrl') . "/labels/{$guia}";

        // Step 3: Calculate real shipping cost (for store owner visibility)
        $shippingCost = null;
        $shippingPrice = (float)(($order['shipping_lines'] ?? [])[0]['price'] ?? '0');
        $isFreeShipping = $shippingPrice === 0.0;

        $costInfo = calculateRealShippingCost($order, $shippingRates, $logger);
        if ($costInfo) {
            $shippingCost = $costInfo['cost'];
            if ($isFreeShipping) {
                $logger->info('Envío gratis - Costo real LAAR asumido por la tienda', [
                    'orderId' => $orderId, 'orderName' => $orderName, 'shippingCost' => $shippingCost,
                    'zone' => $costInfo['zone'], 'zoneName' => $costInfo['zoneName'], 'weightKg' => $costInfo['weightKg'],
                ]);
            } else {
                $logger->info('Costo de envío LAAR (cobrado al cliente)', [
                    'orderId' => $orderId, 'orderName' => $orderName, 'shippingCost' => $shippingCost,
                    'zone' => $costInfo['zone'], 'zoneName' => $costInfo['zoneName'],
                ]);
            }
        } else {
            $logger->warning('No se pudo calcular el costo real de envío LAAR', ['orderId' => $orderId, 'orderName' => $orderName]);
        }

        // Step 4: Save metafields to order
        $logger->info('Saving metafields to order...', ['orderId' => $orderId, 'guia' => $guia]);
        $shopifyService->saveOrderMetafields($orderId, $guia, $pdfUrl, $labelUrl, $shippingCost);

        // Step 5: Create fulfillment with tracking
        $logger->info('Creating fulfillment...', ['orderId' => $orderId, 'guia' => $guia]);
        $trackingUrl = "https://fenix.laarcourier.com/Tracking/?guia={$guia}";

        try {
            $fulfillment = $shopifyService->createFulfillment($orderId, $guia, $trackingUrl);

            if ($fulfillment) {
                $logger->info('Fulfillment created successfully', [
                    'orderId' => $orderId,
                    'fulfillmentId' => $fulfillment['id'] ?? null,
                ]);
            } else {
                $logger->warning('Fulfillment not created (may already be fulfilled)', ['orderId' => $orderId]);
            }
        } catch (\Exception $fulfillmentError) {
            $logger->error('Failed to create fulfillment (metafields saved): ' . $fulfillmentError->getMessage());
        }

        // Step 6: Add tag for easy filtering
        try {
            $shopifyService->addOrderTags($orderId, ['laar-guia-created', "guia-{$guia}"]);
        } catch (\Exception $tagError) {
            $logger->warning('Failed to add tags (non-critical): ' . $tagError->getMessage());
        }

        $logger->info('Order processing completed successfully', [
            'orderId' => $orderId,
            'orderName' => $orderName,
            'guia' => $guia,
        ]);

        Router::json([
            'ok' => true,
            'guia' => $guia,
            'pdfUrl' => $pdfUrl,
            'message' => 'Guide created and fulfillment processed',
        ]);
    } catch (\Exception $e) {
        $logger->error('Order processing failed', [
            'orderId' => $orderId,
            'orderName' => $orderName,
            'error' => $e->getMessage(),
        ]);

        http_response_code(500);
        Router::json([
            'ok' => false,
            'error' => $e->getMessage(),
        ]);
    }
});

/**
 * POST /webhooks/test
 * Test endpoint to verify webhook setup
 */
$router->post('/webhooks/test', function () use ($logger) {
    if (!VerifyShopifyHmac::middleware()) {
        return;
    }

    $logger->info('Test webhook received');

    $rawBody = VerifyShopifyHmac::getRawBody();
    $body = json_decode($rawBody, true) ?: [];

    Router::json([
        'ok' => true,
        'message' => 'Test webhook received',
        'receivedData' => $body,
    ]);
});