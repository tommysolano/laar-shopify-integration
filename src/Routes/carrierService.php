<?php
/**
 * Carrier Service routes - Dynamic shipping rate calculation for Shopify checkout
 */

use App\Router;
use App\Config;
use App\Services\LaarService;
use App\Utils\Logger;

$logger = Logger::create('carrier-service');

// Load shipping rates configuration
$ratesPath = dirname(__DIR__, 2) . '/data/shipping-rates.json';
$shippingRates = null;

if (file_exists($ratesPath)) {
    $shippingRates = json_decode(file_get_contents($ratesPath), true);
    if ($shippingRates) {
        $logger->info('Shipping rates loaded successfully');
    }
}

if (empty($shippingRates)) {
    $logger->error('Failed to load shipping-rates.json, using defaults');
    $shippingRates = [
        'zones' => [
            'TL' => ['name' => 'Local', 'base_price' => 2.20, 'price_per_extra_kg' => 0.44, 'included_kg' => 2, 'min_delivery_days' => 1, 'max_delivery_days' => 2],
            'TP' => ['name' => 'Principal', 'base_price' => 3.47, 'price_per_extra_kg' => 0.77, 'included_kg' => 2, 'min_delivery_days' => 1, 'max_delivery_days' => 3],
            'TS' => ['name' => 'Secundaria', 'base_price' => 3.74, 'price_per_extra_kg' => 0.83, 'included_kg' => 2, 'min_delivery_days' => 2, 'max_delivery_days' => 4],
            'TE' => ['name' => 'Especial', 'base_price' => 4.18, 'price_per_extra_kg' => 0.99, 'included_kg' => 2, 'min_delivery_days' => 3, 'max_delivery_days' => 5],
            'TO' => ['name' => 'Oriente', 'base_price' => 5.28, 'price_per_extra_kg' => 1.54, 'included_kg' => 2, 'min_delivery_days' => 3, 'max_delivery_days' => 5],
            'TG' => ['name' => 'Galápagos', 'base_price' => 14.30, 'price_per_extra_kg' => 2.86, 'included_kg' => 2, 'min_delivery_days' => 5, 'max_delivery_days' => 10],
        ],
        'oriente_provinces' => ['NAPO', 'PASTAZA', 'MORONA SANTIAGO', 'ZAMORA CHINCHIPE', 'SUCUMBIOS', 'ORELLANA'],
        'galapagos_provinces' => ['GALAPAGOS', 'GALÁPAGOS'],
        'default_zone' => 'TP',
        'service_name' => 'LAAR Courier Express',
        'currency' => 'USD',
        'free_shipping_threshold' => 0,
    ];
}

/**
 * Calculate shipping rate based on zone and weight
 */
function calculateRate(array $shippingRates, string $zone, float $weightKg, float $totalPrice): ?array
{
    $zoneConfig = $shippingRates['zones'][$zone] ?? $shippingRates['zones'][$shippingRates['default_zone']] ?? null;

    if (!$zoneConfig) {
        return null;
    }

    // Calculate real price: base + extra kg + IVA
    $extraKg = max(0, $weightKg - ($zoneConfig['included_kg'] ?? 1));
    $subtotal = $zoneConfig['base_price'] + ($extraKg * $zoneConfig['price_per_extra_kg']);
    $ivaRate = $shippingRates['iva_rate'] ?? 0;
    $actualCost = round($subtotal * (1 + $ivaRate), 2);

    // Free shipping check
    $freeThreshold = $shippingRates['free_shipping_threshold'] ?? 0;
    $isFreeShipping = $freeThreshold > 0 && $totalPrice >= $freeThreshold;

    return [
        'price' => $isFreeShipping ? 0 : $actualCost,
        'actualCost' => $actualCost,
        'isFreeShipping' => $isFreeShipping,
        'zone' => $zone,
        'zoneName' => $zoneConfig['name'],
        'minDays' => $zoneConfig['min_delivery_days'],
        'maxDays' => $zoneConfig['max_delivery_days'],
    ];
}

/**
 * Get delivery date offset from today
 */
