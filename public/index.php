<?php
/**
 * Application entry point
 * All requests are routed through this file via .htaccess
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use App\Config;
use App\Router;
use App\Utils\Logger;

// Load environment variables
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

// Validate configuration
try {
    Config::validate();
} catch (\Exception $e) {
    $logger = Logger::create('server');
    $logger->error('Configuration error: ' . $e->getMessage());
    $logger->error('Please check your .env file and ensure all required variables are set.');
    http_response_code(500);
    echo json_encode(['error' => 'Server configuration error']);
    exit(1);
}

// Create and run the router
$router = new Router();

// Register routes
$router->get('/', function () {
    Router::json([
        'name' => 'LAAR-Shopify Integration',
        'version' => '1.0.0',
        'healthCheck' => '/health'
    ]);
});

$router->get('/health', function () {
    Router::json([
        'status' => 'ok',
        'timestamp' => date('c'),
        'environment' => Config::get('nodeEnv')
    ]);
});

// Token status endpoint
$router->get('/token-status', function () {
    $tokenStorage = \App\Services\TokenStorage::getInstance();
    $shop = Config::get('shopify.storeDomain');
    $hasToken = $tokenStorage->hasToken($shop);
    $tokenData = $tokenStorage->getTokenData($shop);

    Router::json([
        'shop' => $shop,
        'authenticated' => $hasToken,
        'installedAt' => $tokenData['installedAt'] ?? null,
        'message' => $hasToken
            ? 'App is authenticated and ready'
            : "Please authenticate at /auth?shop={$shop}"
    ]);
});

// Register carrier service endpoint
$router->post('/register-carrier', function () {
    try {
        $shopifyService = \App\Services\ShopifyService::getInstance();
        $result = $shopifyService->registerCarrierService();
        Router::json(['success' => true, 'carrierService' => $result]);
    } catch (\Exception $e) {
        http_response_code(500);
        Router::json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
});

// Setup metafields endpoint
$router->post('/setup-metafields', function () {
    try {
        $shopifyService = \App\Services\ShopifyService::getInstance();
        $results = $shopifyService->createLabelMetafieldDefinitions();
        Router::json(['success' => true, 'results' => $results]);
    } catch (\Exception $e) {
        http_response_code(500);
        Router::json([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
});

// Fix old label URLs that still point to the old Render server
$router->get('/fix-old-labels', function () {
    $logger = \App\Utils\Logger::create('fix-labels');
    try {
        $shopifyService = \App\Services\ShopifyService::getInstance();
        $appUrl = Config::get('shopify.appUrl');
        $token = $shopifyService->getAccessToken();
        $storeDomain = Config::get('shopify.storeDomain');
        $apiVersion = Config::get('shopify.apiVersion');

        $client = new \GuzzleHttp\Client(['timeout' => 30]);
        $fixed = 0;
        $checked = 0;
        $errors = [];

        // Fetch orders with laar-guia-created tag (these had guides created)
        $url = "https://{$storeDomain}/admin/api/{$apiVersion}/orders.json?status=any&tag=laar-guia-created&limit=250";

        $response = $client->get($url, [
            'headers' => ['X-Shopify-Access-Token' => $token],
        ]);
        $data = json_decode($response->getBody()->getContents(), true);
        $orders = $data['orders'] ?? [];

        foreach ($orders as $order) {
            $checked++;
            $orderId = $order['id'];

            try {
                $metafields = $shopifyService->getOrderLaarMetafields($orderId);
                if (!$metafields || !($metafields['exists'] ?? false)) {
                    continue;
                }

                $labelUrl = $metafields['labelUrl'] ?? '';
                $guia = $metafields['guia'] ?? '';

                // Check if URL points to old server (not current appUrl)
                if (!empty($labelUrl) && !empty($guia) && !str_starts_with($labelUrl, $appUrl)) {
                    $newLabelUrl = "{$appUrl}/labels/{$guia}";
                    $shopifyService->saveOrderMetafields($orderId, $guia, null, $newLabelUrl);
                    $fixed++;
                    $logger->info("Fixed label URL for order {$orderId}", [
                        'guia' => $guia,
                        'old' => $labelUrl,
                        'new' => $newLabelUrl,
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = ['orderId' => $orderId, 'error' => $e->getMessage()];
                $logger->error("Failed to fix order {$orderId}: " . $e->getMessage());
            }
        }

        Router::json([
            'success' => true,
            'checked' => $checked,
            'fixed' => $fixed,
            'errors' => $errors,
            'message' => "Checked {$checked} orders, fixed {$fixed} label URLs to use {$appUrl}",
        ]);
    } catch (\Exception $e) {
        $logger->error('Fix old labels failed: ' . $e->getMessage());
        http_response_code(500);
        Router::json(['success' => false, 'error' => $e->getMessage()]);
    }
});

// Include route files
require_once __DIR__ . '/../src/Routes/auth.php';
require_once __DIR__ . '/../src/Routes/webhooks.php';
require_once __DIR__ . '/../src/Routes/carrierService.php';
require_once __DIR__ . '/../src/Routes/labels.php';

// Dispatch the request
$router->dispatch();
