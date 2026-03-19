import { Router } from 'express';
import { verifyShopifyHmacMiddleware } from '../utils/verifyShopifyHmac.js';
import { laarService } from '../services/laarService.js';
import { shopifyService } from '../services/shopifyService.js';
import config from '../config.js';
import { createLogger } from '../utils/logger.js';
import { readFileSync } from 'fs';
import { join, dirname } from 'path';
import { fileURLToPath } from 'url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const router = Router();
const logger = createLogger('webhooks');

// Load shipping rates for cost calculation
let shippingRates;
try {
  const ratesPath = join(__dirname, '..', '..', 'data', 'shipping-rates.json');
  shippingRates = JSON.parse(readFileSync(ratesPath, 'utf-8'));
} catch (error) {
  logger.error('Failed to load shipping-rates.json in webhooks:', error.message);
  shippingRates = null;
}

/**
 * Calculate the real LAAR shipping cost for an order
 * Used to inform the store owner how much free-shipping orders actually cost
 */
function calculateRealShippingCost(order) {
  if (!shippingRates) {
    logger.warn('shippingRates not loaded, cannot calculate real cost');
    return null;
  }

  const shippingLine = (order.shipping_lines || [])[0];
  if (!shippingLine) {
    logger.warn('No shipping_lines found in order');
    return null;
  }

  logger.info('shipping_lines[0] data:', {
    code: shippingLine.code,
    title: shippingLine.title,
    source: shippingLine.source,
    price: shippingLine.price
  });

  // Try to extract zone from code (e.g., "LAAR_TL" -> "TL")
  const code = shippingLine.code || '';
  let zone = code.replace('LAAR_', '');
  let zoneConfig = shippingRates.zones[zone];

  // Fallback: if zone not found, use default zone
  if (!zoneConfig) {
    logger.warn(`Zone "${zone}" from code "${code}" not found in rates, using default: ${shippingRates.default_zone}`);
    zone = shippingRates.default_zone;
    zoneConfig = shippingRates.zones[zone];
  }

  if (!zoneConfig) {
    logger.error('Could not determine zone config for shipping cost calculation');
    return null;
  }

  // Calculate weight in kg
  const totalWeightGrams = (order.line_items || []).reduce((sum, item) => {
    return sum + ((item.grams || 0) * (item.quantity || 1));
  }, 0);
  const totalWeightKg = Math.max(1, Math.ceil(totalWeightGrams / 1000));

  // Calculate real cost: base + extra kg + IVA
  const extraKg = Math.max(0, totalWeightKg - (zoneConfig.included_kg || 1));
  const subtotal = zoneConfig.base_price + (extraKg * zoneConfig.price_per_extra_kg);
  const ivaRate = shippingRates.iva_rate || 0;
  const cost = Math.round(subtotal * (1 + ivaRate) * 100) / 100;

  logger.info('Real shipping cost calculated:', { cost, zone, zoneName: zoneConfig.name, weightKg: totalWeightKg });

  return {
    cost,
    zone,
    zoneName: zoneConfig.name,
    weightKg: totalWeightKg
  };
}

/**
 * POST /webhooks/orders_paid
 * 
 * Handles Shopify orders/paid webhook
 * Pipeline:
 * 1. Verify HMAC signature
 * 2. Parse order data
 * 3. Check for existing LAAR guide (idempotency)
 * 4. Create LAAR guide
 * 5. Save metafields to order
 * 6. Create fulfillment with tracking
 */
