<?php
namespace App;

/**
 * Application configuration loaded from environment variables
 */
class Config
{
    private static array $config = [];
    private static bool $loaded = false;

    /**
     * Load configuration from environment variables
     */
    private static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$config = [
            'port' => $_ENV['PORT'] ?? 3000,
            'nodeEnv' => $_ENV['NODE_ENV'] ?? 'development',
            'isDevelopment' => ($_ENV['NODE_ENV'] ?? 'development') === 'development',
            'allowInsecureWebhooks' => ($_ENV['ALLOW_INSECURE_WEBHOOKS'] ?? '') === 'true',

            'shopify' => [
                'storeDomain' => $_ENV['SHOPIFY_STORE_DOMAIN'] ?? '',
                'adminToken' => $_ENV['SHOPIFY_ADMIN_TOKEN'] ?? $_ENV['SHOPIFY_ACCESS_TOKEN'] ?? '',
                'webhookSecret' => $_ENV['SHOPIFY_WEBHOOK_SECRET'] ?? '',
                'apiVersion' => '2025-10',
                'clientId' => $_ENV['SHOPIFY_CLIENT_ID'] ?? '',
                'clientSecret' => $_ENV['SHOPIFY_CLIENT_SECRET'] ?? '',
                'appUrl' => $_ENV['APP_URL'] ?? 'https://laar-shopify-integration.onrender.com',
            ],

            'laar' => [
                'baseUrl' => $_ENV['LAAR_BASE_URL'] ?? 'https://api.laarcourier.com:9747',
                'username' => $_ENV['LAAR_USERNAME'] ?? '',
                'password' => $_ENV['LAAR_PASSWORD'] ?? '',
                'tokenExpirationMinutes' => (int)($_ENV['LAAR_TOKEN_EXPIRATION_MINUTES'] ?? 120),
            ],

            'defaults' => [
                'serviceCode' => $_ENV['DEFAULT_SERVICE_CODE'] ?? '',
                'originCityCode' => $_ENV['DEFAULT_ORIGIN_CITY_CODE'] ?? '',
                'origin' => [
                    'identificacionO' => $_ENV['ORIGIN_IDENTIFICACION'] ?? '',
                    'nombreO' => $_ENV['ORIGIN_NOMBRE'] ?? '',
                    'direccionO' => $_ENV['ORIGIN_DIRECCION'] ?? '',
                    'referenciaO' => $_ENV['ORIGIN_REFERENCIA'] ?? '',
                    'ciudadO' => $_ENV['ORIGIN_CIUDAD'] ?? $_ENV['DEFAULT_ORIGIN_CITY_CODE'] ?? '',
                    'telefonoO' => $_ENV['ORIGIN_TELEFONO'] ?? '',
                    'celularO' => $_ENV['ORIGIN_CELULAR'] ?? '',
                    'correoO' => $_ENV['ORIGIN_CORREO'] ?? '',
                ],
            ],
        ];

        self::$loaded = true;
    }

    /**
     * Get a configuration value using dot notation
     * 
     * @param string $key Dot-notation key (e.g., 'shopify.storeDomain')
     * @param mixed $default Default value if key not found
     * @return mixed
     */
    public static function get(string $key, $default = null)
    {
        self::load();

        $keys = explode('.', $key);
        $value = self::$config;

        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }

        return $value;
    }

    /**
     * Validate required configuration
     *
     * @throws \RuntimeException if required variables are missing
     */
    public static function validate(): void
    {
        self::load();

        $required = [
            'SHOPIFY_STORE_DOMAIN' => self::$config['shopify']['storeDomain'],
            'SHOPIFY_CLIENT_ID' => self::$config['shopify']['clientId'],
            'SHOPIFY_CLIENT_SECRET' => self::$config['shopify']['clientSecret'],
            'LAAR_USERNAME' => self::$config['laar']['username'],
            'LAAR_PASSWORD' => self::$config['laar']['password'],
            'DEFAULT_SERVICE_CODE' => self::$config['defaults']['serviceCode'],
            'ORIGIN_IDENTIFICACION' => self::$config['defaults']['origin']['identificacionO'],
            'ORIGIN_NOMBRE' => self::$config['defaults']['origin']['nombreO'],
            'ORIGIN_DIRECCION' => self::$config['defaults']['origin']['direccionO'],
            'ORIGIN_CIUDAD' => self::$config['defaults']['origin']['ciudadO'],
            'ORIGIN_TELEFONO' => self::$config['defaults']['origin']['telefonoO'],
            'ORIGIN_CELULAR' => self::$config['defaults']['origin']['celularO'],
        ];

        $missing = [];
        foreach ($required as $key => $value) {
            if (empty($value)) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException('Missing required environment variables: ' . implode(', ', $missing));
        }
    }
}