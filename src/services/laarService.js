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
    this.citiesCache = null;
    this.citiesCacheExpiry = null;
    
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
      const response = await this.client.post('/api/Login/authenticate', {
        username: config.laar.username,
        password: config.laar.password
      });
      
      const token = response.data.token;
      
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
   * Get list of cities from LAAR API
   * Results are cached in memory
   */
  async getCities() {
    if (this.citiesCache && this.citiesCacheExpiry > Date.now()) {
      return this.citiesCache;
    }
    
    const token = await this.getToken();
    
    try {
      logger.info('Fetching cities from LAAR API...');
      const response = await this.client.get('/api/Ciudades/v1/ciudades', {
        headers: { 'Authorization': `Bearer ${token}` }
      });
      
      this.citiesCache = response.data;
      this.citiesCacheExpiry = Date.now() + (60 * 60 * 1000); // Cache for 1 hour
      
      logger.info(`Loaded ${Array.isArray(response.data) ? response.data.length : 'unknown'} cities from LAAR`);
      return this.citiesCache;
    } catch (error) {
      logger.error('Failed to fetch LAAR cities:', error.response?.data || error.message);
      throw new Error(`Failed to fetch LAAR cities: ${error.message}`);
    }
  }
  
  /**
   * Find city code by name
   * @param {string} cityName - City name from Shopify (e.g., "Guayaquil")
   * @param {string} provinceName - Province name (optional)
   * @returns {string} - LAAR city code
   */
  async findCityCode(cityName, provinceName = '') {
    const cities = await this.getCities();
    
    if (!Array.isArray(cities)) {
      logger.error('Cities response is not an array:', cities);
      throw new Error('Invalid cities data from LAAR API');
    }
    
    // Normalize search terms
    const normalizedCity = cityName.toLowerCase().trim();
    const normalizedProvince = provinceName.toLowerCase().trim();
    
    // Try to find exact match first
    let match = cities.find(c => {
      const laarCity = (c.nombre || '').toLowerCase();
      return laarCity === normalizedCity;
    });
    
    // If no exact match, try partial match
    if (!match) {
      match = cities.find(c => {
        const laarCity = (c.nombre || '').toLowerCase();
        return laarCity.includes(normalizedCity) || normalizedCity.includes(laarCity);
      });
    }
    
    // If still no match and we have province, try with province
    if (!match && normalizedProvince) {
      match = cities.find(c => {
        const laarCity = (c.nombre || '').toLowerCase();
        const laarProv = (c.provincia || '').toLowerCase();
        return laarCity.includes(normalizedCity) && laarProv.includes(normalizedProvince);
      });
    }
    
    if (!match) {
      logger.error(`City not found in LAAR: ${cityName}, Province: ${provinceName}`);
      logger.info('Available cities sample:', cities.slice(0, 5));
      throw new Error(`City "${cityName}" not found in LAAR system. Please verify the city name.`);
    }
    
    const cityCode = match.codigo;
    logger.info(`Found LAAR city: ${cityName} -> Code: ${cityCode}`);
    return String(cityCode);
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
      
      const response = await this.client.post('/api/Guias/v1/guias/contado?isRetorno=false', guideData, {
        headers: {
          'Authorization': `Bearer ${token}`
        }
      });
      
      const result = response.data;
      
      // Response: { guia: "SISLC...", url: "https://...", zpl: null }
      const guideNumber = result.guia;
      const pdfUrl = result.url;
      
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
   * @returns {Promise<Object>} - LAAR guide payload
   */
  async buildGuidePayload(order) {
    const shipping = order.shipping_address || order.shippingAddress || {};
    const billing = order.billing_address || {};
    const customer = order.customer || {};
    const lineItems = order.line_items || order.lineItems || [];
    const noteAttributes = order.note_attributes || [];
    
    // Build SKU summary from line items
    const skuSummary = lineItems
      .map(item => `${item.sku || item.title || 'N/A'} x${item.quantity}`)
      .join(', ')
      .substring(0, 200); // Limit length
    
    // Validate required shipping data
    if (!shipping.city) {
      throw new Error('Missing shipping city in order');
    }
    if (!shipping.address1) {
      throw new Error('Missing shipping address in order');
    }
    
    // Get customer name - required
    const customerName = shipping.name || `${shipping.first_name || ''} ${shipping.last_name || ''}`.trim();
    if (!customerName) {
      throw new Error('Missing customer name in order');
    }
    
    // Get phone from shipping address or customer - required
    const rawPhone = shipping.phone || customer.phone || '';
    const phone = rawPhone.replace(/[^0-9]/g, '').substring(0, 10);
    if (!phone || phone.length < 7) {
      throw new Error(`Missing or invalid phone number in order. Raw phone: "${rawPhone}"`);
    }
    
    // Get customer identification (cédula/RUC) - OPCIONAL según LAAR
    // Está almacenada en las notas del pedido
    let identificacion = '';
    
    // 1. Check note_attributes (custom checkout fields)
    const cedulaAttr = noteAttributes.find(attr => 
      attr.name.toLowerCase().includes('cedula') || 
      attr.name.toLowerCase().includes('cédula') ||
      attr.name.toLowerCase().includes('identificacion') ||
      attr.name.toLowerCase().includes('ruc') ||
      attr.name.toLowerCase().includes('ci')
    );
    if (cedulaAttr?.value) {
      identificacion = cedulaAttr.value.replace(/[^0-9]/g, '');
    }
    
    // 2. Check order notes (campo de notas del pedido)
    if (!identificacion && order.note) {
      // Primero: buscar con label (ej: "cedula: 1712345678")
      const cedulaMatch = order.note.match(/(?:cedula|cédula|ruc|ci)[:\s]*([0-9]{10,13})/i);
      if (cedulaMatch) {
        identificacion = cedulaMatch[1];
      } else {
        // Segundo: buscar un número de 10-13 dígitos directamente en la nota
        const numberMatch = order.note.match(/\b([0-9]{10,13})\b/);
        if (numberMatch) {
          identificacion = numberMatch[1];
        }
      }
    }
    
    if (!identificacion) {
      logger.warn('No se encontró cédula/RUC en las notas del pedido. Continuando sin identificación.');
    }
    
    // Build full address
    const address1 = shipping.address1;
    const address2 = shipping.address2 || '';
    const fullAddress = `${address1} ${address2}`.trim();
    
    // Get reference from company or address2 (NOT order.note to avoid leaking cédula into referencia)
    const reference = shipping.company || shipping.address2 || '';
    
    // Calculate total pieces (sum of all item quantities)
    const totalPieces = lineItems.reduce((sum, item) => sum + (item.quantity || 1), 0);
    
    // Calculate total weight in kg (Shopify sends in grams)
    const totalWeightGrams = order.total_weight || lineItems.reduce((sum, item) => {
      return sum + ((item.grams || 0) * (item.quantity || 1));
    }, 0);
    const totalWeightKg = Math.max(1, Math.ceil(totalWeightGrams / 1000)); // Minimum 1 kg
    
    // Build contents description from line items
    const contenido = lineItems
      .map(item => item.title || item.name || 'Producto')
      .join(', ')
      .substring(0, 200);
    
    // Get city code from LAAR API
    const cityName = shipping.city;
    const provinceName = shipping.province || '';
    logger.info(`Looking up LAAR city code for: ${cityName}, Province: ${provinceName}`);
    
    const cityCode = await this.findCityCode(cityName, provinceName);
    
    logger.info('Building guide with customer data:', {
      customerName,
      identificacion: identificacion || 'N/A',
      phone,
      city: cityName,
      cityCode,
      address: fullAddress,
      pieces: totalPieces,
      weightKg: totalWeightKg
    });
    
    const payload = {
      // Origin data (from config) - this is YOUR store's address
      origen: {
        identificacionO: config.defaults.origin.identificacionO,
        ciudadO: config.defaults.origin.ciudadO,
        nombreO: config.defaults.origin.nombreO,
        direccion: config.defaults.origin.direccionO,
        referencia: config.defaults.origin.referenciaO,
        numeroCasa: '',
        postal: '',
        telefono: config.defaults.origin.telefonoO,
        celular: config.defaults.origin.celularO,
        correo: config.defaults.origin.correoO
      },
      
      // Destination data (from customer order)
      destino: {
        identificacionD: identificacion,
        ciudadD: cityCode,
        nombreD: customerName,
        direccion: fullAddress,
        referencia: reference.substring(0, 225),
        numeroCasa: '',
        postal: shipping.zip || '',
        telefono: phone,
        celular: phone,
        categoria: '',
        latitud: shipping.latitude ? String(shipping.latitude) : '',
        longitud: shipping.longitude ? String(shipping.longitude) : ''
      },
      
      // Guide details
      numeroGuia: '',
      tipoServicio: config.defaults.serviceCode,
      noPiezas: totalPieces,
      peso: totalWeightKg,
      valorDeclarado: 0,
      contiene: contenido || 'Pedido Shopify',
      tamanio: '',
      cod: false,
      costoflete: 0,
      costoproducto: 0,
      tipocobro: 0,
      comentario: `Shopify Order #${order.name || order.order_number || order.id}`,
      fechaPedido: '',
      
      // Retorno (no aplica)
      retorno: {
        tipoServicio: '',
        noPiezas: 0,
        peso: 0,
        contiene: '',
        comentario: '',
        tamanio: ''
      },
      
      // Extra fields for tracking
      extras: {
        campo1: String(order.id),
        campo2: order.name || `#${order.order_number}`,
        campo3: skuSummary
      }
    };
    
    return payload;
  }
}

// Export singleton instance
export const laarService = new LaarService();
export default laarService;
