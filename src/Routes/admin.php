<?php
/**
 * Admin routes - Protected endpoints for manual maintenance operations.
 *
 * All routes here require the header:
 *   X-Admin-Secret: <value of ADMIN_SECRET env var>
 *
 * If ADMIN_SECRET is not set the routes are disabled entirely.
 */

use App\Router;
use App\Config;
use App\Services\LaarService;
use App\Services\ShopifyService;
use App\Utils\Logger;

$logger = Logger::create('admin');

// ── Auth helper ──────────────────────────────────────────────────────────────

/**
 * Verify the X-Admin-Secret header against ADMIN_SECRET env var.
 * Returns true if authorized, sends 401/403 and returns false otherwise.
 */
function adminAuth(): bool
{
    $secret = $_ENV['ADMIN_SECRET'] ?? getenv('ADMIN_SECRET') ?? '';

    if (empty($secret)) {
        http_response_code(403);
        Router::json(['error' => 'Admin routes are disabled: ADMIN_SECRET env var not set']);
        return false;
    }

    $provided = $_SERVER['HTTP_X_ADMIN_SECRET'] ?? '';

    if (!hash_equals($secret, $provided)) {
        http_response_code(401);
        Router::json(['error' => 'Unauthorized']);
        return false;
    }

    return true;
}

// ── Shipping rates loader (shared) ───────────────────────────────────────────

function adminLoadShippingRates(): ?array
{
    $path = dirname(__DIR__, 2) . '/data/shipping-rates.json';
    if (!file_exists($path)) {
        return null;
    }
    return json_decode(file_get_contents($path), true) ?: null;
}

/**
 * Calculate real LAAR shipping cost from order data.
 * Mirrors the logic in webhooks.php.
 */
function adminCalcRealShippingCost(array $order, ?array $shippingRates): ?array
{
    if (!$shippingRates) {
        return null;
    }

    $shippingLine = ($order['shipping_lines'] ?? [])[0] ?? null;
    if (!$shippingLine) {
        return null;
    }

    $code       = $shippingLine['code'] ?? '';
    $zone       = str_replace('LAAR_', '', $code);
    $zoneConfig = $shippingRates['zones'][$zone] ?? null;

    if (!$zoneConfig) {
        $zone       = $shippingRates['default_zone'] ?? 'TP';
        $zoneConfig = $shippingRates['zones'][$zone] ?? null;
    }

    if (!$zoneConfig) {
        return null;
    }

    $totalGrams = 0;
    foreach ($order['line_items'] ?? [] as $item) {
        $totalGrams += (($item['grams'] ?? 0) * ($item['quantity'] ?? 1));
    }
    $weightKg = max(1, (int)ceil($totalGrams / 1000));

    $extraKg  = max(0, $weightKg - ($zoneConfig['included_kg'] ?? 1));
    $subtotal = $zoneConfig['base_price'] + ($extraKg * $zoneConfig['price_per_extra_kg']);
    $ivaRate  = $shippingRates['iva_rate'] ?? 0;
    $cost     = round($subtotal * (1 + $ivaRate), 2);

    return [
        'cost'     => $cost,
        'zone'     => $zone,
        'zoneName' => $zoneConfig['name'],
        'weightKg' => $weightKg,
    ];
}

/**
 * Convert the Spanish-formatted order data into the Shopify REST API format
 * expected by LaarService::buildGuidePayload().
 */
