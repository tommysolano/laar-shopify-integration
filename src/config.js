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
    adminToken: process.env.SHOPIFY_ADMIN_TOKEN,
    webhookSecret: process.env.SHOPIFY_WEBHOOK_SECRET,
    apiVersion: '2025-10'
  },
  
  // LAAR Courier
  laar: {
    baseUrl: process.env.LAAR_BASE_URL || 'https://api.laarcourier.com:9727',
    username: process.env.LAAR_USERNAME,
    password: process.env.LAAR_PASSWORD,
    tokenExpirationMinutes: parseInt(process.env.LAAR_TOKEN_EXPIRATION_MINUTES || '55', 10)
  },
  
  // Default values for shipping
  defaults: {
    originCityCode: process.env.DEFAULT_ORIGIN_CITY_CODE || '201001001001',
    serviceCode: process.env.DEFAULT_SERVICE_CODE || '201202002002013',
    // Origin data (can be customized via env)
    origin: {
      identificacionO: process.env.ORIGIN_IDENTIFICACION || '0999999999',
      nombreO: process.env.ORIGIN_NOMBRE || 'Mi Tienda',
      direccionO: process.env.ORIGIN_DIRECCION || 'Dirección de origen',
      referenciaO: process.env.ORIGIN_REFERENCIA || '',
      ciudadO: process.env.ORIGIN_CIUDAD || process.env.DEFAULT_ORIGIN_CITY_CODE || '201001001001',
      telefonoO: process.env.ORIGIN_TELEFONO || '0999999999',
      celularO: process.env.ORIGIN_CELULAR || '0999999999',
      correoO: process.env.ORIGIN_CORREO || 'tienda@example.com'
    }
  }
};

/**
 * Validate required configuration
 */
export function validateConfig() {
  const required = [
    { key: 'SHOPIFY_STORE_DOMAIN', value: config.shopify.storeDomain },
    { key: 'SHOPIFY_ADMIN_TOKEN', value: config.shopify.adminToken },
    { key: 'SHOPIFY_WEBHOOK_SECRET', value: config.shopify.webhookSecret },
    { key: 'LAAR_USERNAME', value: config.laar.username },
    { key: 'LAAR_PASSWORD', value: config.laar.password }
  ];
  
  const missing = required.filter(r => !r.value).map(r => r.key);
  
  if (missing.length > 0) {
    throw new Error(`Missing required environment variables: ${missing.join(', ')}`);
  }
}

export default config;