router.post('/orders_paid', verifyShopifyHmacMiddleware, async (req, res) => {
  let order;
  
  try {
    // Parse the raw body if it's a buffer (from express.raw())
    if (Buffer.isBuffer(req.body)) {
      order = JSON.parse(req.body.toString('utf8'));
    } else {
      order = req.body;
    }
  } catch (parseError) {
    logger.error('Failed to parse webhook body:', parseError.message);
    return res.status(400).json({ error: 'Invalid JSON body' });
  }
  
  const orderId = order.id;
  const orderName = order.name || `#${order.order_number}`;
  
  logger.info('📦 Received orders/paid webhook', { 
    orderId, 
    orderName,
    email: order.email 
  });
  
  // Log full order keys for debugging
  logger.info('Order keys received: ' + Object.keys(order).join(', '));
  
  // Log shipping address for debugging
  const shippingAddr = order.shipping_address || order.shippingAddress || null;
  if (shippingAddr) {
    logger.info('Shipping address found: ' + JSON.stringify(shippingAddr));
    logger.info('Customer phone: ' + (shippingAddr.phone || 'NO PHONE IN SHIPPING'));
  } else {
    logger.warn('⚠️ NO SHIPPING ADDRESS IN ORDER');
    // Try to find phone in other places
    logger.info('Trying other sources - customer: ' + JSON.stringify(order.customer || 'none'));
    logger.info('Order billing_address: ' + JSON.stringify(order.billing_address || 'none'));
  }
  
  try {
    // Step 1: Check for existing guide (idempotency)
    logger.info('Checking for existing LAAR guide...', { orderId });
    const existingMetafields = await shopifyService.getOrderLaarMetafields(orderId);
    
    if (existingMetafields?.exists) {
      logger.info('⏭️  Order already has LAAR guide, skipping', { 
        orderId, 
        existingGuia: existingMetafields.guia 
      });
      return res.status(200).json({ 
        ok: true, 
        skipped: true, 
        message: 'Guide already exists',
        guia: existingMetafields.guia 
      });
    }
    
    // Step 2: Build and create LAAR guide
    logger.info('Creating LAAR guide...', { orderId });
    const guidePayload = await laarService.buildGuidePayload(order);
    logger.info('LAAR guide payload: ' + JSON.stringify(guidePayload));
    const guideResult = await laarService.createGuide(guidePayload);
    
    const { guia, pdfUrl } = guideResult;
    logger.info('✅ LAAR guide created', { orderId, guia, pdfUrl });
    
    // Build proxy label URL (accessible without LAAR auth)
    const labelUrl = `${config.shopify.appUrl}/labels/${guia}`;
    
    // Step 3: Calculate real shipping cost (for store owner visibility)
    let shippingCost = null;
    const shippingPrice = parseFloat((order.shipping_lines || [])[0]?.price || '0');
    const isFreeShipping = shippingPrice === 0;

    const costInfo = calculateRealShippingCost(order);
    if (costInfo) {
      shippingCost = costInfo.cost;
      if (isFreeShipping) {
        logger.info('💰 Envío gratis - Costo real LAAR asumido por la tienda', {
          orderId, orderName, shippingCost,
          zone: costInfo.zone, zoneName: costInfo.zoneName, weightKg: costInfo.weightKg
        });
      } else {
        logger.info('📦 Costo de envío LAAR (cobrado al cliente)', {
          orderId, orderName, shippingCost,
          zone: costInfo.zone, zoneName: costInfo.zoneName
        });
      }
    } else {
      logger.warn('⚠️ No se pudo calcular el costo real de envío LAAR', { orderId, orderName });
    }

    // Step 4: Save metafields to order
    logger.info('Saving metafields to order...', { orderId, guia });
    await shopifyService.saveOrderMetafields(orderId, guia, pdfUrl, labelUrl, shippingCost);
    
    // Step 5: Create fulfillment with tracking
    logger.info('Creating fulfillment...', { orderId, guia });
    const trackingUrl = `https://fenix.laarcourier.com/Tracking/?guia=${guia}`;
    
    try {
      const fulfillment = await shopifyService.createFulfillment(orderId, guia, trackingUrl);
      
      if (fulfillment) {
        logger.info('✅ Fulfillment created successfully', { 
          orderId, 
          fulfillmentId: fulfillment.id 
        });
      } else {
        logger.warn('⚠️  Fulfillment not created (may already be fulfilled)', { orderId });
      }
    } catch (fulfillmentError) {
      // Log but don't fail - metafields are saved
      logger.error('Failed to create fulfillment (metafields saved):', {
        orderId,
        error: fulfillmentError.message
      });
    }
    
    // Step 6: Add tag for easy filtering (optional)
    try {
      await shopifyService.addOrderTags(orderId, ['laar-guia-created', `guia-${guia}`]);
    } catch (tagError) {
      logger.warn('Failed to add tags (non-critical):', tagError.message);
    }
    
    logger.info('🎉 Order processing completed successfully', { 
      orderId, 
      orderName, 
      guia 
    });
    
    return res.status(200).json({ 
      ok: true, 
      guia,
      pdfUrl,
      message: 'Guide created and fulfillment processed'
    });
    
  } catch (error) {
    logger.error('❌ Order processing failed', { 
      orderId, 
      orderName, 
      error: error.message,
      stack: error.stack
    });
    
    // Return 500 so Shopify retries (unless it's a permanent error)
    return res.status(500).json({ 
      ok: false, 
      error: error.message 
    });
  }
});

/**
 * POST /webhooks/test
 * Test endpoint to verify webhook setup (development only)
 */
router.post('/test', verifyShopifyHmacMiddleware, (req, res) => {
  logger.info('Test webhook received');
  
  let body;
  if (Buffer.isBuffer(req.body)) {
    body = JSON.parse(req.body.toString('utf8'));
  } else {
    body = req.body;
  }
  
  res.status(200).json({ 
    ok: true, 
    message: 'Test webhook received',
    receivedData: body
  });
});

export default router;
