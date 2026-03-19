<?php
/**
 * Auth routes - OAuth flow with Shopify
 */

use App\Router;
use App\Config;
use App\Services\TokenStorage;
use App\Services\ShopifyService;
use App\Utils\Logger;

$logger = Logger::create('auth');

$SCOPES = implode(',', [
    'read_orders',
    'write_orders',
    'read_fulfillments',
    'write_fulfillments',
    'read_assigned_fulfillment_orders',
    'write_assigned_fulfillment_orders',
    'write_merchant_managed_fulfillment_orders',
    'read_shipping',
    'write_shipping',
]);

// Store nonces temporarily (in memory per-request, persisted in session for PHP)
// For PHP we use a file-based approach since each request is a new process
$nonceFile = dirname(__DIR__) . '/data/nonces.json';

/**
 * Load nonces from file
 */
function loadNonces(string $file): array
{
    if (file_exists($file)) {
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
    return [];
}

/**
 * Save nonces to file
 */
function saveNonces(string $file, array $nonces): void
{
    $dir = dirname($file);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($file, json_encode($nonces, JSON_PRETTY_PRINT));
}

/**
 * Register webhooks via Shopify Admin API
 */
function registerWebhooks(string $shop, string $accessToken, $logger): array
{
    $webhooks = [
        [
            'topic' => 'orders/paid',
            'address' => Config::get('shopify.appUrl') . '/webhooks/orders_paid',
            'format' => 'json',
        ],
    ];

    $client = new \GuzzleHttp\Client(['timeout' => 30]);
    $results = [];

    foreach ($webhooks as $webhook) {
        try {
            $response = $client->post("https://{$shop}/admin/api/2024-01/webhooks.json", [
                'json' => ['webhook' => $webhook],
                'headers' => [
                    'X-Shopify-Access-Token' => $accessToken,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $logger->info("Webhook registered: {$webhook['topic']} -> {$webhook['address']}");
            $results[] = ['topic' => $webhook['topic'], 'success' => true, 'id' => $data['webhook']['id'] ?? null];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 422) {
                $logger->info("Webhook {$webhook['topic']} already exists, skipping");
                $results[] = ['topic' => $webhook['topic'], 'success' => true, 'alreadyExists' => true];
            } else {
                $logger->error("Failed to register webhook {$webhook['topic']}: " . $e->getMessage());
                $results[] = ['topic' => $webhook['topic'], 'success' => false, 'error' => $e->getMessage()];
            }
        } catch (\Exception $e) {
            $logger->error("Failed to register webhook {$webhook['topic']}: " . $e->getMessage());
            $results[] = ['topic' => $webhook['topic'], 'success' => false, 'error' => $e->getMessage()];
        }
    }

    return $results;
}

/**
 * Verify HMAC for OAuth callbacks
 */
function verifyOAuthHmac(array $query): bool
{
    $hmac = $query['hmac'] ?? '';
    if (empty($hmac)) {
        return false;
    }

    $params = $query;
    unset($params['hmac']);

    // Sort and stringify params
    ksort($params);
    $parts = [];
    foreach ($params as $key => $value) {
        $parts[] = "{$key}={$value}";
    }
    $message = implode('&', $parts);

    $calculatedHmac = hash_hmac('sha256', $message, Config::get('shopify.clientSecret'));

    return hash_equals($calculatedHmac, $hmac);
}

/**
 * GET /auth
 * Initiates OAuth flow
 */
$router->get('/auth', function () use ($SCOPES, $nonceFile, $logger) {
    $shop = Router::query('shop');

    if (empty($shop)) {
        http_response_code(400);
        Router::json([
            'error' => 'Missing shop parameter',
            'usage' => '/auth?shop=yourstore.myshopify.com',
        ]);
        return;
    }

    // Validate shop format
    if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/', $shop)) {
        http_response_code(400);
        Router::json([
            'error' => 'Invalid shop format',
            'expected' => 'yourstore.myshopify.com',
        ]);
        return;
    }

    // Generate nonce
    $nonce = bin2hex(random_bytes(16));

    // Store nonce
    $nonces = loadNonces($nonceFile);
    $nonces[$shop] = ['nonce' => $nonce, 'timestamp' => time()];

    // Clean up old nonces (older than 10 minutes)
    foreach ($nonces as $key => $value) {
        if (time() - ($value['timestamp'] ?? 0) > 600) {
            unset($nonces[$key]);
        }
    }
    saveNonces($nonceFile, $nonces);

    // Build redirect URL
    $redirectUri = Config::get('shopify.appUrl') . '/auth/callback';
    $authUrl = "https://{$shop}/admin/oauth/authorize?" .
        "client_id=" . Config::get('shopify.clientId') . "&" .
        "scope={$SCOPES}&" .
        "redirect_uri=" . urlencode($redirectUri) . "&" .
        "state={$nonce}";

    $logger->info("Initiating OAuth for shop: {$shop}");
    Router::redirect($authUrl);
});

/**
 * GET /auth/callback
 * OAuth callback - exchanges code for access token
 */
$router->get('/auth/callback', function () use ($nonceFile, $logger) {
    $shop = Router::query('shop');
    $code = Router::query('code');
    $state = Router::query('state');

    $logger->info("OAuth callback received for shop: {$shop}");

    // Validate required params
    if (empty($shop) || empty($code) || empty($state)) {
        $logger->error('Missing required OAuth parameters');
        http_response_code(400);
        Router::json(['error' => 'Missing required parameters']);
        return;
    }

    // Verify HMAC
    if (!verifyOAuthHmac($_GET)) {
        $logger->error('Invalid HMAC in OAuth callback');
        http_response_code(401);
        Router::json(['error' => 'Invalid HMAC signature']);
        return;
    }

    // Verify nonce/state
    $nonces = loadNonces($nonceFile);
    $storedNonce = $nonces[$shop] ?? null;
    if (!$storedNonce || $storedNonce['nonce'] !== $state) {
        $logger->error('Invalid state/nonce in OAuth callback');
        http_response_code(401);
        Router::json(['error' => 'Invalid state parameter']);
        return;
    }

    // Remove used nonce
    unset($nonces[$shop]);
    saveNonces($nonceFile, $nonces);

    try {
        // Exchange code for access token
        $client = new \GuzzleHttp\Client(['timeout' => 30]);
        $response = $client->post("https://{$shop}/admin/oauth/access_token", [
            'json' => [
                'client_id' => Config::get('shopify.clientId'),
                'client_secret' => Config::get('shopify.clientSecret'),
                'code' => $code,
            ],
            'headers' => ['Content-Type' => 'application/json'],
        ]);

        $tokenData = json_decode($response->getBody()->getContents(), true);
        $accessToken = $tokenData['access_token'] ?? '';
        $scope = $tokenData['scope'] ?? '';

        if (empty($accessToken)) {
            throw new \RuntimeException('No access token in response');
        }

        $logger->info("Successfully obtained access token for {$shop}");
        $logger->info("Scopes granted: {$scope}");

        // Store the token
        $tokenStorage = TokenStorage::getInstance();
        $tokenStorage->setToken($shop, $accessToken, $scope);

        // Register webhooks automatically
        $logger->info('Registering webhooks...');
        $webhookResults = registerWebhooks($shop, $accessToken, $logger);
        $allWebhooksOk = !empty(array_filter($webhookResults, fn($r) => !$r['success'])) ? false : true;
        $webhookStatus = $allWebhooksOk ? 'Webhooks registrados' : 'Algunos webhooks fallaron';

        // Register CarrierService
        $logger->info('Registering CarrierService...');
        try {
            $shopifyService = ShopifyService::getInstance();
            $shopifyService->registerCarrierService();
            $logger->info('CarrierService registered for dynamic shipping rates');
        } catch (\Exception $carrierError) {
            $logger->error('Failed to register CarrierService: ' . $carrierError->getMessage());
        }

        // Show success page with token
        $escapedToken = htmlspecialchars($accessToken, ENT_QUOTES, 'UTF-8');
        $escapedShop = htmlspecialchars($shop, ENT_QUOTES, 'UTF-8');
        $statusColor = $allWebhooksOk ? '#008060' : '#ff9800';

        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>LAAR Integration - Instalación Exitosa</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
               display: flex; justify-content: center; align-items: center; 
               min-height: 100vh; margin: 0; background: #f6f6f7; }
        .container { text-align: center; padding: 40px; background: white; 
                     border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 600px; }
        h1 { color: #008060; }
        p { color: #637381; }
        .checkmark { font-size: 48px; margin-bottom: 20px; }
        .token-box { background: #1a1a2e; color: #00ff88; padding: 15px; border-radius: 6px; 
                     font-family: monospace; font-size: 12px; word-break: break-all; 
                     margin: 20px 0; text-align: left; }
        .copy-btn { background: #008060; color: white; border: none; padding: 10px 20px; 
                    border-radius: 4px; cursor: pointer; font-size: 14px; margin-top: 10px; }
        .copy-btn:hover { background: #006e52; }
        .instructions { background: #fff8e6; border: 1px solid #ffcc00; padding: 15px; 
                        border-radius: 6px; text-align: left; margin-top: 20px; }
        .instructions ol { margin: 10px 0; padding-left: 20px; }
        .instructions li { margin: 5px 0; color: #333; }
    </style>
</head>
<body>
    <div class="container">
        <div class="checkmark">✅</div>
        <h1>¡Instalación Exitosa!</h1>
        <p>LAAR Courier Integration se ha conectado correctamente a tu tienda.</p>
        <p><strong>{$escapedShop}</strong></p>
        <p style="margin-top: 10px; color: {$statusColor};">{$webhookStatus}</p>
        
        <div class="instructions">
            <strong>⚠️ IMPORTANTE - Guarda este token:</strong>
            <p>Copia este Access Token y agrégalo como variable de entorno para que persista.</p>
        </div>
        
        <div class="token-box" id="token">{$escapedToken}</div>
        <button class="copy-btn" onclick="copyToken()">📋 Copiar Token</button>
        
        <div class="instructions">
            <strong>Pasos:</strong>
            <ol>
                <li>Copia el token de arriba</li>
                <li>Agrega la variable de entorno: <code>SHOPIFY_ACCESS_TOKEN</code></li>
                <li>Pega el token como valor</li>
                <li>Guarda los cambios</li>
            </ol>
        </div>
    </div>
    <script>
        function copyToken() {
            const token = document.getElementById('token').innerText;
            navigator.clipboard.writeText(token).then(() => {
                const btn = document.querySelector('.copy-btn');
                btn.textContent = '✅ ¡Copiado!';
                setTimeout(() => btn.textContent = '📋 Copiar Token', 2000);
            });
        }
    </script>
</body>
</html>
HTML;
    } catch (\Exception $e) {
        $logger->error('Failed to exchange code for token: ' . $e->getMessage());
        http_response_code(500);
        Router::json([
            'error' => 'Failed to complete OAuth',
            'details' => $e->getMessage(),
        ]);
    }
});

/**
 * GET /auth/status
 * Check if a shop is authenticated
 */
$router->get('/auth/status', function () {
    $shop = Router::query('shop') ?: Config::get('shopify.storeDomain');

    if (empty($shop)) {
        http_response_code(400);
        Router::json(['error' => 'Missing shop parameter']);
        return;
    }

    $tokenStorage = TokenStorage::getInstance();
    $hasToken = $tokenStorage->hasToken($shop);
    $tokenData = $tokenStorage->getTokenData($shop);

    Router::json([
        'shop' => $shop,
        'authenticated' => $hasToken,
        'installedAt' => $tokenData['installedAt'] ?? null,
        'scopes' => $tokenData['scope'] ?? null,
    ]);
});

/**
 * POST /auth/uninstall
 * Webhook handler for app uninstallation
 */
$router->post('/auth/uninstall', function () use ($logger) {
    $body = Router::getJsonBody();
    $shop = $body['myshopify_domain'] ?? $body['shop_domain'] ?? null;

    if ($shop) {
        $logger->info("App uninstalled from: {$shop}");
        $tokenStorage = TokenStorage::getInstance();
        $tokenStorage->removeToken($shop);
    }

    Router::json(['ok' => true]);
});
