<?php
namespace App;

/**
 * Simple router for handling HTTP requests
 * Replaces Express.js routing functionality
 */
class Router
{
    private static ?Router $instance = null;
    private array $routes = [];
    private bool $matched = false;

    public function __construct()
    {
        self::$instance = $this;
    }

    public static function getInstance(): self
    {
        return self::$instance;
    }

    /**
     * Register a GET route
     */
    public function get(string $path, callable $handler): void
    {
        $this->routes[] = ['GET', $path, $handler];
    }

    /**
     * Register a POST route
     */
    public function post(string $path, callable $handler): void
    {
        $this->routes[] = ['POST', $path, $handler];
    }

    /**
     * Dispatch the current request to the matching route
     */
    public function dispatch(): void
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $uri = $this->getRequestUri();

        foreach ($this->routes as [$routeMethod, $routePath, $handler]) {
            if ($method !== $routeMethod) {
                continue;
            }

            $params = $this->matchRoute($routePath, $uri);
            if ($params !== false) {
                $this->matched = true;
                $handler($params);
                return;
            }
        }

        // 404 Not Found
        http_response_code(404);
        self::json(['error' => 'Not found']);
    }

    /**
     * Match a route pattern against a URI
     * Supports :param style parameters
     *
     * @return array|false Parameters array or false if no match
     */
    private function matchRoute(string $pattern, string $uri)
    {
        // Exact match
        if ($pattern === $uri) {
            return [];
        }

        // Convert :param to regex groups
        $regex = preg_replace('#:([a-zA-Z_]+)#', '(?P<\1>[A-Za-z0-9_-]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (preg_match($regex, $uri, $matches)) {
            // Filter only named groups
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return false;
    }

    /**
     * Get the clean request URI (without query string)
     */
    private function getRequestUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // Remove query string
        $pos = strpos($uri, '?');
        if ($pos !== false) {
            $uri = substr($uri, 0, $pos);
        }
        // Remove trailing slash (except root)
        if ($uri !== '/' && str_ends_with($uri, '/')) {
            $uri = rtrim($uri, '/');
        }
        return $uri;
    }

    /**
     * Send a JSON response
     */
    public static function json($data, int $statusCode = 0): void
    {
        if ($statusCode > 0) {
            http_response_code($statusCode);
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Get raw request body
     */
    public static function getRawBody(): string
    {
        return file_get_contents('php://input') ?: '';
    }

    /**
     * Get parsed JSON body
     */
    public static function getJsonBody(): ?array
    {
        $raw = self::getRawBody();
        if (empty($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Get query parameter
     */
    public static function query(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }

    /**
     * Redirect to a URL
     */
    public static function redirect(string $url): void
    {
        header("Location: {$url}");
        exit;
    }
}