function getDeliveryDate(int $daysFromNow): string
{
    $date = new \DateTime();
    $date->modify("+{$daysFromNow} days");
    return $date->format('Y-m-d');
}

/**
 * GET /carrier-service/rates
 *
 * Health-check endpoint. Shopify uses POST; a GET should never be sent by
 * Shopify but is useful to verify from a browser that the endpoint is
 * publicly reachable from the new server.
 */
$router->get('/carrier-service/rates', function () use ($shippingRates, $logger) {
    Router::json([
        'ok' => true,
        'message' => 'Carrier service endpoint is reachable. Shopify will POST here.',
        'service_name' => $shippingRates['service_name'] ?? null,
        'free_shipping_threshold' => $shippingRates['free_shipping_threshold'] ?? null,
        'iva_rate' => $shippingRates['iva_rate'] ?? null,
        'zones' => array_keys($shippingRates['zones'] ?? []),
    ]);
});

/**
 * POST /carrier-service/rates
 * 
 * Shopify sends rate requests here during checkout.
 */
$router->post('/carrier-service/rates', function () use ($shippingRates, $logger) {
    try {
        $body = Router::getJsonBody();
        $rateRequest = $body['rate'] ?? null;

        if (!$rateRequest) {
            $logger->warning('Invalid rate request: missing rate object');
            Router::json(['rates' => []]);
            return;
        }

        $destination = $rateRequest['destination'] ?? [];
        $items = $rateRequest['items'] ?? [];
        $currency = $rateRequest['currency'] ?? null;

        $logger->info('Rate request received', [
            'city' => $destination['city'] ?? '',
            'province' => $destination['province'] ?? '',
            'country' => $destination['country'] ?? '',
            'itemCount' => count($items),
        ]);

        // Only handle Ecuador shipments
        if (!empty($destination['country']) && $destination['country'] !== 'EC') {
            $logger->info('Non-Ecuador destination, returning empty rates');
            Router::json(['rates' => []]);
            return;
        }

        // Calculate total weight in kg (Shopify sends grams per item)
        $totalWeightGrams = 0;
        foreach ($items as $item) {
            $totalWeightGrams += (($item['grams'] ?? 0) * ($item['quantity'] ?? 1));
        }
        $totalWeightKg = max(1, (int)ceil($totalWeightGrams / 1000));

        // Calculate total cart price in dollars (Shopify sends cents)
        $totalPriceCents = 0;
        foreach ($items as $item) {
            $totalPriceCents += (($item['price'] ?? 0) * ($item['quantity'] ?? 1));
        }
        $totalPriceDollars = $totalPriceCents / 100;

        // Determine zone
        $zone = $shippingRates['default_zone'];
        $cityName = $destination['city'] ?? '';
        $provinceName = $destination['province'] ?? '';

        // Check if destination is Galápagos or Oriente by province
        $normalizedProvince = mb_strtoupper(trim($provinceName));
        $isGalapagos = mb_strlen($normalizedProvince) > 2 && !empty(array_filter(
            $shippingRates['galapagos_provinces'] ?? [],
            fn($p) => $normalizedProvince === $p || str_contains($normalizedProvince, $p) || str_contains($p, $normalizedProvince)
        ));
        $isOriente = mb_strlen($normalizedProvince) > 2 && !empty(array_filter(
            $shippingRates['oriente_provinces'] ?? [],
            fn($p) => $normalizedProvince === $p || str_contains($normalizedProvince, $p) || str_contains($p, $normalizedProvince)
        ));

        // Check local zone
        $normalizedCity = mb_strtolower(trim($cityName));
        $localConfig = $shippingRates['local_zone'] ?? [];
        $localCities = array_map('mb_strtolower', $localConfig['cities'] ?? []);
        $localSectors = array_map('mb_strtolower', $localConfig['special_sectors'] ?? []);
        $localSectorCity = mb_strtolower($localConfig['special_sectors_city'] ?? '');

        $isLocalCity = !empty(array_filter($localCities, fn($c) => $normalizedCity === $c || str_contains($normalizedCity, $c)));
        $isSectorAsCity = !empty(array_filter($localSectors, fn($s) => $normalizedCity === $s || str_contains($normalizedCity, $s)));

        $address1 = mb_strtolower($destination['address1'] ?? '');
        $address2 = mb_strtolower($destination['address2'] ?? '');
        $isSectorInAddress = $normalizedCity === $localSectorCity &&
            !empty(array_filter($localSectors, fn($s) => str_contains($address1, $s) || str_contains($address2, $s)));

        $isLocal = $isLocalCity || $isSectorAsCity || $isSectorInAddress;

        if ($isGalapagos) {
            $zone = 'TG';
            $logger->info("Province {$provinceName} detected as Galápagos");
        } elseif ($isOriente) {
            $zone = 'TO';
            $logger->info("Province {$provinceName} detected as Oriente");
        } elseif ($isLocal) {
            $zone = 'TL';
            $logger->info("Destination detected as Local zone: city={$cityName}");
        } elseif (!empty($cityName)) {
            try {
                $laarService = LaarService::getInstance();
                $cities = $laarService->getCities();
                $normalizedCitySearch = mb_strtolower(trim($cityName));

                // Find matching city
                $match = null;
                foreach ($cities as $c) {
                    if (mb_strtolower($c['nombre'] ?? '') === $normalizedCitySearch) {
                        $match = $c;
                        break;
                    }
                }
                if (!$match) {
                    foreach ($cities as $c) {
                        $laarCity = mb_strtolower($c['nombre'] ?? '');
                        if (str_contains($laarCity, $normalizedCitySearch) || str_contains($normalizedCitySearch, $laarCity)) {
                            $match = $c;
                            break;
                        }
                    }
                }

                if ($match) {
                    $trayecto = $match['trayecto'] ?? '';
                    if (isset($shippingRates['zones'][$trayecto])) {
                        $zone = $trayecto;
                    }
                    $logger->info("City {$cityName} matched to LAAR city {$match['nombre']}, zone: {$zone}");
                } else {
                    $logger->warning("City {$cityName} not found in LAAR catalog, using default zone: {$zone}");
                }
            } catch (\Exception $e) {
                $logger->error('Error fetching LAAR cities for rate calc: ' . $e->getMessage());
            }
        }

        // Calculate rate
        $rate = calculateRate($shippingRates, $zone, $totalWeightKg, $totalPriceDollars);

        if (!$rate) {
            $logger->error('Failed to calculate rate');
            Router::json(['rates' => []]);
            return;
        }

        // Build Shopify rate response (total_price in cents as string)
        $description = $rate['isFreeShipping']
            ? "{$rate['zoneName']} - Envío Gratis - Entrega estimada {$rate['minDays']}-{$rate['maxDays']} días hábiles"
            : "{$rate['zoneName']} - Entrega estimada {$rate['minDays']}-{$rate['maxDays']} días hábiles";

        $rates = [
            [
                'service_name' => $shippingRates['service_name'] ?? 'LAAR Courier Express',
                'service_code' => "LAAR_{$zone}",
                'total_price' => (string)(int)round($rate['price'] * 100),
                'description' => $description,
                'currency' => $currency ?? $shippingRates['currency'] ?? 'USD',
                'min_delivery_date' => getDeliveryDate($rate['minDays']),
                'max_delivery_date' => getDeliveryDate($rate['maxDays']),
            ],
        ];

        $logger->info('Returning shipping rate', [
            'city' => $cityName,
            'zone' => $zone,
            'weightKg' => $totalWeightKg,
            'price' => $rate['price'],
            'actualCost' => $rate['actualCost'],
            'isFreeShipping' => $rate['isFreeShipping'],
            'serviceName' => $rates[0]['service_name'],
        ]);

        if ($rate['isFreeShipping']) {
            $logger->info('Envío gratis aplicado - Costo real LAAR asumido por la tienda', [
                'city' => $cityName,
                'zone' => $zone,
                'actualCost' => $rate['actualCost'],
                'cartTotal' => $totalPriceDollars,
            ]);
        }

        Router::json(['rates' => $rates]);
    } catch (\Exception $e) {
        $logger->error('Carrier service rate calculation failed: ' . $e->getMessage());
        Router::json(['rates' => []]);
    }
});
