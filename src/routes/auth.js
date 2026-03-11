import { Router } from 'express';
import crypto from 'crypto';
import axios from 'axios';
import config from '../config.js';
import { tokenStorage } from '../services/tokenStorage.js';
import { shopifyService } from '../services/shopifyService.js';
import { createLogger } from '../utils/logger.js';

const router = Router();
const logger = createLogger('auth');

// Required scopes for the app
const SCOPES = [
  'read_orders',
  'write_orders', 
  'read_fulfillments',
  'write_fulfillments',
  'read_assigned_fulfillment_orders',
  'write_assigned_fulfillment_orders',
  'write_merchant_managed_fulfillment_orders',
  'read_shipping',
  'write_shipping'
].join(',');

/**
 * Register webhooks via Shopify Admin API
 */
async function registerWebhooks(shop, accessToken) {
  const webhooks = [
    {
      topic: 'orders/paid',
      address: `${config.shopify.appUrl}/webhooks/orders_paid`,
      format: 'json'
    }
  ];

  const results = [];

  for (const webhook of webhooks) {
    try {
      const response = await axios.post(
        `https://${shop}/admin/api/2024-01/webhooks.json`,
        { webhook },
        {
          headers: {
            'X-Shopify-Access-Token': accessToken,
            'Content-Type': 'application/json'
          }
        }
      );
      logger.info(`✅ Webhook registered: ${webhook.topic} -> ${webhook.address}`);
      results.push({ topic: webhook.topic, success: true, id: response.data.webhook.id });
    } catch (error) {
      // Check if webhook already exists
      if (error.response?.status === 422) {
        logger.info(`Webhook ${webhook.topic} already exists, skipping`);
        results.push({ topic: webhook.topic, success: true, alreadyExists: true });
      } else {
        logger.error(`Failed to register webhook ${webhook.topic}:`, error.response?.data || error.message);
        results.push({ topic: webhook.topic, success: false, error: error.message });
      }
    }
  }

  return results;
}

/**
 * Generate a random nonce for OAuth state
 */
function generateNonce() {
  return crypto.randomBytes(16).toString('hex');
}

/**
 * Verify HMAC for OAuth callbacks
 */
function verifyOAuthHmac(query) {
  const { hmac, ...params } = query;
  
  if (!hmac) return false;
  
  // Sort and stringify params
  const message = Object.keys(params)
    .sort()
    .map(key => `${key}=${params[key]}`)
    .join('&');
  
  const calculatedHmac = crypto
    .createHmac('sha256', config.shopify.clientSecret)
    .update(message)
    .digest('hex');
  
  return crypto.timingSafeEqual(
    Buffer.from(hmac, 'hex'),
    Buffer.from(calculatedHmac, 'hex')
  );
}

// Store nonces temporarily (in production, use Redis or similar)
const nonceStore = new Map();

/**
 * GET /auth
 * 
 * Initiates OAuth flow - redirects to Shopify authorization page
 * Usage: /auth?shop=mystore.myshopify.com
 */
router.get('/', (req, res) => {
  const { shop } = req.query;
  
  if (!shop) {
    return res.status(400).json({ 
      error: 'Missing shop parameter',
      usage: '/auth?shop=yourstore.myshopify.com'
    });
  }
  
  // Validate shop format
  const shopRegex = /^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/;
  if (!shopRegex.test(shop)) {
    return res.status(400).json({ 
      error: 'Invalid shop format',
      expected: 'yourstore.myshopify.com'
    });
  }
  
  // Generate nonce for state verification
  const nonce = generateNonce();
  nonceStore.set(shop, { nonce, timestamp: Date.now() });
  
  // Clean up old nonces (older than 10 minutes)
  const TEN_MINUTES = 10 * 60 * 1000;
  for (const [key, value] of nonceStore.entries()) {
    if (Date.now() - value.timestamp > TEN_MINUTES) {
      nonceStore.delete(key);
    }
  }
  
  // Build redirect URL
  const redirectUri = `${config.shopify.appUrl}/auth/callback`;
  const authUrl = `https://${shop}/admin/oauth/authorize?` + 
    `client_id=${config.shopify.clientId}&` +
    `scope=${SCOPES}&` +
    `redirect_uri=${encodeURIComponent(redirectUri)}&` +
    `state=${nonce}`;
  
  logger.info(`Initiating OAuth for shop: ${shop}`);
  res.redirect(authUrl);
});

/**
 * GET /auth/callback
 * 
 * OAuth callback - exchanges code for access token
 */
