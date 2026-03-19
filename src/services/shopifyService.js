import axios from 'axios';
import config from '../config.js';
import { tokenStorage } from './tokenStorage.js';
import { createLogger } from '../utils/logger.js';

const logger = createLogger('shopify-service');

/**
 * Shopify Admin API Service
 * Uses GraphQL for metafields and fulfillment operations
 * 
 * Token resolution order:
 * 1. OAuth token from tokenStorage (obtained via /auth flow)
 * 2. Fallback to SHOPIFY_ADMIN_TOKEN env var (for backwards compatibility)
 */
class ShopifyService {
  constructor() {
    this.storeDomain = config.shopify.storeDomain;
    this.apiVersion = config.shopify.apiVersion;
    
    // GraphQL endpoint
    this.graphqlUrl = `https://${this.storeDomain}/admin/api/${this.apiVersion}/graphql.json`;
    
    // REST endpoint base
    this.restBaseUrl = `https://${this.storeDomain}/admin/api/${this.apiVersion}`;
    
    // Create axios instance (token will be set per-request)
    this.client = axios.create({
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json'
      }
    });
  }
  
  /**
   * Get the access token for the configured store
   * Checks OAuth storage first, then falls back to env var
   * @returns {string} - Access token
   * @throws {Error} - If no token is available
   */
  getAccessToken() {
    // Try OAuth token first
    const oauthToken = tokenStorage.getToken(this.storeDomain);
    if (oauthToken) {
      return oauthToken;
    }
    
    // Fallback to env var token
    if (config.shopify.adminToken) {
      return config.shopify.adminToken;
    }
    
    throw new Error(
      `No access token available for ${this.storeDomain}. ` +
      `Please authenticate at /auth?shop=${this.storeDomain}`
    );
  }
  
  /**
   * Check if the store has a valid token
   * @returns {boolean}
   */
  hasValidToken() {
    return tokenStorage.hasToken(this.storeDomain) || !!config.shopify.adminToken;
  }
  
  /**
   * Execute GraphQL query
   */
  async graphql(query, variables = {}) {
    const token = this.getAccessToken();
    
    try {
      const response = await this.client.post(this.graphqlUrl, {
        query,
        variables
      }, {
        headers: {
          'X-Shopify-Access-Token': token
        }
      });
      
      if (response.data.errors) {
        logger.error('GraphQL errors:', response.data.errors);
        throw new Error(`GraphQL error: ${response.data.errors[0]?.message}`);
      }
      
      return response.data.data;
    } catch (error) {
      const errorDetails = {
        status: error.response?.status,
        statusText: error.response?.statusText,
        data: error.response?.data,
        message: error.message
      };
      logger.error('GraphQL request failed:', errorDetails);
      throw error;
    }
  }
  
  /**
   * Convert numeric order ID to GraphQL GID
   */
  toOrderGid(orderId) {
    if (String(orderId).startsWith('gid://')) {
      return orderId;
    }
    return `gid://shopify/Order/${orderId}`;
  }
  
  /**
   * Check if order already has LAAR guide metafield
   * 
   * @param {string|number} orderId - Order ID
   * @returns {Object|null} - Existing metafield data or null
   */
  async getOrderLaarMetafields(orderId) {
    const gid = this.toOrderGid(orderId);
    
    const query = `
      query getOrderMetafields($id: ID!) {
        order(id: $id) {
          id
          name
          metafield(namespace: "laar", key: "guia") {
            id
            value
          }
          metafieldPdfUrl: metafield(namespace: "laar", key: "pdf_url") {
            id
            value
          }
          metafieldLabelUrl: metafield(namespace: "laar", key: "label_url") {
            id
            value
          }
        }
      }
    `;
    
    try {
      const data = await this.graphql(query, { id: gid });
      const order = data.order;
      
      if (!order) {
        logger.warn('Order not found:', orderId);
        return null;
      }
      
      if (order.metafield?.value) {
        return {
          guia: order.metafield.value,
          pdfUrl: order.metafieldPdfUrl?.value || null,
          labelUrl: order.metafieldLabelUrl?.value || null,
          exists: true
        };
      }
      
      return { exists: false };
    } catch (error) {
      logger.error('Failed to get order metafields:', error.message);
      throw error;
    }
  }
  
  /**
   * Save LAAR guide data to order metafields
   * 
   * @param {string|number} orderId - Order ID
   * @param {string} guia - Guide number
   * @param {string} pdfUrl - PDF URL
   * @param {string} labelUrl - Label proxy URL
   * @param {number|null} shippingCost - Real LAAR shipping cost (when free shipping applied)
   */
  async saveOrderMetafields(orderId, guia, pdfUrl, labelUrl, shippingCost = null) {
    const gid = this.toOrderGid(orderId);
    
    const mutation = `
      mutation setOrderMetafields($input: [MetafieldsSetInput!]!) {
        metafieldsSet(metafields: $input) {
          metafields {
            id
            namespace
            key
            value
          }
          userErrors {
            field
            message
          }
        }
      }
    `;
    
    const metafields = [
      {
        ownerId: gid,
        namespace: 'laar',
        key: 'guia',
        type: 'single_line_text_field',
        value: String(guia)
      }
    ];
    
    if (pdfUrl) {
      metafields.push({
        ownerId: gid,
        namespace: 'laar',
        key: 'pdf_url',
        type: 'url',
        value: pdfUrl
      });
    }
    
    if (labelUrl) {
      metafields.push({
        ownerId: gid,
        namespace: 'laar',
        key: 'label_url',
        type: 'url',
        value: labelUrl
      });
    }
    
    if (shippingCost !== null && shippingCost !== undefined) {
      metafields.push({
        ownerId: gid,
        namespace: 'laar',
        key: 'costo_envio',
        type: 'number_decimal',
        value: String(shippingCost)
      });
    }
    
    try {
      const data = await this.graphql(mutation, { input: metafields });
      
      if (data.metafieldsSet.userErrors.length > 0) {
        logger.error('Metafield save errors:', data.metafieldsSet.userErrors);
        throw new Error(`Failed to save metafields: ${data.metafieldsSet.userErrors[0].message}`);
      }
      
      logger.info('Order metafields saved successfully', { orderId, guia });
      return data.metafieldsSet.metafields;
    } catch (error) {
      logger.error('Failed to save order metafields:', error.message);
      throw error;
    }
  }
  
  /**
   * Get fulfillment orders for an order
   * 
   * @param {string|number} orderId - Order ID
   * @returns {Array} - Fulfillment orders
   */
  async getFulfillmentOrders(orderId) {
    const gid = this.toOrderGid(orderId);
    
    const query = `
      query getFulfillmentOrders($id: ID!) {
        order(id: $id) {
          id
          fulfillmentOrders(first: 50) {
            nodes {
              id
              status
              lineItems(first: 50) {
                nodes {
                  id
                  remainingQuantity
                }
              }
            }
          }
        }
      }
    `;
    
    try {
      const data = await this.graphql(query, { id: gid });
      return data.order?.fulfillmentOrders?.nodes || [];
    } catch (error) {
      logger.error('Failed to get fulfillment orders:', error.message);
      throw error;
    }
  }
  
  /**
   * Create fulfillment with tracking info
   * 
   * @param {string|number} orderId - Order ID  
   * @param {string} trackingNumber - Tracking number (LAAR guide)
   * @param {string} trackingUrl - Tracking URL (PDF or tracking page)
   * @returns {Object} - Created fulfillment
   */
  async createFulfillment(orderId, trackingNumber, trackingUrl) {
    logger.info('Creating fulfillment...', { orderId, trackingNumber });
    
    // Get fulfillment orders first
    const fulfillmentOrders = await this.getFulfillmentOrders(orderId);
    
    // Filter to only open/in-progress fulfillment orders
    const openFulfillmentOrders = fulfillmentOrders.filter(fo => 
      ['OPEN', 'IN_PROGRESS'].includes(fo.status) &&
      fo.lineItems.nodes.some(li => li.remainingQuantity > 0)
    );
    
    if (openFulfillmentOrders.length === 0) {
      logger.warn('No open fulfillment orders found', { orderId });
      return null;
    }
    
    // Build line items by fulfillment order
    const lineItemsByFulfillmentOrder = openFulfillmentOrders.map(fo => ({
      fulfillmentOrderId: fo.id,
      fulfillmentOrderLineItems: fo.lineItems.nodes
        .filter(li => li.remainingQuantity > 0)
        .map(li => ({
          id: li.id,
          quantity: li.remainingQuantity
        }))
    }));
    
    const mutation = `
      mutation fulfillmentCreateV2($fulfillment: FulfillmentV2Input!) {
        fulfillmentCreateV2(fulfillment: $fulfillment) {
          fulfillment {
            id
            status
            trackingInfo {
              number
              url
            }
          }
          userErrors {
            field
            message
          }
        }
      }
    `;
    
    const fulfillmentInput = {
      lineItemsByFulfillmentOrder,
      notifyCustomer: true,
      trackingInfo: {
        number: trackingNumber,
        url: trackingUrl || undefined
      }
    };
    
    try {
      const data = await this.graphql(mutation, { fulfillment: fulfillmentInput });
      
      if (data.fulfillmentCreateV2.userErrors.length > 0) {
        const errors = data.fulfillmentCreateV2.userErrors;
        logger.error('Fulfillment creation errors:', errors);
        
        // Check if it's just because items are already fulfilled
        const alreadyFulfilled = errors.some(e => 
          e.message.toLowerCase().includes('already fulfilled') ||
          e.message.toLowerCase().includes('no fulfillable')
        );
        
        if (alreadyFulfilled) {
          logger.warn('Items already fulfilled or no fulfillable items', { orderId });
          return null;
        }
        
        throw new Error(`Failed to create fulfillment: ${errors[0].message}`);
      }
      
      logger.info('Fulfillment created successfully', { 
        orderId, 
        fulfillmentId: data.fulfillmentCreateV2.fulfillment?.id 
      });
      
      return data.fulfillmentCreateV2.fulfillment;
    } catch (error) {
      logger.error('Failed to create fulfillment:', error.message);
      throw error;
    }
  }
  
  /**
   * Add tags to an order (fallback/additional option)
   * 
   * @param {string|number} orderId - Order ID
   * @param {Array<string>} tags - Tags to add
   */
  async addOrderTags(orderId, tags) {
    const gid = this.toOrderGid(orderId);
    
    const mutation = `
      mutation addTags($id: ID!, $tags: [String!]!) {
        tagsAdd(id: $id, tags: $tags) {
          node {
            ... on Order {
              id
              tags
            }
          }
          userErrors {
            field
            message
          }
        }
      }
    `;
    
    try {
      const data = await this.graphql(mutation, { id: gid, tags });
      
      if (data.tagsAdd.userErrors.length > 0) {
        logger.warn('Tag add errors:', data.tagsAdd.userErrors);
      }
      
      return data.tagsAdd.node;
    } catch (error) {
      logger.error('Failed to add order tags:', error.message);
      // Don't throw - tags are not critical
    }
  }

  /**
   * Create metafield definitions so they are visible and pinned in Shopify admin order page
   */
  async createLabelMetafieldDefinitions() {
    const definitions = [
      {
        name: 'Etiqueta LAAR',
        namespace: 'laar',
        key: 'label_url',
        type: 'url',
        ownerType: 'ORDER',
        pin: true
      },
      {
        name: 'Guía LAAR',
        namespace: 'laar',
        key: 'guia',
        type: 'single_line_text_field',
        ownerType: 'ORDER',
        pin: true
      }
    ];

    const mutation = `
      mutation createMetafieldDefinition($definition: MetafieldDefinitionInput!) {
        metafieldDefinitionCreate(definition: $definition) {
          createdDefinition {
            id
            name
            namespace
            key
          }
          userErrors {
            field
            message
          }
        }
      }
    `;

    const results = [];
    for (const def of definitions) {
      try {
        const data = await this.graphql(mutation, {
          definition: {
            name: def.name,
            namespace: def.namespace,
            key: def.key,
            type: def.type,
            ownerType: def.ownerType,
            pin: def.pin
          }
        });

        const errors = data.metafieldDefinitionCreate.userErrors;
        if (errors.length > 0) {
          // "already exists" is fine
          logger.warn(`Metafield definition ${def.key}:`, errors[0].message);
          results.push({ key: def.key, status: 'exists', message: errors[0].message });
        } else {
          logger.info(`Metafield definition created: ${def.key}`);
          results.push({ key: def.key, status: 'created', id: data.metafieldDefinitionCreate.createdDefinition.id });
        }
      } catch (error) {
        logger.error(`Failed to create metafield definition ${def.key}:`, error.message);
        results.push({ key: def.key, status: 'error', message: error.message });
      }
    }
    return results;
  }

  /**
   * Register a CarrierService with Shopify for dynamic shipping rates
   * This tells Shopify to call our /carrier-service/rates endpoint at checkout
   */
  async registerCarrierService() {
    const token = this.getAccessToken();
    const callbackUrl = `${config.shopify.appUrl}/carrier-service/rates`;

    // First check if carrier service already exists
    try {
      const listResponse = await this.client.get(
        `${this.restBaseUrl}/carrier_services.json`,
        { headers: { 'X-Shopify-Access-Token': token } }
      );

      const existing = (listResponse.data.carrier_services || []).find(
        cs => cs.callback_url === callbackUrl
      );

      if (existing) {
        logger.info('CarrierService already registered', { id: existing.id, callbackUrl });
        return existing;
      }
    } catch (error) {
      logger.warn('Could not list carrier services:', error.message);
    }

    // Register new carrier service
    const response = await this.client.post(
      `${this.restBaseUrl}/carrier_services.json`,
      {
        carrier_service: {
          name: 'LAAR Courier',
          callback_url: callbackUrl,
          service_discovery: true,
          format: 'json'
        }
      },
      { headers: { 'X-Shopify-Access-Token': token } }
    );

    logger.info('CarrierService registered', {
      id: response.data.carrier_service.id,
      callbackUrl
    });

    return response.data.carrier_service;
  }
}

// Export singleton instance
export const shopifyService = new ShopifyService();
export default shopifyService;
