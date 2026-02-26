import pino from 'pino';
import config from '../config.js';

/**
 * Create a Pino logger instance
 * 
 * @param {string} name - Logger name (module identifier)
 * @returns {Object} - Pino logger instance
 */
export function createLogger(name) {
  const options = {
    name,
    level: config.isDevelopment ? 'debug' : 'info'
  };
  
  // Use pino-pretty in development for readable logs
  if (config.isDevelopment) {
    options.transport = {
      target: 'pino-pretty',
      options: {
        colorize: true,
        translateTime: 'SYS:standard',
        ignore: 'pid,hostname'
      }
    };
  }
  
  return pino(options);
}

// Default logger
export const logger = createLogger('app');
export default logger;
