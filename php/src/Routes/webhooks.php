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
$router->post('/webhooks/orders_paid', function () use ($logger) {
    // Step 0: Verify HMAC
    if (!VerifyShopifyHmac::middleware()) {
        return; // Response already sent by middleware
    }

    // Parse order from raw body
    $rawBody = file_get_contents('php://input') ?: '';
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
            $logger->info('Order already has LAAR guide, skipping', [
                'orderId' => $orderId,
                'existingGuia' => $existingMetafields['guia'] ?? '',
            ]);
            Router::json([
                'ok' => true,
                'skipped' => true,
                'message' => 'Guide already exists',
                'guia' => $existingMetafields['guia'] ?? '',
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

        // Step 3: Save metafields to order
        $logger->info('Saving metafields to order...', ['orderId' => $orderId, 'guia' => $guia]);
        $shopifyService->saveOrderMetafields($orderId, $guia, $pdfUrl, $labelUrl);

        // Step 4: Create fulfillment with tracking
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

        // Step 5: Add tag for easy filtering
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

    $rawBody = file_get_contents('php://input') ?: '';
    $body = json_decode($rawBody, true) ?: [];

    Router::json([
        'ok' => true,
        'message' => 'Test webhook received',
        'receivedData' => $body,
    ]);
});
