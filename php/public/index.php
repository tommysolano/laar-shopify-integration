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

// Include route files
require_once __DIR__ . '/../src/Routes/auth.php';
require_once __DIR__ . '/../src/Routes/webhooks.php';
require_once __DIR__ . '/../src/Routes/carrierService.php';
require_once __DIR__ . '/../src/Routes/labels.php';

// Dispatch the request
$router->dispatch();
