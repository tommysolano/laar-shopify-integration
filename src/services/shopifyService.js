import axios from 'axios';
import config from '../config.js';
import { createLogger } from '../utils/logger.js';

const logger = createLogger('shopify-service');

/**
 * Shopify Admin API Service
 * Uses GraphQL for metafields and fulfillment operations
 */
class ShopifyService {
  constructor() {
    this.storeDomain = config.shopify.storeDomain;
    this.apiVersion = config.shopify.apiVersion;
    this.adminToken = config.shopify.adminToken;
    
    // GraphQL endpoint
    this.graphqlUrl = `https://${this.storeDomain}/admin/api/${this.apiVersion}/graphql.json`;
    
    // REST endpoint base
    this.restBaseUrl = `https://${this.storeDomain}/admin/api/${this.apiVersion}`;
    
    // Create axios instance
    this.client = axios.create({
      timeout: 30000,
      headers: {
        'Content-Type': 'application/json',
        'X-Shopify-Access-Token': this.adminToken
      }
    });
  }
  
  /**
   * Execute GraphQL query
   */
  async graphql(query, variables = {}) {
    try {
      const response = await this.client.post(this.graphqlUrl, {
        query,
        variables
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
   */
  async saveOrderMetafields(orderId, guia, pdfUrl) {
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
}

// Export singleton instance
export const shopifyService = new ShopifyService();
export default shopifyService;
