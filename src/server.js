import 'dotenv/config';
import express from 'express';
import helmet from 'helmet';
import rateLimit from 'express-rate-limit';
import config, { validateConfig } from './config.js';
import webhooksRouter from './routes/webhooks.js';
import authRouter from './routes/auth.js';
import carrierServiceRouter from './routes/carrierService.js';
import { tokenStorage } from './services/tokenStorage.js';
import { createLogger } from './utils/logger.js';

const logger = createLogger('server');

// Validate configuration
try {
  validateConfig();
} catch (error) {
  logger.error('Configuration error:', error.message);
  logger.error('Please check your .env file and ensure all required variables are set.');
  process.exit(1);
}

const app = express();

// Security middleware
app.use(helmet({
  contentSecurityPolicy: false // Disable CSP for API
}));

// Rate limiting
const limiter = rateLimit({
  windowMs: 1 * 60 * 1000, // 1 minute
  max: 100, // 100 requests per minute
  message: { error: 'Too many requests, please try again later' },
  standardHeaders: true,
  legacyHeaders: false
});
app.use(limiter);

// Trust proxy (for deployment behind load balancer)
app.set('trust proxy', 1);

// IMPORTANT: Webhooks need raw body for HMAC verification
// Use express.raw() for webhook routes
app.use('/webhooks', express.raw({ 
  type: 'application/json',
  limit: '5mb'
}));

// Regular JSON parsing for other routes
app.use(express.json({ limit: '1mb' }));

// Health check endpoint
app.get('/health', (req, res) => {
  res.json({ 
    status: 'ok',
    timestamp: new Date().toISOString(),
    environment: config.nodeEnv
  });
});

// Root endpoint
app.get('/', (req, res) => {
  res.json({ 
    name: 'LAAR-Shopify Integration',
    version: '1.0.0',
    healthCheck: '/health'
  });
});

// Webhook routes
app.use('/webhooks', webhooksRouter);

// Carrier Service rates endpoint (Shopify calls this for dynamic shipping rates)
app.use('/carrier-service/rates', carrierServiceRouter);

// Auth routes (OAuth flow)
app.use('/auth', authRouter);

// Token status endpoint
app.get('/token-status', (req, res) => {
  const shop = config.shopify.storeDomain;
  const hasToken = tokenStorage.hasToken(shop);
  const tokenData = tokenStorage.getTokenData(shop);
  
  res.json({
    shop,
    authenticated: hasToken,
    installedAt: tokenData?.installedAt || null,
    message: hasToken 
      ? 'App is authenticated and ready' 
      : `Please authenticate at /auth?shop=${shop}`
  });
});

// Manual CarrierService registration endpoint
app.post('/register-carrier', async (req, res) => {
  try {
    const { shopifyService } = await import('./services/shopifyService.js');
    const result = await shopifyService.registerCarrierService();
    res.json({ success: true, carrierService: result });
  } catch (error) {
    res.status(500).json({ 
      success: false, 
      error: error.message,
      details: error.response?.data || null
    });
  }
});

// 404 handler
app.use((req, res) => {
  res.status(404).json({ error: 'Not found' });
});

// Global error handler
app.use((err, req, res, next) => {
  logger.error('Unhandled error:', err);
  res.status(500).json({ 
    error: 'Internal server error',
    message: config.isDevelopment ? err.message : undefined
  });
});

// Start server
const PORT = config.port;

app.listen(PORT, () => {
  logger.info(`🚀 Server running on port ${PORT}`);
  logger.info(`   Environment: ${config.nodeEnv}`);
  logger.info(`   Health check: http://localhost:${PORT}/health`);
  logger.info(`   Webhook endpoint: http://localhost:${PORT}/webhooks/orders_paid`);
  logger.info(`   Carrier Service: http://localhost:${PORT}/carrier-service/rates`);
  logger.info(`   Auth endpoint: http://localhost:${PORT}/auth?shop=${config.shopify.storeDomain}`);
  
  // Check if store is already authenticated
  const hasToken = tokenStorage.hasToken(config.shopify.storeDomain);
  if (hasToken) {
    logger.info(`✅ Store ${config.shopify.storeDomain} is authenticated`);
  } else {
    logger.warn(`⚠️  Store not authenticated. Visit: ${config.shopify.appUrl}/auth?shop=${config.shopify.storeDomain}`);
  }
  
  if (config.isDevelopment && config.allowInsecureWebhooks) {
    logger.warn('⚠️  INSECURE MODE: HMAC verification is disabled!');
    logger.warn('   This should NEVER be enabled in production.');
  }
});

export default app;
