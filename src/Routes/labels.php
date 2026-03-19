<?php
/**
 * Labels routes - Proxy endpoint for LAAR label PDFs
 */

use App\Router;
use App\Services\LaarService;
use App\Utils\Logger;

$logger = Logger::create('labels');

/**
 * GET /labels/:guia
 * 
 * Proxy endpoint that fetches the LAAR label PDF and streams it to the browser.
 * This is needed because LAAR's PDF endpoint requires Bearer token authentication.
 */
$router->get('/labels/:guia', function (array $params) use ($logger) {
    $guia = $params['guia'] ?? '';

    // Validate guide number format (alphanumeric only)
    if (empty($guia) || !preg_match('/^[A-Za-z0-9]+$/', $guia)) {
        http_response_code(400);
        Router::json(['error' => 'Invalid guide number']);
        return;
    }

    try {
        $logger->info('Fetching label PDF from LAAR', ['guia' => $guia]);

        $laarService = LaarService::getInstance();
        $token = $laarService->getToken();
        $client = $laarService->getClient();

        $response = $client->get('/api/Pdfs/v3/etiqueta/descargar', [
            'headers' => ['Authorization' => "Bearer {$token}"],
            'query' => ['guia' => $guia],
            'timeout' => 30,
        ]);

        $contentType = $response->getHeaderLine('Content-Type') ?: 'application/pdf';
        $body = $response->getBody()->getContents();

        header("Content-Type: {$contentType}");
        header("Content-Disposition: inline; filename=\"etiqueta-{$guia}.pdf\"");
        header('Cache-Control: private, max-age=3600');
        echo $body;
    } catch (\GuzzleHttp\Exception\ClientException $e) {
        // Retry once on 401 (expired token)
        if ($e->getResponse()->getStatusCode() === 401) {
            try {
                $laarService->clearToken();
                $token = $laarService->getToken();
                $client = $laarService->getClient();

                $response = $client->get('/api/Pdfs/v3/etiqueta/descargar', [
                    'headers' => ['Authorization' => "Bearer {$token}"],
                    'query' => ['guia' => $guia],
                    'timeout' => 30,
                ]);

                $contentType = $response->getHeaderLine('Content-Type') ?: 'application/pdf';
                $body = $response->getBody()->getContents();

                header("Content-Type: {$contentType}");
                header("Content-Disposition: inline; filename=\"etiqueta-{$guia}.pdf\"");
                header('Cache-Control: private, max-age=3600');
                echo $body;
                return;
            } catch (\Exception $retryError) {
                $logger->error('Label fetch retry failed', ['guia' => $guia, 'error' => $retryError->getMessage()]);
            }
        }

        $status = $e->getResponse()->getStatusCode();
        $logger->error('Failed to fetch label PDF', [
            'guia' => $guia,
            'status' => $status,
            'message' => $e->getMessage(),
        ]);

        http_response_code($status);
        Router::json([
            'error' => 'Failed to fetch label',
            'message' => $status === 404 ? 'Label not found' : 'Could not retrieve label from LAAR',
        ]);
    } catch (\Exception $e) {
        $logger->error('Failed to fetch label PDF', [
            'guia' => $guia,
            'message' => $e->getMessage(),
        ]);

        http_response_code(500);
        Router::json([
            'error' => 'Failed to fetch label',
            'message' => 'Could not retrieve label from LAAR',
        ]);
    }
});