function adminToShopifyRestFormat(array $order): array
{
    $env   = $order['direccionEnvio']       ?? [];
    $bill  = $order['direccionFacturacion'] ?? [];
    $cli   = $order['cliente']              ?? [];
    $prods = $order['productos']            ?? [];

    $shippingAddress = [
        'name'      => $env['nombre']       ?? '',
        'company'   => $env['empresa']      ?? '',
        'address1'  => $env['direccion1']   ?? '',
        'address2'  => $env['direccion2']   ?? '',
        'city'      => $env['ciudad']       ?? '',
        'province'  => $env['provincia']    ?? '',
        'zip'       => $env['codigoPostal'] ?? '',
        'country'   => $env['pais']         ?? 'Ecuador',
        'phone'     => $env['telefono']     ?? '',
        'latitude'  => null,
        'longitude' => null,
    ];

    $billingAddress = [
        'name'     => $bill['nombre']       ?? '',
        'company'  => $bill['empresa']      ?? '',
        'address1' => $bill['direccion1']   ?? '',
        'address2' => $bill['direccion2']   ?? '',
        'city'     => $bill['ciudad']       ?? '',
        'province' => $bill['provincia']    ?? '',
        'zip'      => $bill['codigoPostal'] ?? '',
        'country'  => $bill['pais']         ?? 'Ecuador',
        'phone'    => $bill['telefono']     ?? '',
    ];

    $customer = [
        'id'         => $cli['id']       ?? null,
        'first_name' => $cli['nombre']   ?? '',
        'last_name'  => $cli['apellido'] ?? '',
        'email'      => $cli['email']    ?? '',
        'phone'      => $cli['telefono'] ?? null,
    ];

    $lineItems = [];
    foreach ($prods as $prod) {
        $lineItems[] = [
            'id'               => $prod['id']            ?? null,
            'product_id'       => $prod['productoId']    ?? null,
            'variant_id'       => $prod['varianteId']    ?? null,
            'sku'              => $prod['sku']            ?? '',
            'title'            => $prod['titulo']         ?? '',
            'quantity'         => (int)($prod['cantidad'] ?? 1),
            'price'            => $prod['precioUnitario'] ?? '0.00',
            'grams'            => 0,
            'requires_shipping' => (bool)($prod['requiereEnvio'] ?? true),
        ];
    }

    $shippingLines = [];
    foreach ($order['envios'] ?? [] as $envio) {
        $shippingLines[] = [
            'id'     => $envio['id']     ?? null,
            'title'  => $envio['titulo'] ?? 'LAAR Courier',
            'code'   => $envio['codigo'] ?? 'LAAR_TP',
            'price'  => $envio['precio'] ?? '0.00',
            'source' => $envio['origen'] ?? 'LAAR Courier',
        ];
    }

    return [
        'id'               => $order['id'],
        'name'             => $order['numeroPedido'] ?? ('#' . $order['id']),
        'email'            => $cli['email'] ?? '',
        'note'             => $order['notas'] ?? '',
        'note_attributes'  => [],
        'shipping_address' => $shippingAddress,
        'billing_address'  => $billingAddress,
        'customer'         => $customer,
        'line_items'       => $lineItems,
        'shipping_lines'   => $shippingLines,
        'total_weight'     => 0,
        'financial_status'   => $order['estadoFinanciero']     ?? 'paid',
        'fulfillment_status' => $order['estadoCumplimiento']   ?? null,
    ];
}

