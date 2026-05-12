<?php
namespace App\Utils;

use App\Config;

/**
 * Verify Shopify webhook HMAC signature
 */
class VerifyShopifyHmac
{
    private static ?string $rawBodyCache = null;

    /**
     * Get raw request body only once and reuse it.
     */
    public static function getRawBody(): string
    {
        if (self::$rawBodyCache === null) {
            self::$rawBodyCache = file_get_contents('php://input') ?: '';
        }

        return self::$rawBodyCache;
    }

    /**
     * Verify Shopify webhook HMAC signature.
     * 
     * Tries both:
     * - SHOPIFY_CLIENT_SECRET
     * - SHOPIFY_WEBHOOK_SECRET
     * 
     * This avoids failures if the webhook was registered differently.
     */
    public static function verify(string $rawBody, string $hmacHeader): bool
    {
        if (empty($hmacHeader)) {
            return false;
        }

        $secrets = [];

        $clientSecret = Config::get('shopify.clientSecret');
        $webhookSecret = Config::get('shopify.webhookSecret');

        if (!empty($clientSecret)) {
            $secrets[] = $clientSecret;
        }

        if (!empty($webhookSecret) && $webhookSecret !== $clientSecret) {
            $secrets[] = $webhookSecret;
        }

        if (empty($secrets)) {
            throw new \RuntimeException('No Shopify secret configured. Check SHOPIFY_CLIENT_SECRET or SHOPIFY_WEBHOOK_SECRET.');
        }

        foreach ($secrets as $secret) {
            $calculatedHmac = base64_encode(
                hash_hmac('sha256', $rawBody, $secret, true)
            );

            if (hash_equals($calculatedHmac, $hmacHeader)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Middleware-style verification for webhook routes.
     */
    public static function middleware(): bool
    {
        $hmacHeader = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';

        if (Config::get('isDevelopment') && Config::get('allowInsecureWebhooks')) {
            error_log('WARNING: Skipping HMAC verification because ALLOW_INSECURE_WEBHOOKS=true');
            return true;
        }

        $rawBody = self::getRawBody();

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
     * Generate HMAC for testing purposes.
     */
    public static function generateHmac(string $body, string $secret): string
    {
        return base64_encode(
            hash_hmac('sha256', $body, $secret, true)
        );
    }
}