router.get('/callback', async (req, res) => {
  const { shop, code, state, hmac } = req.query;
  
  logger.info(`OAuth callback received for shop: ${shop}`);
  
  // Validate required params
  if (!shop || !code || !state) {
    logger.error('Missing required OAuth parameters');
    return res.status(400).json({ error: 'Missing required parameters' });
  }
  
  // Verify HMAC
  if (!verifyOAuthHmac(req.query)) {
    logger.error('Invalid HMAC in OAuth callback');
    return res.status(401).json({ error: 'Invalid HMAC signature' });
  }
  
  // Verify nonce/state
  const storedNonce = nonceStore.get(shop);
  if (!storedNonce || storedNonce.nonce !== state) {
    logger.error('Invalid state/nonce in OAuth callback');
    return res.status(401).json({ error: 'Invalid state parameter' });
  }
  
  // Remove used nonce
  nonceStore.delete(shop);
  
  try {
    // Exchange code for access token
    const tokenResponse = await axios.post(
      `https://${shop}/admin/oauth/access_token`,
      {
        client_id: config.shopify.clientId,
        client_secret: config.shopify.clientSecret,
        code
      },
      {
        headers: { 'Content-Type': 'application/json' }
      }
    );
    
    const { access_token, scope } = tokenResponse.data;
    
    if (!access_token) {
      throw new Error('No access token in response');
    }
    
    logger.info(`✅ Successfully obtained access token for ${shop}`);
    logger.info(`   Scopes granted: ${scope}`);
    
    // Store the token
    tokenStorage.setToken(shop, access_token, scope);
    
    // Register webhooks automatically
    logger.info('Registering webhooks...');
    const webhookResults = await registerWebhooks(shop, access_token);
    const allWebhooksOk = webhookResults.every(r => r.success);
    const webhookStatus = allWebhooksOk ? '✅ Webhooks registrados' : '⚠️ Algunos webhooks fallaron';

    // Register CarrierService for dynamic shipping rates
    logger.info('Registering CarrierService...');
    try {
      await shopifyService.registerCarrierService();
      logger.info('✅ CarrierService registered for dynamic shipping rates');
    } catch (carrierError) {
      logger.error('Failed to register CarrierService:', carrierError.message);
    }
    
    // Redirect to success page or app - show token for copying to env var
    res.send(`
      <!DOCTYPE html>
      <html>
      <head>
        <title>LAAR Integration - Instalación Exitosa</title>
        <style>
          body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
                 display: flex; justify-content: center; align-items: center; 
                 min-height: 100vh; margin: 0; background: #f6f6f7; }
          .container { text-align: center; padding: 40px; background: white; 
                       border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); max-width: 600px; }
          h1 { color: #008060; }
          p { color: #637381; }
          .checkmark { font-size: 48px; margin-bottom: 20px; }
          .token-box { background: #1a1a2e; color: #00ff88; padding: 15px; border-radius: 6px; 
                       font-family: monospace; font-size: 12px; word-break: break-all; 
                       margin: 20px 0; text-align: left; }
          .copy-btn { background: #008060; color: white; border: none; padding: 10px 20px; 
                      border-radius: 4px; cursor: pointer; font-size: 14px; margin-top: 10px; }
          .copy-btn:hover { background: #006e52; }
          .instructions { background: #fff8e6; border: 1px solid #ffcc00; padding: 15px; 
                          border-radius: 6px; text-align: left; margin-top: 20px; }
          .instructions ol { margin: 10px 0; padding-left: 20px; }
          .instructions li { margin: 5px 0; color: #333; }
        </style>
      </head>
      <body>
        <div class="container">
          <div class="checkmark">✅</div>
          <h1>¡Instalación Exitosa!</h1>
          <p>LAAR Courier Integration se ha conectado correctamente a tu tienda.</p>
          <p><strong>${shop}</strong></p>
          <p style="margin-top: 10px; color: ${allWebhooksOk ? '#008060' : '#ff9800'};">${webhookStatus}</p>
          
          <div class="instructions">
            <strong>⚠️ IMPORTANTE - Guarda este token:</strong>
            <p>Copia este Access Token y agrégalo como variable de entorno en Render para que persista entre reinicios.</p>
          </div>
          
          <div class="token-box" id="token">${access_token}</div>
          <button class="copy-btn" onclick="copyToken()">📋 Copiar Token</button>
          
          <div class="instructions">
            <strong>Pasos en Render:</strong>
            <ol>
              <li>Ve a tu servicio en Render Dashboard</li>
              <li>Ve a Environment → Add Environment Variable</li>
              <li>Nombre: <code>SHOPIFY_ACCESS_TOKEN</code></li>
              <li>Valor: (pega el token copiado)</li>
              <li>Click "Save Changes"</li>
            </ol>
          </div>
        </div>
        <script>
          function copyToken() {
            const token = document.getElementById('token').innerText;
            navigator.clipboard.writeText(token).then(() => {
              const btn = document.querySelector('.copy-btn');
              btn.textContent = '✅ ¡Copiado!';
              setTimeout(() => btn.textContent = '📋 Copiar Token', 2000);
            });
          }
        </script>
      </body>
      </html>
    `);
    
  } catch (error) {
    logger.error('Failed to exchange code for token:', error.response?.data || error.message);
    res.status(500).json({ 
      error: 'Failed to complete OAuth',
      details: error.response?.data?.error_description || error.message
    });
  }
});

/**
 * GET /auth/status
 * 
 * Check if a shop is authenticated
 */
router.get('/status', (req, res) => {
  const shop = req.query.shop || config.shopify.storeDomain;
  
  if (!shop) {
    return res.status(400).json({ error: 'Missing shop parameter' });
  }
  
  const hasToken = tokenStorage.hasToken(shop);
  const tokenData = tokenStorage.getTokenData(shop);
  
  res.json({
    shop,
    authenticated: hasToken,
    installedAt: tokenData?.installedAt || null,
    scopes: tokenData?.scope || null
  });
});

/**
 * POST /auth/uninstall
 * 
 * Webhook handler for app uninstallation
 */
router.post('/uninstall', (req, res) => {
  // This would be called by Shopify when app is uninstalled
  // You should verify the webhook HMAC here
  const shop = req.body?.myshopify_domain || req.body?.shop_domain;
  
  if (shop) {
    logger.info(`App uninstalled from: ${shop}`);
    tokenStorage.removeToken(shop);
  }
  
  res.status(200).json({ ok: true });
});

export default router;