// ── Static order list ─────────────────────────────────────────────────────────
// These are the 4 orders that were not processed by the webhook at the time
// of purchase. They are hardcoded here for a one-time reprocessing run.
$pendingOrders = [
    [
        "id" => 11810344403055,
        "numeroPedido" => "#1045",
        "estadoFinanciero" => "pagado",
        "estadoCumplimiento" => "pendiente",
        "envios" => [["id" => 10993487085679, "titulo" => "LAAR Courier", "codigo" => "LAAR_TP", "precio" => "3.99", "origen" => "LAAR Courier"]],
        "notas" => "Documento: Cédula - 2300239056",
        "cliente" => ["id" => 24052064944239, "nombre" => "Jose", "apellido" => "Bohórquez", "email" => "jabzam23@gmail.com", "telefono" => null],
        "direccionFacturacion" => ["nombre" => "Jose Joaquín Bohórquez Zambrano", "empresa" => null, "direccion1" => "Fray Leonardo Murialdo & Manuel Matheu", "direccion2" => "S2", "ciudad" => "Quito", "provincia" => null, "codigoPostal" => "170138", "pais" => "Ecuador", "telefono" => "0998816306"],
        "direccionEnvio" => ["nombre" => "Jose Bohórquez", "empresa" => "Casa de las Diversidades Q+", "direccion1" => "Calle Galápagos Oe6-147 y Cuenca", "direccion2" => "Servicio Casa de las Diversidades Q+", "ciudad" => "Quito", "provincia" => null, "codigoPostal" => "170402", "pais" => "Ecuador", "telefono" => "0998816306"],
        "productos" => [
            ["id" => 35120111353967, "sku" => "8472", "titulo" => "BISGLICINATO DE MAGNESIO", "cantidad" => 2, "precioUnitario" => "11.89", "requiereEnvio" => true, "varianteId" => 44028152741999, "productoId" => 7990944202863],
            ["id" => null, "sku" => "4440", "titulo" => "ENVÍO LOCAL 15%", "cantidad" => 1, "precioUnitario" => "3.99", "requiereEnvio" => false],
        ],
    ],
    [
        "id" => 11810142453871,
        "numeroPedido" => "#1044",
        "estadoFinanciero" => "pagado",
        "estadoCumplimiento" => "pendiente",
        "envios" => [["id" => 10993359650927, "titulo" => "LAAR Courier", "codigo" => "LAAR_TP", "precio" => "0.00", "origen" => "LAAR Courier"]],
        "notas" => "Documento: Cédula - 1715619340",
        "cliente" => ["id" => 24052025426031, "nombre" => "ESTHELA ELISA", "apellido" => "RAMOS TIRADO", "email" => "contabilidadfcpc@gmail.com", "telefono" => null],
        "direccionFacturacion" => ["nombre" => "ESTHELA ELISA RAMOS TIRADO", "empresa" => null, "direccion1" => "Gonzalo Valdivieso Lt2 e Ignacio Asín (Sector La Florida – Norte de Quito)", "direccion2" => null, "ciudad" => "QUITO", "provincia" => null, "codigoPostal" => "170528", "pais" => "Ecuador", "telefono" => "+593988793689"],
        "direccionEnvio" => ["nombre" => "ESTHELA ELISA RAMOS TIRADO", "empresa" => null, "direccion1" => "Gonzalo Valdivieso Lt2 e Ignacio Asín (Sector La Florida – Norte de Quito)", "direccion2" => null, "ciudad" => "QUITO", "provincia" => null, "codigoPostal" => "170528", "pais" => "Ecuador", "telefono" => "+593988793689"],
        "productos" => [
            ["id" => 35119704080495, "sku" => "32",   "titulo" => "COLON LIVE POLVO",         "cantidad" => 1, "precioUnitario" => "15.99", "requiereEnvio" => true, "varianteId" => 44028152086639, "productoId" => 7990943514735],
            ["id" => 35119704113263, "sku" => "64",   "titulo" => "GEL GARDEN",               "cantidad" => 1, "precioUnitario" => "7.99",  "requiereEnvio" => true, "varianteId" => 44028152152175, "productoId" => 7990943580271],
            ["id" => 35119704146031, "sku" => "5976", "titulo" => "MELATONINA",               "cantidad" => 1, "precioUnitario" => "13.99", "requiereEnvio" => true, "varianteId" => 44028152676463, "productoId" => 7990944137327],
            ["id" => 35119704178799, "sku" => "1",    "titulo" => "CLOROFILA",                "cantidad" => 1, "precioUnitario" => "15.99", "requiereEnvio" => true, "varianteId" => 44028152119407, "productoId" => 7990943547503],
            ["id" => 35119704211567, "sku" => "8472", "titulo" => "BISGLICINATO DE MAGNESIO", "cantidad" => 1, "precioUnitario" => "11.89", "requiereEnvio" => true, "varianteId" => 44028152741999, "productoId" => 7990944202863],
        ],
    ],
    [
        "id" => 11808608780399,
        "numeroPedido" => "#1043",
        "estadoFinanciero" => "pagado",
        "estadoCumplimiento" => "pendiente",
        "envios" => [["id" => 10992194912367, "titulo" => "LAAR Courier", "codigo" => "LAAR_TP", "precio" => "3.99", "origen" => "LAAR Courier"]],
        "notas" => "Documento: Cédula - 0105714893",
        "cliente" => ["id" => 24049514545263, "nombre" => "Carolina", "apellido" => "Reyes", "email" => "caritoreyestinoco@gmail.com", "telefono" => null],
        "direccionFacturacion" => ["nombre" => "Carolina Reyes", "empresa" => null, "direccion1" => "Gran Colombia 8-50 y Luis cordero", "direccion2" => "En Romeo Joyería", "ciudad" => "Cuenca", "provincia" => null, "codigoPostal" => null, "pais" => "Ecuador", "telefono" => "0987509888"],
        "direccionEnvio" => ["nombre" => "Carolina Reyes", "empresa" => null, "direccion1" => "Gran Colombia 8-50 y Luis cordero", "direccion2" => "En Romeo Joyería", "ciudad" => "Cuenca", "provincia" => null, "codigoPostal" => null, "pais" => "Ecuador", "telefono" => "0987509888"],
        "productos" => [
            ["id" => 35116863553647, "sku" => "4339", "titulo" => "COLÁGENO HIDROLIZADO", "cantidad" => 1, "precioUnitario" => "27.99", "requiereEnvio" => true, "varianteId" => 44028152905839, "productoId" => 7990944366703],
            ["id" => null, "sku" => "4440", "titulo" => "ENVÍO LOCAL 15%", "cantidad" => 1, "precioUnitario" => "3.99", "requiereEnvio" => false],
        ],
    ],
    [
        "id" => 11805349216367,
        "numeroPedido" => "#1042",
        "estadoFinanciero" => "pagado",
        "estadoCumplimiento" => "pendiente",
        "envios" => [["id" => 10989692846191, "titulo" => "LAAR Courier", "codigo" => "LAAR_TE", "precio" => "0.00", "origen" => "LAAR Courier"]],
        "notas" => "Documento: Cédula - 1207172691",
        "cliente" => ["id" => 23987221463151, "nombre" => "Boris", "apellido" => "Vega", "email" => "vegajcarriel@gmail.com", "telefono" => null],
        "direccionFacturacion" => ["nombre" => "Boris Vega", "empresa" => "Frigoríficos K3", "direccion1" => "Antonio Sotomayor (Cab. en Playas de Vinces)", "direccion2" => "Avenida 13 de enero, a 50 metros del cementerio", "ciudad" => "Vinces", "provincia" => null, "codigoPostal" => "120550", "pais" => "Ecuador", "telefono" => "+593960012070"],
        "direccionEnvio" => ["nombre" => "Boris Vega", "empresa" => "Frigoríficos K3", "direccion1" => "Antonio Sotomayor (Cab. en Playas de Vinces)", "direccion2" => "Avenida 13 de enero, a 50 metros del cementerio", "ciudad" => "Vinces", "provincia" => null, "codigoPostal" => "120550", "pais" => "Ecuador", "telefono" => "+593960012070"],
        "productos" => [
            ["id" => 35111042449519, "sku" => "7540", "titulo" => "CITRATO DE MAGNESIO",              "cantidad" => 1, "precioUnitario" => "9.10",  "requiereEnvio" => true, "varianteId" => 44028152610927, "productoId" => 7990944006255],
            ["id" => 35111042482287, "sku" => "7540", "titulo" => "CITRATO DE MAGNESIO",              "cantidad" => 1, "precioUnitario" => "9.10",  "requiereEnvio" => true, "varianteId" => 44028152610927, "productoId" => 7990944006255],
            ["id" => 35111042515055, "sku" => "7140", "titulo" => "CITRATO DE MAGNESIO + VITAMINA C", "cantidad" => 2, "precioUnitario" => "10.49", "requiereEnvio" => true, "varianteId" => 44028152643695, "productoId" => 7990944104559],
            ["id" => 35111042547823, "sku" => "7747", "titulo" => "CITRATO DE POTASIO",               "cantidad" => 1, "precioUnitario" => "16.99", "requiereEnvio" => true, "varianteId" => 44580423237743, "productoId" => 8082492981359],
        ],
    ],
];

