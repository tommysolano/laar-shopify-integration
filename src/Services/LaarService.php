<?php
namespace App\Services;

use GuzzleHttp\Client;
use App\Config;
use App\Utils\Logger;

/**
 * LAAR Courier Service
 * Handles authentication, city lookup, and guide creation
 */
class LaarService
{
    private static ?LaarService $instance = null;
    private string $baseUrl;
    private ?string $token = null;
    private ?int $tokenExpiry = null;
    private ?array $citiesCache = null;
    private ?int $citiesCacheExpiry = null;
    private Client $client;
    private $logger;
    private string $tokenCacheFile;

    private function __construct()
    {
        $this->logger = Logger::create('laar-service');
        $this->baseUrl = Config::get('laar.baseUrl');
        $this->tokenCacheFile = dirname(__DIR__, 2) . '/data/laar_token.json';
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
            'verify' => false, // cPanel environments may not have updated CA bundles
        ]);
        $this->loadTokenFromCache();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the Guzzle client (used by labels route for direct requests)
     */
    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Check if current token is still valid
     */
    public function isTokenValid(): bool
    {
        if (empty($this->token) || empty($this->tokenExpiry)) {
            return false;
        }
        // Token is valid if we have more than 1 minute before expiry
        return time() < ($this->tokenExpiry - 60);
    }

    /**
     * Authenticate with LAAR API and get token
     */
    public function authenticate(): string
    {
        $this->logger->info('Authenticating with LAAR API...');

        try {
            $response = $this->client->post('/api/Login/authenticate', [
                'json' => [
                    'username' => Config::get('laar.username'),
                    'password' => Config::get('laar.password'),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $token = $data['token'] ?? null;

            if (empty($token) || !is_string($token)) {
                throw new \RuntimeException('Invalid token received from LAAR API');
            }

            $this->token = $token;
            $this->tokenExpiry = time() + (Config::get('laar.tokenExpirationMinutes') * 60);
            $this->saveTokenToCache();

            $this->logger->info('Successfully authenticated with LAAR API');
            return $this->token;
        } catch (\Exception $e) {
            $this->logger->error('LAAR authentication failed: ' . $e->getMessage());
            throw new \RuntimeException('LAAR authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Get valid token, authenticating if necessary
     */
    public function getToken(): string
    {
        if (!$this->isTokenValid()) {
            $this->authenticate();
        }
        return $this->token;
    }

    /**
     * Clear stored token (used for retry on 401)
     */
    public function clearToken(): void
    {
        $this->token = null;
        $this->tokenExpiry = null;
        @unlink($this->tokenCacheFile);
    }

    /**
     * Load cached LAAR token from file
     */
    private function loadTokenFromCache(): void
    {
        if (!file_exists($this->tokenCacheFile)) {
            return;
        }
        $data = json_decode(file_get_contents($this->tokenCacheFile), true);
        if (!is_array($data) || empty($data['token']) || empty($data['expiry'])) {
            return;
        }
        if (time() < ($data['expiry'] - 60)) {
            $this->token = $data['token'];
            $this->tokenExpiry = $data['expiry'];
            $this->logger->info('LAAR token loaded from cache (expires in ' . round(($data['expiry'] - time()) / 60) . ' min)');
        }
    }

    /**
     * Save LAAR token to cache file
     */
    private function saveTokenToCache(): void
    {
        $dir = dirname($this->tokenCacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->tokenCacheFile, json_encode([
            'token' => $this->token,
            'expiry' => $this->tokenExpiry,
        ]));
    }

    /**
     * Get list of cities from LAAR API (cached in memory)
     */
    public function getCities(): array
    {
        if ($this->citiesCache !== null && $this->citiesCacheExpiry > time()) {
            return $this->citiesCache;
        }

        $token = $this->getToken();

        try {
            $this->logger->info('Fetching cities from LAAR API...');
            $response = $this->client->get('/api/Ciudades/v1/ciudades', [
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->citiesCache = $data;
            $this->citiesCacheExpiry = time() + 3600; // Cache for 1 hour

            $count = is_array($data) ? count($data) : 'unknown';
            $this->logger->info("Loaded {$count} cities from LAAR");
            return $this->citiesCache;
        } catch (\Exception $e) {
            $this->logger->error('Failed to fetch LAAR cities: ' . $e->getMessage());
            throw new \RuntimeException('Failed to fetch LAAR cities: ' . $e->getMessage());
        }
    }

    /**
     * Find city code by name
     *
     * @param string $cityName City name from Shopify
     * @param string $provinceName Province name (optional)
     * @return string LAAR city code
     */
    public function findCityCode(string $cityName, string $provinceName = ''): string
    {
        $cities = $this->getCities();

        if (!is_array($cities)) {
            $this->logger->error('Cities response is not an array');
            throw new \RuntimeException('Invalid cities data from LAAR API');
        }

        $normalizedCity = mb_strtolower(trim($cityName));
        $normalizedProvince = mb_strtolower(trim($provinceName));

        // Try exact match first
        $match = null;
        foreach ($cities as $c) {
            if (mb_strtolower($c['nombre'] ?? '') === $normalizedCity) {
                $match = $c;
                break;
            }
        }

        // Try partial match
        if (!$match) {
            foreach ($cities as $c) {
                $laarCity = mb_strtolower($c['nombre'] ?? '');
                if (str_contains($laarCity, $normalizedCity) || str_contains($normalizedCity, $laarCity)) {
                    $match = $c;
                    break;
                }
            }
        }

        // Try with province
        if (!$match && !empty($normalizedProvince)) {
            foreach ($cities as $c) {
                $laarCity = mb_strtolower($c['nombre'] ?? '');
                $laarProv = mb_strtolower($c['provincia'] ?? '');
                if (str_contains($laarCity, $normalizedCity) && str_contains($laarProv, $normalizedProvince)) {
                    $match = $c;
                    break;
                }
            }
        }

        if (!$match) {
            $this->logger->error("City not found in LAAR: {$cityName}, Province: {$provinceName}");
            throw new \RuntimeException("City \"{$cityName}\" not found in LAAR system. Please verify the city name.");
        }

        $cityCode = $match['codigo'];
        $this->logger->info("Found LAAR city: {$cityName} -> Code: {$cityCode}");
        return (string)$cityCode;
    }

    /**
     * Create shipping guide (guia) in LAAR
     *
     * @param array $guideData Guide creation payload
     * @param bool $isRetry Whether this is a retry after 401
     * @return array Created guide with number and PDF URL
     */
    public function createGuide(array $guideData, bool $isRetry = false): array
    {
        $token = $this->getToken();

        try {
            $orderId = $guideData['extras']['campo1'] ?? 'unknown';
            $this->logger->info('Creating LAAR guide...', ['orderId' => $orderId]);

            $response = $this->client->post('/api/Guias/v1/guias/contado?isRetorno=false', [
                'json' => $guideData,
                'headers' => ['Authorization' => "Bearer {$token}"],
            ]);

            $result = json_decode($response->getBody()->getContents(), true);
            $guideNumber = $result['guia'] ?? null;
            $pdfUrl = $result['url'] ?? null;

            if (empty($guideNumber)) {
                $this->logger->error('LAAR response missing guide number', $result);
                throw new \RuntimeException('LAAR response missing guide number');
            }

            $this->logger->info('LAAR guide created successfully', [
                'guideNumber' => $guideNumber,
                'orderId' => $orderId,
            ]);

            return [
                'guia' => $guideNumber,
                'pdfUrl' => $pdfUrl,
                'rawResponse' => $result,
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Handle 401 - token expired, retry once
            if ($e->getResponse()->getStatusCode() === 401 && !$isRetry) {
                $this->logger->warning('LAAR token expired, re-authenticating and retrying...');
                $this->clearToken();
                return $this->createGuide($guideData, true);
            }

            $responseBody = $e->getResponse()->getBody()->getContents();
            $this->logger->error('LAAR guide creation failed', [
                'status' => $e->getResponse()->getStatusCode(),
                'data' => $responseBody,
            ]);

            $errorData = json_decode($responseBody, true);
            $errorMessage = $errorData['message'] ?? $responseBody;
            throw new \RuntimeException("LAAR guide creation failed: {$errorMessage}");
        } catch (\Exception $e) {
            $this->logger->error('LAAR guide creation failed: ' . $e->getMessage());
            throw new \RuntimeException('LAAR guide creation failed: ' . $e->getMessage());
        }
    }

    /**
     * Build guide payload from Shopify order
     *
     * @param array $order Shopify order object
     * @return array LAAR guide payload
     */
    public function buildGuidePayload(array $order): array
    {
        $shipping = $order['shipping_address'] ?? $order['shippingAddress'] ?? [];
        $billing = $order['billing_address'] ?? [];
        $customer = $order['customer'] ?? [];
        $lineItems = $order['line_items'] ?? $order['lineItems'] ?? [];
        $noteAttributes = $order['note_attributes'] ?? [];

        // Build SKU summary
        $skuParts = [];
        foreach ($lineItems as $item) {
            $sku = $item['sku'] ?? $item['title'] ?? 'N/A';
            $qty = $item['quantity'] ?? 1;
            $skuParts[] = "{$sku} x{$qty}";
        }
        $skuSummary = mb_substr(implode(', ', $skuParts), 0, 200);

        // Validate required shipping data
        if (empty($shipping['city'])) {
            throw new \RuntimeException('Missing shipping city in order');
        }
        if (empty($shipping['address1'])) {
            throw new \RuntimeException('Missing shipping address in order');
        }

        // Get customer name
        $customerName = $shipping['name'] ?? trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? ''));
        if (empty($customerName)) {
            throw new \RuntimeException('Missing customer name in order');
        }

        // Get phone
        $rawPhone = $shipping['phone'] ?? $customer['phone'] ?? '';
        $phone = substr(preg_replace('/[^0-9]/', '', $rawPhone), 0, 10);
        if (empty($phone) || strlen($phone) < 7) {
            throw new \RuntimeException("Missing or invalid phone number in order. Raw phone: \"{$rawPhone}\"");
        }

        // Get customer identification (cédula/RUC) - OPTIONAL
        $identificacion = '';

        // 1. Check note_attributes
        foreach ($noteAttributes as $attr) {
            $name = mb_strtolower($attr['name'] ?? '');
            if (str_contains($name, 'cedula') || str_contains($name, 'cédula') ||
                str_contains($name, 'identificacion') || str_contains($name, 'ruc') ||
                str_contains($name, 'ci')) {
                if (!empty($attr['value'])) {
                    $identificacion = preg_replace('/[^0-9]/', '', $attr['value']);
                    break;
                }
            }
        }

        // 2. Check order notes
        if (empty($identificacion) && !empty($order['note'])) {
            if (preg_match('/(?:cedula|cédula|ruc|ci)[:\s]*([0-9]{10,13})/i', $order['note'], $m)) {
                $identificacion = $m[1];
            } else if (preg_match('/\b([0-9]{10,13})\b/', $order['note'], $m)) {
                $identificacion = $m[1];
            }
        }

        if (empty($identificacion)) {
            $this->logger->warning('No se encontró cédula/RUC en las notas del pedido. Continuando sin identificación.');
        }

        // Build full address
        $address1 = $shipping['address1'];
        $address2 = $shipping['address2'] ?? '';
        $fullAddress = trim("{$address1} {$address2}");

        // Get reference
        $reference = $shipping['company'] ?? $shipping['address2'] ?? '';

        // Calculate total pieces
        $totalPieces = 0;
        foreach ($lineItems as $item) {
            $totalPieces += ($item['quantity'] ?? 1);
        }

        // Calculate total weight in kg
        $totalWeightGrams = $order['total_weight'] ?? 0;
        if (empty($totalWeightGrams)) {
            foreach ($lineItems as $item) {
                $totalWeightGrams += (($item['grams'] ?? 0) * ($item['quantity'] ?? 1));
            }
        }
        $totalWeightKg = max(1, (int)ceil($totalWeightGrams / 1000));

        // Build contents description
        $contentParts = [];
        foreach ($lineItems as $item) {
            $contentParts[] = $item['title'] ?? $item['name'] ?? 'Producto';
        }
        $contenido = mb_substr(implode(', ', $contentParts), 0, 200);

        // Get city code from LAAR API
        $cityName = $shipping['city'];
        $provinceName = $shipping['province'] ?? '';
        $this->logger->info("Looking up LAAR city code for: {$cityName}, Province: {$provinceName}");

        $cityCode = $this->findCityCode($cityName, $provinceName);

        $this->logger->info('Building guide with customer data', [
            'customerName' => $customerName,
            'identificacion' => $identificacion ?: 'N/A',
            'phone' => $phone,
            'city' => $cityName,
            'cityCode' => $cityCode,
            'address' => $fullAddress,
            'pieces' => $totalPieces,
            'weightKg' => $totalWeightKg,
        ]);

        $orderName = $order['name'] ?? '#' . ($order['order_number'] ?? $order['id'] ?? '');

        return [
            // Origin data (from config)
            'origen' => [
                'identificacionO' => Config::get('defaults.origin.identificacionO'),
                'ciudadO' => Config::get('defaults.origin.ciudadO'),
                'nombreO' => Config::get('defaults.origin.nombreO'),
                'direccion' => Config::get('defaults.origin.direccionO'),
                'referencia' => Config::get('defaults.origin.referenciaO'),
                'numeroCasa' => '',
                'postal' => '',
                'telefono' => Config::get('defaults.origin.telefonoO'),
                'celular' => Config::get('defaults.origin.celularO'),
                'correo' => Config::get('defaults.origin.correoO'),
            ],

            // Destination data (from customer order)
            'destino' => [
                'identificacionD' => $identificacion,
                'ciudadD' => $cityCode,
                'nombreD' => $customerName,
                'direccion' => $fullAddress,
                'referencia' => mb_substr($reference, 0, 225),
                'numeroCasa' => '',
                'postal' => $shipping['zip'] ?? '',
                'telefono' => $phone,
                'celular' => $phone,
                'categoria' => '',
                'latitud' => !empty($shipping['latitude']) ? (string)$shipping['latitude'] : '',
                'longitud' => !empty($shipping['longitude']) ? (string)$shipping['longitude'] : '',
            ],

            // Guide details
            'numeroGuia' => '',
            'tipoServicio' => Config::get('defaults.serviceCode'),
            'noPiezas' => $totalPieces,
            'peso' => $totalWeightKg,
            'valorDeclarado' => 0,
            'contiene' => $contenido ?: 'Pedido Shopify',
            'tamanio' => '',
            'cod' => false,
            'costoflete' => 0,
            'costoproducto' => 0,
            'tipocobro' => 0,
            'comentario' => "Shopify Order #{$orderName}",
            'fechaPedido' => '',

            // Retorno (no aplica)
            'retorno' => [
                'tipoServicio' => '',
                'noPiezas' => 0,
                'peso' => 0,
                'contiene' => '',
                'comentario' => '',
                'tamanio' => '',
            ],

            // Extra fields for tracking
            'extras' => [
                'campo1' => (string)($order['id'] ?? ''),
                'campo2' => $orderName,
                'campo3' => $skuSummary,
            ],
        ];
    }
}
