import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { createLogger } from '../utils/logger.js';

const logger = createLogger('token-storage');

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Store tokens in a JSON file (for single-store deployment)
// For multi-store, you would use a database instead
const TOKENS_FILE = process.env.TOKENS_FILE_PATH || path.join(__dirname, '../../data/tokens.json');

/**
 * Simple token storage for OAuth tokens
 * Stores tokens in a JSON file for persistence across restarts
 */
class TokenStorage {
  constructor() {
    this.tokens = {};
    this.ensureDataDirectory();
    this.loadTokens();
  }

  /**
   * Ensure the data directory exists
   */
  ensureDataDirectory() {
    const dir = path.dirname(TOKENS_FILE);
    if (!fs.existsSync(dir)) {
      fs.mkdirSync(dir, { recursive: true });
      logger.info('Created data directory:', dir);
    }
  }

  /**
   * Load tokens from file
   */
  loadTokens() {
    try {
      if (fs.existsSync(TOKENS_FILE)) {
        const data = fs.readFileSync(TOKENS_FILE, 'utf8');
        this.tokens = JSON.parse(data);
        logger.info('Loaded tokens from storage');
      } else {
        this.tokens = {};
        logger.info('No existing tokens file, starting fresh');
      }
    } catch (error) {
      logger.error('Error loading tokens:', error.message);
      this.tokens = {};
    }
  }

  /**
   * Save tokens to file
   */
  saveTokens() {
    try {
      fs.writeFileSync(TOKENS_FILE, JSON.stringify(this.tokens, null, 2));
      logger.info('Tokens saved to storage');
    } catch (error) {
      logger.error('Error saving tokens:', error.message);
    }
  }

  /**
   * Store access token for a shop
   * @param {string} shop - Shop domain (e.g., 'mystore.myshopify.com')
   * @param {string} accessToken - The access token
   * @param {string} scope - Granted scopes
   */
  setToken(shop, accessToken, scope = '') {
    this.tokens[shop] = {
      accessToken,
      scope,
      installedAt: new Date().toISOString(),
      updatedAt: new Date().toISOString()
    };
    this.saveTokens();
    logger.info(`Token stored for shop: ${shop}`);
  }

  /**
   * Get access token for a shop
   * @param {string} shop - Shop domain
   * @returns {string|null} - Access token or null if not found
   */
  getToken(shop) {
    const tokenData = this.tokens[shop];
    if (tokenData && tokenData.accessToken) {
      return tokenData.accessToken;
    }
    return null;
  }

  /**
   * Get full token data for a shop
   * @param {string} shop - Shop domain
   * @returns {Object|null} - Token data or null
   */
  getTokenData(shop) {
    return this.tokens[shop] || null;
  }

  /**
   * Check if a shop has a valid token
   * @param {string} shop - Shop domain
   * @returns {boolean}
   */
  hasToken(shop) {
    return !!this.getToken(shop);
  }

  /**
   * Remove token for a shop (uninstall)
   * @param {string} shop - Shop domain
   */
  removeToken(shop) {
    if (this.tokens[shop]) {
      delete this.tokens[shop];
      this.saveTokens();
      logger.info(`Token removed for shop: ${shop}`);
    }
  }

  /**
   * Get all shops with tokens
   * @returns {string[]} - Array of shop domains
   */
  getAllShops() {
    return Object.keys(this.tokens);
  }
}

// Export singleton instance
export const tokenStorage = new TokenStorage();
export default tokenStorage;