// ── Route: POST /admin/reprocess-orders ─────────────────────────────────────

/**
 * POST /admin/reprocess-orders
 *
 * Manually triggers the full LAAR guide + fulfillment pipeline for the 4
 * orders that were not processed by the webhook.
 *
 * Headers required:
 *   X-Admin-Secret: <ADMIN_SECRET>
 *
 * Optional JSON body:
 *   { "orderId": 11810344403055 }   → process only that order
 */
$router->post('/admin/reprocess-orders', function () use ($logger, $pendingOrders) {
    if (!adminAuth()) {
        return;
    }

    $body       = Router::getJsonBody();
    $filterById = isset($body['orderId']) ? (string)$body['orderId'] : null;

    $shippingRates  = adminLoadShippingRates();
    $shopifyService = ShopifyService::getInstance();
    $laarService    = LaarService::getInstance();
    $appUrl         = Config::get('shopify.appUrl');

    $results = [];

    foreach ($pendingOrders as $rawOrder) {
        $orderId   = $rawOrder['id'];
        $orderName = $rawOrder['numeroPedido'] ?? '#' . $orderId;

        if ($filterById !== null && (string)$orderId !== $filterById) {
            continue;
        }

        $logger->info("Admin reprocess: starting {$orderName}", ['orderId' => $orderId]);

        try {
            // ── Idempotency check ──────────────────────────────────────────
            $existing = $shopifyService->getOrderLaarMetafields($orderId);

            if ($existing && ($existing['exists'] ?? false)) {
                $existingGuia = $existing['guia'];
                $logger->info("Order {$orderName} already has guide {$existingGuia}, attempting repair");

                // Repair missing metafields
                $order        = adminToShopifyRestFormat($rawOrder);
                $costInfo     = adminCalcRealShippingCost($order, $shippingRates);
                $shippingCost = $costInfo['cost'] ?? null;
                $labelUrl     = "{$appUrl}/labels/{$existingGuia}";

                if (empty($existing['costoEnvio']) || empty($existing['labelUrl'])) {
                    $shopifyService->saveOrderMetafields($orderId, $existingGuia, $existing['pdfUrl'] ?? null, $labelUrl, $shippingCost);
                    $logger->info("Repaired missing metafields for {$orderName}");
                }

                // Repair fulfillment
                try {
                    $trackingUrl = "https://fenix.laarcourier.com/Tracking/?guia={$existingGuia}";
                    $shopifyService->createFulfillment($orderId, $existingGuia, $trackingUrl);
                } catch (\Exception $fe) {
                    $logger->warning("Fulfillment repair failed for {$orderName}: " . $fe->getMessage());
                }

                $results[] = [
                    'order'   => $orderName,
                    'orderId' => $orderId,
                    'status'  => 'skipped',
                    'reason'  => 'Guide already exists',
                    'guia'    => $existingGuia,
                ];
                continue;
            }

            // ── Build LAAR payload ─────────────────────────────────────────
            $order        = adminToShopifyRestFormat($rawOrder);
            $guidePayload = $laarService->buildGuidePayload($order);
            $logger->info("Built LAAR payload for {$orderName}", [
                'city'   => $guidePayload['destino']['ciudadD'] ?? '',
                'pieces' => $guidePayload['noPiezas'] ?? 0,
                'weight' => $guidePayload['peso'] ?? 0,
            ]);

            // ── Create LAAR guide ──────────────────────────────────────────
            $guideResult = $laarService->createGuide($guidePayload);
            $guia        = $guideResult['guia'];
            $pdfUrl      = $guideResult['pdfUrl'] ?? null;
            $labelUrl    = "{$appUrl}/labels/{$guia}";
            $logger->info("LAAR guide created for {$orderName}: {$guia}");

            // ── Save metafields ────────────────────────────────────────────
            $costInfo     = adminCalcRealShippingCost($order, $shippingRates);
            $shippingCost = $costInfo['cost'] ?? null;
            $shopifyService->saveOrderMetafields($orderId, $guia, $pdfUrl, $labelUrl, $shippingCost);
            $logger->info("Metafields saved for {$orderName}");

            // ── Create fulfillment ─────────────────────────────────────────
            $trackingUrl  = "https://fenix.laarcourier.com/Tracking/?guia={$guia}";
            $fulfillment  = null;
            $fulfillError = null;
            try {
                $fulfillment = $shopifyService->createFulfillment($orderId, $guia, $trackingUrl);
                $logger->info("Fulfillment created for {$orderName}", ['fulfillmentId' => $fulfillment['id'] ?? null]);
            } catch (\Exception $fe) {
                $fulfillError = $fe->getMessage();
                $logger->warning("Fulfillment failed for {$orderName} (guide saved): " . $fulfillError);
            }

            // ── Tags ───────────────────────────────────────────────────────
            try {
                $shopifyService->addOrderTags($orderId, ['laar-guia-created', "guia-{$guia}"]);
            } catch (\Exception $te) {
                $logger->warning("Tags failed for {$orderName} (non-critical): " . $te->getMessage());
            }

            $results[] = [
                'order'          => $orderName,
                'orderId'        => $orderId,
                'status'         => 'ok',
                'guia'           => $guia,
                'pdfUrl'         => $pdfUrl,
                'labelUrl'       => $labelUrl,
                'trackingUrl'    => $trackingUrl,
                'shippingCost'   => $shippingCost,
                'fulfillmentId'  => $fulfillment['id'] ?? null,
                'fulfillError'   => $fulfillError,
            ];

        } catch (\Exception $e) {
            $logger->error("Admin reprocess failed for {$orderName}: " . $e->getMessage());
            $results[] = [
                'order'   => $orderName,
                'orderId' => $orderId,
                'status'  => 'error',
                'error'   => $e->getMessage(),
            ];
        }
    }

    $ok      = count(array_filter($results, fn($r) => $r['status'] === 'ok'));
    $skipped = count(array_filter($results, fn($r) => $r['status'] === 'skipped'));
    $errors  = count(array_filter($results, fn($r) => $r['status'] === 'error'));

    Router::json([
        'ok'      => $errors === 0,
        'summary' => ['processed' => $ok, 'skipped' => $skipped, 'errors' => $errors],
        'results' => $results,
    ]);
});

/**
 * GET /admin/reprocess-orders
 *
 * Returns the list of pending orders that would be processed, without executing anything.
 * Requires the same X-Admin-Secret header.
 */
$router->get('/admin/reprocess-orders', function () use ($pendingOrders) {
    if (!adminAuth()) {
        return;
    }

    $preview = array_map(fn($o) => [
        'orderId'     => $o['id'],
        'orderName'   => $o['numeroPedido'],
        'customer'    => ($o['cliente']['nombre'] ?? '') . ' ' . ($o['cliente']['apellido'] ?? ''),
        'city'        => $o['direccionEnvio']['ciudad'] ?? '',
        'shippingCode' => $o['envios'][0]['codigo'] ?? '',
    ], $pendingOrders);

    Router::json([
        'pendingOrders' => $preview,
        'count'         => count($pendingOrders),
        'hint'          => 'POST to this URL with the same header to execute reprocessing',
    ]);
});
