import { Router } from 'express';
import { verifyShopifyHmacMiddleware } from '../utils/verifyShopifyHmac.js';
import { laarService } from '../services/laarService.js';
import { shopifyService } from '../services/shopifyService.js';
import { createLogger } from '../utils/logger.js';

const router = Router();
const logger = createLogger('webhooks');

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
    const guidePayload = laarService.buildGuidePayload(order);
    const guideResult = await laarService.createGuide(guidePayload);
    
    const { guia, pdfUrl } = guideResult;
    logger.info('✅ LAAR guide created', { orderId, guia, pdfUrl });
    
    // Step 3: Save metafields to order
    logger.info('Saving metafields to order...', { orderId, guia });
    await shopifyService.saveOrderMetafields(orderId, guia, pdfUrl);
    
    // Step 4: Create fulfillment with tracking
    logger.info('Creating fulfillment...', { orderId, guia });
    const trackingUrl = pdfUrl || `https://api.laarcourier.com/tracking/${guia}`;
    
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
    
    // Step 5: Add tag for easy filtering (optional)
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
