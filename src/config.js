/**
 * Application configuration loaded from environment variables
 */

const config = {
  // Server
  port: process.env.PORT || 3000,
  nodeEnv: process.env.NODE_ENV || 'development',
  isDevelopment: process.env.NODE_ENV === 'development',
  
  // Security
  allowInsecureWebhooks: process.env.ALLOW_INSECURE_WEBHOOKS === 'true',
  
  // Shopify
  shopify: {
    storeDomain: process.env.SHOPIFY_STORE_DOMAIN,
    adminToken: process.env.SHOPIFY_ADMIN_TOKEN, // Optional: fallback for OAuth token
    webhookSecret: process.env.SHOPIFY_WEBHOOK_SECRET,
    apiVersion: '2025-10',
    // OAuth credentials (from Dev Dashboard / Partner)
    clientId: process.env.SHOPIFY_CLIENT_ID,
    clientSecret: process.env.SHOPIFY_CLIENT_SECRET,
    appUrl: process.env.APP_URL || 'https://laar-shopify-integration.onrender.com'
  },
  
  // LAAR Courier
  laar: {
    baseUrl: process.env.LAAR_BASE_URL || 'https://api.laarcourier.com:9747',
    username: process.env.LAAR_USERNAME,
    password: process.env.LAAR_PASSWORD,
    tokenExpirationMinutes: parseInt(process.env.LAAR_TOKEN_EXPIRATION_MINUTES || '120', 10)
  },
  
  // Datos de origen (remitente) - SIN valores por defecto, deben configurarse en .env
  defaults: {
    serviceCode: process.env.DEFAULT_SERVICE_CODE,
    origin: {
      identificacionO: process.env.ORIGIN_IDENTIFICACION,
      nombreO: process.env.ORIGIN_NOMBRE,
      direccionO: process.env.ORIGIN_DIRECCION,
      referenciaO: process.env.ORIGIN_REFERENCIA || '',
      ciudadO: process.env.ORIGIN_CIUDAD,
      telefonoO: process.env.ORIGIN_TELEFONO,
      celularO: process.env.ORIGIN_CELULAR,
      correoO: process.env.ORIGIN_CORREO || ''
    }
  }
};

/**
 * Validate required configuration
 */
export function validateConfig() {
  const required = [
    { key: 'SHOPIFY_STORE_DOMAIN', value: config.shopify.storeDomain },
    { key: 'SHOPIFY_CLIENT_ID', value: config.shopify.clientId },
    { key: 'SHOPIFY_CLIENT_SECRET', value: config.shopify.clientSecret },
    { key: 'SHOPIFY_WEBHOOK_SECRET', value: config.shopify.webhookSecret },
    { key: 'LAAR_USERNAME', value: config.laar.username },
    { key: 'LAAR_PASSWORD', value: config.laar.password },
    { key: 'DEFAULT_SERVICE_CODE', value: config.defaults.serviceCode },
    { key: 'ORIGIN_IDENTIFICACION', value: config.defaults.origin.identificacionO },
    { key: 'ORIGIN_NOMBRE', value: config.defaults.origin.nombreO },
    { key: 'ORIGIN_DIRECCION', value: config.defaults.origin.direccionO },
    { key: 'ORIGIN_CIUDAD', value: config.defaults.origin.ciudadO },
    { key: 'ORIGIN_TELEFONO', value: config.defaults.origin.telefonoO },
    { key: 'ORIGIN_CELULAR', value: config.defaults.origin.celularO }
  ];
  
  const missing = required.filter(r => !r.value).map(r => r.key);
  
  if (missing.length > 0) {
    throw new Error(`Missing required environment variables: ${missing.join(', ')}`);
  }
}

export default config;
