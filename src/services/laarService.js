import axios from 'axios';
import config from '../config.js';
import { createLogger } from '../utils/logger.js';

const logger = createLogger('laar-service');

/**
 * LAAR Courier Service
 * Handles authentication and guide creation
 */
class LaarService {
  constructor() {
    this.baseUrl = config.laar.baseUrl;
    this.token = null;
    this.tokenExpiry = null;
    
    // Create axios instance with defaults
    this.client = axios.create({
      baseURL: this.baseUrl,
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json'
      }
    });
  }
  
  /**
   * Check if current token is still valid
   */
  isTokenValid() {
    if (!this.token || !this.tokenExpiry) {
      return false;
    }
    // Token is valid if we have more than 1 minute before expiry
    return Date.now() < this.tokenExpiry - 60000;
  }
  
  /**
   * Authenticate with LAAR API and get token
   */
  async authenticate() {
    logger.info('Authenticating with LAAR API...');
    
    try {
      const response = await this.client.post('/authenticate', {
        username: config.laar.username,
        password: config.laar.password
      });
      
      // LAAR returns the token in the response
      // Adjust based on actual API response structure
      const token = response.data.token || response.data.access_token || response.data;
      
      if (!token || typeof token !== 'string') {
        throw new Error('Invalid token received from LAAR API');
      }
      
      this.token = token;
      // Set expiry based on configured minutes
      this.tokenExpiry = Date.now() + (config.laar.tokenExpirationMinutes * 60 * 1000);
      
      logger.info('Successfully authenticated with LAAR API');
      return this.token;
    } catch (error) {
      logger.error('LAAR authentication failed:', error.response?.data || error.message);
      throw new Error(`LAAR authentication failed: ${error.message}`);
    }
  }
  
  /**
   * Get valid token, authenticating if necessary
   */
  async getToken() {
    if (!this.isTokenValid()) {
      await this.authenticate();
    }
    return this.token;
  }
  
  /**
   * Clear stored token (used for retry on 401)
   */
  clearToken() {
    this.token = null;
    this.tokenExpiry = null;
  }
  
  /**
   * Create shipping guide (guia) in LAAR
   * 
   * @param {Object} guideData - Guide creation payload
   * @param {boolean} isRetry - Whether this is a retry after 401
   * @returns {Object} - Created guide with number and PDF URL
   */
  async createGuide(guideData, isRetry = false) {
    const token = await this.getToken();
    
    try {
      logger.info('Creating LAAR guide...', { orderId: guideData.extras?.Campo1 });
      
      const response = await this.client.post('/guias/contado', guideData, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      
      const result = response.data;
      
      // Extract guide number and PDF URL from response
      // Adjust based on actual LAAR API response structure
      const guideNumber = result.numeroGuia || result.guia || result.numero;
      const pdfUrl = result.urlPdf || result.pdfUrl || result.url_pdf;
      
      if (!guideNumber) {
        logger.error('LAAR response missing guide number:', result);
        throw new Error('LAAR response missing guide number');
      }
      
      logger.info('LAAR guide created successfully', { 
        guideNumber, 
        orderId: guideData.extras?.Campo1 
      });
      
      return {
        guia: guideNumber,
        pdfUrl: pdfUrl || null,
        rawResponse: result
      };
    } catch (error) {
      // Handle 401 - token expired, retry once
      if (error.response?.status === 401 && !isRetry) {
        logger.warn('LAAR token expired, re-authenticating and retrying...');
        this.clearToken();
        return this.createGuide(guideData, true);
      }
      
      logger.error('LAAR guide creation failed:', {
        status: error.response?.status,
        data: JSON.stringify(error.response?.data),
        message: error.message
      });
      
      // Log the full error data for debugging
      if (error.response?.data) {
        logger.error('LAAR error details: ' + JSON.stringify(error.response.data));
      }
      
      throw new Error(`LAAR guide creation failed: ${error.response?.data?.message || JSON.stringify(error.response?.data) || error.message}`);
    }
  }
  
  /**
   * Build guide payload from Shopify order
   * 
   * @param {Object} order - Shopify order object
   * @returns {Object} - LAAR guide payload
   */
  buildGuidePayload(order) {
    const shipping = order.shipping_address || order.shippingAddress || {};
    const customer = order.customer || {};
    const lineItems = order.line_items || order.lineItems || [];
    
    // Build SKU summary from line items
    const skuSummary = lineItems
      .map(item => `${item.sku || item.title || 'N/A'} x${item.quantity}`)
      .join(', ')
      .substring(0, 200); // Limit length
    
    // Get phone from shipping address or customer
    const rawPhone = shipping.phone || customer.phone || '';
    const phone = rawPhone.replace(/[^0-9]/g, '').substring(0, 10);
    
    if (!phone || phone.length < 7) {
      throw new Error(`Missing or invalid phone number in order. Raw phone: "${rawPhone}"`);
    }
    
    // Build full address
    const address1 = shipping.address1 || '';
    const address2 = shipping.address2 || '';
    const fullAddress = `${address1} ${address2}`.trim() || 'Sin dirección';
    
    // Get reference from company or order note
    const reference = shipping.company || order.note || '';
    
    // Calculate total value
    const totalPrice = parseFloat(order.current_total_price || order.total_price || 0);
    
    const payload = {
      // Origin data (from config) - field names per LAAR API docs
      origen: {
        identificacionO: config.defaults.origin.identificacionO,
        ciudadO: config.defaults.origin.ciudadO,
        nombreO: config.defaults.origin.nombreO,
        direccion: config.defaults.origin.direccionO,
        referencia: config.defaults.origin.referenciaO,
        numeroCasa: '',
        postal: '',
        telefono: config.defaults.origin.telefonoO,
        celular: config.defaults.origin.celularO
      },
      
      // Destination data (from order) - field names per LAAR API docs
      destino: {
        identificacionD: '9999999999', // Default if not provided
        ciudadD: config.defaults.originCityCode, // TODO: Map Shopify locations to LAAR city codes
        nombreD: shipping.name || `${shipping.first_name || ''} ${shipping.last_name || ''}`.trim() || 'Cliente',
        direccion: fullAddress,
        referencia: reference.substring(0, 200),
        numeroCasa: '',
        postal: shipping.zip || '',
        telefono: phone,
        celular: phone,
        latitud: shipping.latitude ? String(shipping.latitude) : '',
        longitud: shipping.longitude ? String(shipping.longitude) : ''
      },
      
      // Guide details
      numeroGuia: '', // LAAR generates this
      tipoServicio: config.defaults.serviceCode,
      noPiezas: 1,
      peso: 1, // TODO: Calculate from order items
      valorDeclarado: totalPrice,
      contiene: 'Pedido Shopify',
      comentario: `Shopify Order #${order.name || order.order_number || order.id}`,
      
      // Extra fields for tracking
      extras: {
        Campo1: String(order.id),
        Campo2: order.name || `#${order.order_number}`,
        Campo3: skuSummary
      }
    };
    
    return payload;
  }
}

// Export singleton instance
export const laarService = new LaarService();
export default laarService;
