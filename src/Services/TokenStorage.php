<?php
namespace App\Services;

use App\Config;
use App\Utils\Logger;

/**
 * Simple token storage for OAuth tokens
 * Stores tokens in a JSON file for persistence
 */
class TokenStorage
{
    private static ?TokenStorage $instance = null;
    private array $tokens = [];
    private string $tokensFile;
    private $logger;

    private function __construct()
    {
        $this->logger = Logger::create('token-storage');
        $this->tokensFile = $_ENV['TOKENS_FILE_PATH'] ?? dirname(__DIR__, 2) . '/data/tokens.json';
        $this->ensureDataDirectory();
        $this->loadTokens();
        $this->loadFromEnv();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Load token from environment variable (fallback)
     */
    private function loadFromEnv(): void
    {
        $envToken = $_ENV['SHOPIFY_ACCESS_TOKEN'] ?? '';
        $shopDomain = Config::get('shopify.storeDomain');

        if (!empty($envToken) && !empty($shopDomain) && !isset($this->tokens[$shopDomain])) {
            $this->tokens[$shopDomain] = [
                'accessToken' => $envToken,
                'scope' => 'from-env',
                'installedAt' => 'from-env',
                'updatedAt' => date('c'),
            ];
            $this->logger->info("Loaded token from SHOPIFY_ACCESS_TOKEN env var for {$shopDomain}");
        }
    }

    /**
     * Ensure the data directory exists
     */
    private function ensureDataDirectory(): void
    {
        $dir = dirname($this->tokensFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $this->logger->info('Created data directory: ' . $dir);
        }
    }

    /**
     * Load tokens from file
     */
    private function loadTokens(): void
    {
        try {
            if (file_exists($this->tokensFile)) {
                $data = file_get_contents($this->tokensFile);
                $decoded = json_decode($data, true);
                $this->tokens = is_array($decoded) ? $decoded : [];
                $this->logger->info('Loaded tokens from storage');
            } else {
                $this->tokens = [];
                $this->logger->info('No existing tokens file, starting fresh');
            }
        } catch (\Exception $e) {
            $this->logger->error('Error loading tokens: ' . $e->getMessage());
            $this->tokens = [];
        }
    }

    /**
     * Save tokens to file
     */
    private function saveTokens(): void
    {
        try {
            file_put_contents(
                $this->tokensFile,
                json_encode($this->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $this->logger->info('Tokens saved to storage');
        } catch (\Exception $e) {
            $this->logger->error('Error saving tokens: ' . $e->getMessage());
        }
    }

    /**
     * Store access token for a shop
     */
    public function setToken(string $shop, string $accessToken, string $scope = ''): void
    {
        $this->tokens[$shop] = [
            'accessToken' => $accessToken,
            'scope' => $scope,
            'installedAt' => date('c'),
            'updatedAt' => date('c'),
        ];
        $this->saveTokens();
        $this->logger->info("Token stored for shop: {$shop}");
    }

    /**
     * Get access token for a shop
     */
    public function getToken(string $shop): ?string
    {
        $tokenData = $this->tokens[$shop] ?? null;
        if ($tokenData && !empty($tokenData['accessToken'])) {
            return $tokenData['accessToken'];
        }
        return null;
    }

    /**
     * Get full token data for a shop
     */
    public function getTokenData(string $shop): ?array
    {
        return $this->tokens[$shop] ?? null;
    }

    /**
     * Check if a shop has a valid token
     */
    public function hasToken(string $shop): bool
    {
        return $this->getToken($shop) !== null;
    }

    /**
     * Remove token for a shop (uninstall)
     */
    public function removeToken(string $shop): void
    {
        if (isset($this->tokens[$shop])) {
            unset($this->tokens[$shop]);
            $this->saveTokens();
            $this->logger->info("Token removed for shop: {$shop}");
        }
    }

    /**
     * Get all shops with tokens
     */
    public function getAllShops(): array
    {
        return array_keys($this->tokens);
    }
}
