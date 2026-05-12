<?php
namespace App\Services;

use GuzzleHttp\Client;
use App\Config;
use App\Utils\Logger;

/**
 * Shopify Admin API Service
 * Uses GraphQL for metafields and fulfillment operations
 */
class ShopifyService
{
    private static ?ShopifyService $instance = null;
    private string $storeDomain;
    private string $apiVersion;
    private string $graphqlUrl;
    private string $restBaseUrl;
    private Client $client;
    private $logger;

    private function __construct()
    {
        $this->logger = Logger::create('shopify-service');
        $this->storeDomain = Config::get('shopify.storeDomain');
        $this->apiVersion = Config::get('shopify.apiVersion');
        $this->graphqlUrl = "https://{$this->storeDomain}/admin/api/{$this->apiVersion}/graphql.json";
        $this->restBaseUrl = "https://{$this->storeDomain}/admin/api/{$this->apiVersion}";
        $this->client = new Client([
            'timeout' => 30,
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Get the access token for the configured store
     */
    public function getAccessToken(): string
    {
        $tokenStorage = TokenStorage::getInstance();

        // Try OAuth token first
        $oauthToken = $tokenStorage->getToken($this->storeDomain);
        if ($oauthToken) {
            return $oauthToken;
        }

        // Fallback to env var token
        $adminToken = Config::get('shopify.adminToken');
        if (!empty($adminToken)) {
            return $adminToken;
        }

        throw new \RuntimeException(
            "No access token available for {$this->storeDomain}. " .
            "Please authenticate at /auth?shop={$this->storeDomain}"
        );
    }

    /**
     * Check if the store has a valid token
     */
    public function hasValidToken(): bool
    {
        $tokenStorage = TokenStorage::getInstance();
        return $tokenStorage->hasToken($this->storeDomain) || !empty(Config::get('shopify.adminToken'));
    }

    /**
     * Execute GraphQL query
     */
    public function graphql(string $query, array $variables = []): array
    {
        $token = $this->getAccessToken();

        try {
            $response = $this->client->post($this->graphqlUrl, [
                'json' => [
                    'query' => $query,
                    'variables' => $variables,
                ],
                'headers' => [
                    'X-Shopify-Access-Token' => $token,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (!empty($data['errors'])) {
                $this->logger->error('GraphQL errors', $data['errors']);
                throw new \RuntimeException('GraphQL error: ' . ($data['errors'][0]['message'] ?? 'Unknown error'));
            }

            return $data['data'] ?? [];
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            $this->logger->error('GraphQL request failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Convert numeric order ID to GraphQL GID
     */
    public function toOrderGid($orderId): string
    {
        if (str_starts_with((string)$orderId, 'gid://')) {
            return (string)$orderId;
        }
        return "gid://shopify/Order/{$orderId}";
    }

    /**
     * Check if order already has LAAR guide metafield
     */
    public function getOrderLaarMetafields($orderId): ?array
    {
        $gid = $this->toOrderGid($orderId);

        $query = <<<'GRAPHQL'
        query getOrderMetafields($id: ID!) {
            order(id: $id) {
                id
                name
                metafield(namespace: "laar", key: "guia") {
                    id
                    value
                }
                metafieldPdfUrl: metafield(namespace: "laar", key: "pdf_url") {
                    id
                    value
                }
                metafieldLabelUrl: metafield(namespace: "laar", key: "label_url") {
                    id
                    value
                }
                metafieldCostoEnvio: metafield(namespace: "laar", key: "costo_envio") {
                    id
                    value
                }
            }
        }
        GRAPHQL;

        try {
            $data = $this->graphql($query, ['id' => $gid]);
            $order = $data['order'] ?? null;

            if (!$order) {
                $this->logger->warning('Order not found: ' . $orderId);
                return null;
            }

            if (!empty($order['metafield']['value'])) {
                return [
                    'guia' => $order['metafield']['value'],
                    'pdfUrl' => $order['metafieldPdfUrl']['value'] ?? null,
                    'labelUrl' => $order['metafieldLabelUrl']['value'] ?? null,
                    'costoEnvio' => $order['metafieldCostoEnvio']['value'] ?? null,
                    'exists' => true,
                ];
            }

            return ['exists' => false];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get order metafields: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Save LAAR guide data to order metafields
     */
    public function saveOrderMetafields($orderId, string $guia, ?string $pdfUrl, ?string $labelUrl, ?float $shippingCost = null): array
    {
        $gid = $this->toOrderGid($orderId);

        $mutation = <<<'GRAPHQL'
        mutation setOrderMetafields($input: [MetafieldsSetInput!]!) {
            metafieldsSet(metafields: $input) {
                metafields {
                    id
                    namespace
                    key
                    value
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $metafields = [
            [
                'ownerId' => $gid,
                'namespace' => 'laar',
                'key' => 'guia',
                'type' => 'single_line_text_field',
                'value' => (string)$guia,
            ],
        ];

        if (!empty($pdfUrl)) {
            $metafields[] = [
                'ownerId' => $gid,
                'namespace' => 'laar',
                'key' => 'pdf_url',
                'type' => 'url',
                'value' => $pdfUrl,
            ];
        }

        if (!empty($labelUrl)) {
            $metafields[] = [
                'ownerId' => $gid,
                'namespace' => 'laar',
                'key' => 'label_url',
                'type' => 'url',
                'value' => $labelUrl,
            ];
        }

        if ($shippingCost !== null) {
            $metafields[] = [
                'ownerId' => $gid,
                'namespace' => 'laar',
                'key' => 'costo_envio',
                'type' => 'number_decimal',
                'value' => (string)$shippingCost,
            ];
        }

        try {
            $data = $this->graphql($mutation, ['input' => $metafields]);

            if (!empty($data['metafieldsSet']['userErrors'])) {
                $errors = $data['metafieldsSet']['userErrors'];
                $this->logger->error('Metafield save errors', $errors);
                throw new \RuntimeException('Failed to save metafields: ' . ($errors[0]['message'] ?? 'Unknown'));
            }

            $this->logger->info('Order metafields saved successfully', ['orderId' => $orderId, 'guia' => $guia]);
            return $data['metafieldsSet']['metafields'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to save order metafields: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Get fulfillment orders for an order
     */
    public function getFulfillmentOrders($orderId): array
    {
        $gid = $this->toOrderGid($orderId);

        $query = <<<'GRAPHQL'
        query getFulfillmentOrders($id: ID!) {
            order(id: $id) {
                id
                fulfillmentOrders(first: 50) {
                    nodes {
                        id
                        status
                        lineItems(first: 50) {
                            nodes {
                                id
                                remainingQuantity
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        try {
            $data = $this->graphql($query, ['id' => $gid]);
            return $data['order']['fulfillmentOrders']['nodes'] ?? [];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get fulfillment orders: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create fulfillment with tracking info
     */
    public function createFulfillment($orderId, string $trackingNumber, ?string $trackingUrl): ?array
    {
        $this->logger->info('Creating fulfillment...', ['orderId' => $orderId, 'trackingNumber' => $trackingNumber]);

        $fulfillmentOrders = $this->getFulfillmentOrders($orderId);

        // Filter to only open/in-progress fulfillment orders
        $openFulfillmentOrders = array_filter($fulfillmentOrders, function ($fo) {
            $isOpen = in_array($fo['status'], ['OPEN', 'IN_PROGRESS']);
            $hasRemaining = false;
            foreach ($fo['lineItems']['nodes'] as $li) {
                if (($li['remainingQuantity'] ?? 0) > 0) {
                    $hasRemaining = true;
                    break;
                }
            }
            return $isOpen && $hasRemaining;
        });

        if (empty($openFulfillmentOrders)) {
            $this->logger->warning('No open fulfillment orders found', ['orderId' => $orderId]);
            return null;
        }

        // Build line items by fulfillment order
        $lineItemsByFulfillmentOrder = [];
        foreach ($openFulfillmentOrders as $fo) {
            $items = [];
            foreach ($fo['lineItems']['nodes'] as $li) {
                if (($li['remainingQuantity'] ?? 0) > 0) {
                    $items[] = [
                        'id' => $li['id'],
                        'quantity' => $li['remainingQuantity'],
                    ];
                }
            }
            $lineItemsByFulfillmentOrder[] = [
                'fulfillmentOrderId' => $fo['id'],
                'fulfillmentOrderLineItems' => $items,
            ];
        }

        $mutation = <<<'GRAPHQL'
        mutation fulfillmentCreate($fulfillment: FulfillmentInput!) {
            fulfillmentCreate(fulfillment: $fulfillment) {
                fulfillment {
                    id
                    status
                    trackingInfo {
                        number
                        url
                        company
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $fulfillmentInput = [
            'lineItemsByFulfillmentOrder' => $lineItemsByFulfillmentOrder,
            'notifyCustomer' => true,
            'trackingInfo' => [
                'number' => $trackingNumber,
                'company' => 'LAAR Courier',
            ],
        ];

        if (!empty($trackingUrl)) {
            $fulfillmentInput['trackingInfo']['url'] = $trackingUrl;
        }

        try {
            $data = $this->graphql($mutation, ['fulfillment' => $fulfillmentInput]);

            if (!empty($data['fulfillmentCreate']['userErrors'])) {
                $errors = $data['fulfillmentCreate']['userErrors'];
                $this->logger->error('Fulfillment creation errors', $errors);

                // Check if it's because items are already fulfilled
                foreach ($errors as $e) {
                    $msg = mb_strtolower($e['message'] ?? '');
                    if (str_contains($msg, 'already fulfilled') || str_contains($msg, 'no fulfillable')) {
                        $this->logger->warning('Items already fulfilled or no fulfillable items', ['orderId' => $orderId]);
                        return null;
                    }
                }

                throw new \RuntimeException('Failed to create fulfillment: ' . ($errors[0]['message'] ?? 'Unknown'));
            }

            $fulfillment = $data['fulfillmentCreate']['fulfillment'] ?? null;
            $this->logger->info('Fulfillment created successfully', [
                'orderId' => $orderId,
                'fulfillmentId' => $fulfillment['id'] ?? null,
            ]);

            return $fulfillment;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create fulfillment: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Add tags to an order
     */
    public function addOrderTags($orderId, array $tags): ?array
    {
        $gid = $this->toOrderGid($orderId);

        $mutation = <<<'GRAPHQL'
        mutation addTags($id: ID!, $tags: [String!]!) {
            tagsAdd(id: $id, tags: $tags) {
                node {
                    ... on Order {
                        id
                        tags
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        try {
            $data = $this->graphql($mutation, ['id' => $gid, 'tags' => $tags]);

            if (!empty($data['tagsAdd']['userErrors'])) {
                $this->logger->warning('Tag add errors', $data['tagsAdd']['userErrors']);
            }

            return $data['tagsAdd']['node'] ?? null;
        } catch (\Exception $e) {
            $this->logger->error('Failed to add order tags: ' . $e->getMessage());
            // Don't throw - tags are not critical
            return null;
        }
    }

    /**
     * Create metafield definitions so they are visible in Shopify admin
     */
    public function createLabelMetafieldDefinitions(): array
    {
        $definitions = [
            [
                'name' => 'Etiqueta LAAR',
                'namespace' => 'laar',
                'key' => 'label_url',
                'type' => 'url',
                'ownerType' => 'ORDER',
                'pin' => true,
            ],
            [
                'name' => 'Guía LAAR',
                'namespace' => 'laar',
                'key' => 'guia',
                'type' => 'single_line_text_field',
                'ownerType' => 'ORDER',
                'pin' => true,
            ],
            [
                'name' => 'Costo Envío LAAR',
                'namespace' => 'laar',
                'key' => 'costo_envio',
                'type' => 'number_decimal',
                'ownerType' => 'ORDER',
                'pin' => true,
            ],
        ];

        $mutation = <<<'GRAPHQL'
        mutation createMetafieldDefinition($definition: MetafieldDefinitionInput!) {
            metafieldDefinitionCreate(definition: $definition) {
                createdDefinition {
                    id
                    name
                    namespace
                    key
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;

        $results = [];
        foreach ($definitions as $def) {
            try {
                $data = $this->graphql($mutation, [
                    'definition' => [
                        'name' => $def['name'],
                        'namespace' => $def['namespace'],
                        'key' => $def['key'],
                        'type' => $def['type'],
                        'ownerType' => $def['ownerType'],
                        'pin' => $def['pin'],
                    ],
                ]);

                $errors = $data['metafieldDefinitionCreate']['userErrors'] ?? [];
                if (!empty($errors)) {
                    $this->logger->warning("Metafield definition {$def['key']}: " . ($errors[0]['message'] ?? ''));
                    $results[] = ['key' => $def['key'], 'status' => 'exists', 'message' => $errors[0]['message'] ?? ''];
                } else {
                    $this->logger->info("Metafield definition created: {$def['key']}");
                    $created = $data['metafieldDefinitionCreate']['createdDefinition'] ?? [];
                    $results[] = ['key' => $def['key'], 'status' => 'created', 'id' => $created['id'] ?? null];
                }
            } catch (\Exception $e) {
                $this->logger->error("Failed to create metafield definition {$def['key']}: " . $e->getMessage());
                $results[] = ['key' => $def['key'], 'status' => 'error', 'message' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * Register a CarrierService with Shopify for dynamic shipping rates
     */
    public function registerCarrierService(): array
    {
        $token = $this->getAccessToken();
        $callbackUrl = Config::get('shopify.appUrl') . '/carrier-service/rates';

        // First check if carrier service already exists
        try {
            $response = $this->client->get("{$this->restBaseUrl}/carrier_services.json", [
                'headers' => ['X-Shopify-Access-Token' => $token],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $existing = null;
            foreach ($data['carrier_services'] ?? [] as $cs) {
                if ($cs['callback_url'] === $callbackUrl) {
                    $existing = $cs;
                    break;
                }
            }

            if ($existing) {
                $this->logger->info('CarrierService already registered', ['id' => $existing['id']]);
                return $existing;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not list carrier services: ' . $e->getMessage());
        }

        // Register new carrier service
        $response = $this->client->post("{$this->restBaseUrl}/carrier_services.json", [
            'json' => [
                'carrier_service' => [
                    'name' => 'LAAR Courier',
                    'callback_url' => $callbackUrl,
                    'service_discovery' => true,
                    'format' => 'json',
                ],
            ],
            'headers' => ['X-Shopify-Access-Token' => $token],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $this->logger->info('CarrierService registered', [
            'id' => $data['carrier_service']['id'] ?? null,
            'callbackUrl' => $callbackUrl,
        ]);

        return $data['carrier_service'];
    }
}