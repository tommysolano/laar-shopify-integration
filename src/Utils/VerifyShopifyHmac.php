<?php
namespace App\Utils;

use App\Config;

/**
 * Verify Shopify webhook HMAC signature
 */
class VerifyShopifyHmac
{
    /**
     * Verify Shopify webhook HMAC signature
     *
     * @param string $rawBody Raw request body
     * @param string $hmacHeader X-Shopify-Hmac-Sha256 header value
     * @return bool Whether the signature is valid
     */
    public static function verify(string $rawBody, string $hmacHeader): bool
    {
        if (empty($hmacHeader)) {
            return false;
        }

        $secret = Config::get('shopify.webhookSecret') ?: Config::get('shopify.clientSecret');
        if (empty($secret)) {
            throw new \RuntimeException('No webhook secret configured (SHOPIFY_WEBHOOK_SECRET or SHOPIFY_CLIENT_SECRET)');
        }

        $calculatedHmac = base64_encode(
            hash_hmac('sha256', $rawBody, $secret, true)
        );

        return hash_equals($calculatedHmac, $hmacHeader);
    }

    /**
     * Middleware-style verification for webhook routes
     * Returns true if valid, sends 401 and returns false if invalid
     *
     * @return bool
     */
    public static function middleware(): bool
    {
        $hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

        // Allow skipping HMAC in development mode if configured
        if (Config::get('isDevelopment') && Config::get('allowInsecureWebhooks')) {
            error_log('WARNING: Skipping HMAC verification (ALLOW_INSECURE_WEBHOOKS=true)');
            return true;
        }

        $rawBody = file_get_contents('php://input') ?: '';

        if (!self::verify($rawBody, $hmacHeader)) {
            error_log('Invalid HMAC signature');
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Invalid HMAC signature']);
            return false;
        }

        return true;
    }

    /**
     * Generate HMAC for testing purposes
     *
     * @param string $body JSON body string
     * @param string $secret Webhook secret
     * @return string Base64 encoded HMAC
     */
    public static function generateHmac(string $body, string $secret): string
    {
        return base64_encode(
            hash_hmac('sha256', $body, $secret, true)
        );
    }
}
