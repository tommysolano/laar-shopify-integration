import { Router } from 'express';
import config from '../config.js';
import { laarService } from '../services/laarService.js';
import { createLogger } from '../utils/logger.js';
import { readFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const router = Router();
const logger = createLogger('carrier-service');

// Load shipping rates configuration
let shippingRates;
try {
  const ratesPath = join(__dirname, '..', '..', 'data', 'shipping-rates.json');
  shippingRates = JSON.parse(readFileSync(ratesPath, 'utf-8'));
  logger.info('Shipping rates loaded successfully');
} catch (error) {
  logger.error('Failed to load shipping-rates.json, using defaults:', error.message);
  shippingRates = {
    zones: {
      TL: { name: 'Local', base_price: 2.20, price_per_extra_kg: 0.44, included_kg: 2, min_delivery_days: 1, max_delivery_days: 2 },
      TP: { name: 'Principal', base_price: 3.47, price_per_extra_kg: 0.77, included_kg: 2, min_delivery_days: 1, max_delivery_days: 3 },
      TS: { name: 'Secundaria', base_price: 3.74, price_per_extra_kg: 0.83, included_kg: 2, min_delivery_days: 2, max_delivery_days: 4 },
      TE: { name: 'Especial', base_price: 4.18, price_per_extra_kg: 0.99, included_kg: 2, min_delivery_days: 3, max_delivery_days: 5 },
      TO: { name: 'Oriente', base_price: 5.28, price_per_extra_kg: 1.54, included_kg: 2, min_delivery_days: 3, max_delivery_days: 5 },
      TG: { name: 'Galápagos', base_price: 14.30, price_per_extra_kg: 2.86, included_kg: 2, min_delivery_days: 5, max_delivery_days: 10 }
    },
    oriente_provinces: ['NAPO', 'PASTAZA', 'MORONA SANTIAGO', 'ZAMORA CHINCHIPE', 'SUCUMBIOS', 'ORELLANA'],
    galapagos_provinces: ['GALAPAGOS', 'GALÁPAGOS'],
    default_zone: 'TP',
    service_name: 'LAAR Courier Express',
    currency: 'USD',
    free_shipping_threshold: 0
  };
}

/**
 * Calculate shipping rate based on zone and weight
 */
function calculateRate(zone, weightKg, totalPrice) {
  const zoneConfig = shippingRates.zones[zone] || shippingRates.zones[shippingRates.default_zone];
  
  if (!zoneConfig) {
    logger.error(`Zone ${zone} not found in shipping rates config`);
    return null;
  }

  // Calculate real price: base + extra kg + IVA
  const extraKg = Math.max(0, weightKg - (zoneConfig.included_kg || 1));
  const subtotal = zoneConfig.base_price + (extraKg * zoneConfig.price_per_extra_kg);
  const ivaRate = shippingRates.iva_rate || 0;
  const actualCost = Math.round(subtotal * (1 + ivaRate) * 100) / 100;

  // Free shipping check
  const freeThreshold = shippingRates.free_shipping_threshold || 0;
  const isFreeShipping = freeThreshold > 0 && totalPrice >= freeThreshold;

  return {
    price: isFreeShipping ? 0 : actualCost,
    actualCost,
    isFreeShipping,
    zone,
    zoneName: zoneConfig.name,
    minDays: zoneConfig.min_delivery_days,
    maxDays: zoneConfig.max_delivery_days
  };
}

/**
 * Add delivery date offset from today
 */
function getDeliveryDate(daysFromNow) {
  const date = new Date();
  date.setDate(date.getDate() + daysFromNow);
  return date.toISOString().split('T')[0];
}

/**
 * POST /carrier-service/rates
 * 
 * Shopify sends rate requests here during checkout.
 * We look up the destination city in LAAR's catalog to determine the zone,
 * calculate weight, and return shipping rates.
 */
router.post('/', async (req, res) => {
  try {
    const rateRequest = req.body?.rate;

    if (!rateRequest) {
      logger.warn('Invalid rate request: missing rate object');
      return res.json({ rates: [] });
    }

    const { destination, items, currency } = rateRequest;

    logger.info('Rate request received', {
      city: destination?.city,
      province: destination?.province,
      country: destination?.country,
      itemCount: items?.length
    });

    // Only handle Ecuador shipments
    if (destination?.country && destination.country !== 'EC') {
      logger.info('Non-Ecuador destination, returning empty rates');
      return res.json({ rates: [] });
    }

    // Calculate total weight in kg (Shopify sends grams per item)
    const totalWeightGrams = (items || []).reduce((sum, item) => {
      return sum + ((item.grams || 0) * (item.quantity || 1));
    }, 0);
    const totalWeightKg = Math.max(1, Math.ceil(totalWeightGrams / 1000));

    // Calculate total cart price in dollars (Shopify sends cents)
    const totalPriceCents = (items || []).reduce((sum, item) => {
      return sum + ((item.price || 0) * (item.quantity || 1));
    }, 0);
    const totalPriceDollars = totalPriceCents / 100;

    // Try to find the destination zone using LAAR's city catalog
    let zone = shippingRates.default_zone;
    const cityName = destination?.city || '';
    const provinceName = destination?.province || '';

    // Check if destination is Galápagos or Oriente by province
    const normalizedProvince = provinceName.toUpperCase().trim();
    const isGalapagos = normalizedProvince.length > 2 && (shippingRates.galapagos_provinces || []).some(
      p => normalizedProvince === p || normalizedProvince.includes(p) || p.includes(normalizedProvince)
    );
    const isOriente = normalizedProvince.length > 2 && (shippingRates.oriente_provinces || []).some(
      p => normalizedProvince === p || normalizedProvince.includes(p) || p.includes(normalizedProvince)
    );

    // Check if destination is in the Local zone (Guayaquil or La Puntilla in Samborondón)
    const normalizedCity = cityName.toLowerCase().trim();
    const localConfig = shippingRates.local_zone || {};
    const localCities = (localConfig.cities || []).map(c => c.toLowerCase());
    const localSectors = (localConfig.special_sectors || []).map(s => s.toLowerCase());
    const localSectorCity = (localConfig.special_sectors_city || '').toLowerCase();

    // City is directly Guayaquil (or contains it, e.g. "Guayaquil - Norte")
    const isLocalCity = localCities.some(c => normalizedCity === c || normalizedCity.includes(c));

    // City is "La Puntilla" / "Puntilla" directly
    const isSectorAsCity = localSectors.some(s => normalizedCity === s || normalizedCity.includes(s));

    // City is Samborondón and address mentions La Puntilla
    const address1 = (destination?.address1 || '').toLowerCase();
    const address2 = (destination?.address2 || '').toLowerCase();
    const isSectorInAddress = normalizedCity === localSectorCity &&
      localSectors.some(s => address1.includes(s) || address2.includes(s));

    const isLocal = isLocalCity || isSectorAsCity || isSectorInAddress;

    if (isGalapagos) {
      zone = 'TG';
      logger.info(`Province ${provinceName} detected as Galápagos`);
    } else if (isOriente) {
      zone = 'TO';
      logger.info(`Province ${provinceName} detected as Oriente`);
    } else if (isLocal) {
      zone = 'TL';
      logger.info(`Destination detected as Local zone: city=${cityName}, address1=${destination?.address1}`);
    } else if (cityName) {
      try {
        const cities = await laarService.getCities();
        const normalizedCity = cityName.toLowerCase().trim();

        // Find matching city
        let match = cities.find(c => (c.nombre || '').toLowerCase() === normalizedCity);
        if (!match) {
          match = cities.find(c => {
            const laarCity = (c.nombre || '').toLowerCase();
            return laarCity.includes(normalizedCity) || normalizedCity.includes(laarCity);
          });
        }

        if (match) {
          // Use LAAR trayecto code directly if it matches a configured zone
          const trayecto = match.trayecto || '';
          if (shippingRates.zones[trayecto]) {
            zone = trayecto;
          }
          logger.info(`City ${cityName} matched to LAAR city ${match.nombre}, province: ${match.provincia}, zone: ${zone}`);
        } else {
          logger.warn(`City ${cityName} not found in LAAR catalog, using default zone: ${zone}`);
        }
      } catch (error) {
        logger.error('Error fetching LAAR cities for rate calc:', error.message);
        // Continue with default zone
      }
    }

    // Calculate rate
    const rate = calculateRate(zone, totalWeightKg, totalPriceDollars);

    if (!rate) {
      logger.error('Failed to calculate rate');
      return res.json({ rates: [] });
    }

    // Build Shopify rate response
    // total_price must be in cents (string)
    const description = rate.isFreeShipping
      ? `${rate.zoneName} - Envío Gratis - Entrega estimada ${rate.minDays}-${rate.maxDays} días hábiles`
      : `${rate.zoneName} - Entrega estimada ${rate.minDays}-${rate.maxDays} días hábiles`;

    const rates = [
      {
        service_name: shippingRates.service_name || 'LAAR Courier Express',
        service_code: `LAAR_${zone}`,
        total_price: String(Math.round(rate.price * 100)),
        description,
        currency: currency || shippingRates.currency || 'USD',
        min_delivery_date: getDeliveryDate(rate.minDays),
        max_delivery_date: getDeliveryDate(rate.maxDays)
      }
    ];

    logger.info('Returning shipping rate', {
      city: cityName,
      zone,
      weightKg: totalWeightKg,
      price: rate.price,
      actualCost: rate.actualCost,
      isFreeShipping: rate.isFreeShipping,
      serviceName: rates[0].service_name
    });

    if (rate.isFreeShipping) {
      logger.info('💰 Envío gratis aplicado - Costo real LAAR asumido por la tienda', {
        city: cityName,
        zone,
        actualCost: rate.actualCost,
        cartTotal: totalPriceDollars
      });
    }

    return res.json({ rates });
  } catch (error) {
    logger.error('Carrier service rate calculation failed:', error.message);
    // Return empty rates on error - Shopify will show "rates unavailable"
    return res.json({ rates: [] });
  }
});

export default router;
