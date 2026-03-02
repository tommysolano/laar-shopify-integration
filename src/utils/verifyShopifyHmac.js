import crypto from 'crypto';
import config from '../config.js';

/**
 * Verify Shopify webhook HMAC signature
 * 
 * @param {Buffer|string} rawBody - Raw request body
 * @param {string} hmacHeader - X-Shopify-Hmac-Sha256 header value
 * @returns {boolean} - Whether the signature is valid
 */
export function verifyShopifyHmac(rawBody, hmacHeader) {
  if (!hmacHeader) {
    return false;
  }
  
  // Webhooks are signed with SHOPIFY_WEBHOOK_SECRET (even for OAuth apps)
  const secret = config.shopify.webhookSecret || config.shopify.clientSecret;
  if (!secret) {
    throw new Error('No webhook secret configured (SHOPIFY_WEBHOOK_SECRET or SHOPIFY_CLIENT_SECRET)');
  }
  
  // Ensure rawBody is a Buffer or string
  const body = Buffer.isBuffer(rawBody) ? rawBody : Buffer.from(rawBody, 'utf8');
  
  // Calculate HMAC
  const calculatedHmac = crypto
    .createHmac('sha256', secret)
    .update(body)
    .digest('base64');
  
  // Use timingSafeEqual to prevent timing attacks
  try {
    const calculatedBuffer = Buffer.from(calculatedHmac, 'base64');
    const headerBuffer = Buffer.from(hmacHeader, 'base64');
    
    if (calculatedBuffer.length !== headerBuffer.length) {
      return false;
    }
    
    return crypto.timingSafeEqual(calculatedBuffer, headerBuffer);
  } catch (error) {
    return false;
  }
}

/**
 * Express middleware to verify Shopify HMAC
 * Must be used with express.raw() body parser
 */
export function verifyShopifyHmacMiddleware(req, res, next) {
  const hmacHeader = req.get('X-Shopify-Hmac-Sha256');
  
  // Allow skipping HMAC in development mode if configured
  if (config.isDevelopment && config.allowInsecureWebhooks) {
    console.warn('⚠️  WARNING: Skipping HMAC verification (ALLOW_INSECURE_WEBHOOKS=true)');
    next();
    return;
  }
  
  if (!verifyShopifyHmac(req.body, hmacHeader)) {
    console.error('❌ Invalid HMAC signature');
    return res.status(401).json({ error: 'Invalid HMAC signature' });
  }
  
  console.log('✅ HMAC signature verified');
  next();
}

/**
 * Generate HMAC for testing purposes
 * 
 * @param {string} body - JSON body string
 * @param {string} secret - Webhook secret
 * @returns {string} - Base64 encoded HMAC
 */
export function generateHmac(body, secret) {
  return crypto
    .createHmac('sha256', secret)
    .update(body, 'utf8')
    .digest('base64');
}

export default verifyShopifyHmac;
