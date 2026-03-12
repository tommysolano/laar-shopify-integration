import { Router } from 'express';
import { laarService } from '../services/laarService.js';
import { createLogger } from '../utils/logger.js';

const router = Router();
const logger = createLogger('labels');

/**
 * GET /labels/:guia
 * 
 * Proxy endpoint that fetches the LAAR label PDF and streams it to the browser.
 * This is needed because LAAR's PDF endpoint requires Bearer token authentication,
 * so it can't be opened directly in a browser.
 */
router.get('/:guia', async (req, res) => {
  const { guia } = req.params;

  // Validate guide number format (alphanumeric only)
  if (!guia || !/^[A-Za-z0-9]+$/.test(guia)) {
    return res.status(400).json({ error: 'Invalid guide number' });
  }

  try {
    logger.info('Fetching label PDF from LAAR', { guia });

    const token = await laarService.getToken();

    const response = await laarService.client.get(
      `/api/Pdfs/v3/etiqueta/descargar`,
      {
        headers: { 'Authorization': `Bearer ${token}` },
        params: { guia },
        responseType: 'arraybuffer',
        timeout: 30000
      }
    );

    const contentType = response.headers['content-type'] || 'application/pdf';

    res.set({
      'Content-Type': contentType,
      'Content-Disposition': `inline; filename="etiqueta-${guia}.pdf"`,
      'Cache-Control': 'private, max-age=3600'
    });

    res.send(Buffer.from(response.data));
  } catch (error) {
    // Retry once on 401 (expired token)
    if (error.response?.status === 401) {
      try {
        laarService.clearToken();
        const token = await laarService.getToken();
        const response = await laarService.client.get(
          `/api/Pdfs/v3/etiqueta/descargar`,
          {
            headers: { 'Authorization': `Bearer ${token}` },
            params: { guia },
            responseType: 'arraybuffer',
            timeout: 30000
          }
        );

        const contentType = response.headers['content-type'] || 'application/pdf';
        res.set({
          'Content-Type': contentType,
          'Content-Disposition': `inline; filename="etiqueta-${guia}.pdf"`,
          'Cache-Control': 'private, max-age=3600'
        });
        return res.send(Buffer.from(response.data));
      } catch (retryError) {
        logger.error('Label fetch retry failed', { guia, error: retryError.message });
      }
    }

    logger.error('Failed to fetch label PDF', {
      guia,
      status: error.response?.status,
      message: error.message
    });

    const status = error.response?.status || 500;
    res.status(status).json({
      error: 'Failed to fetch label',
      message: status === 404 ? 'Label not found' : 'Could not retrieve label from LAAR'
    });
  }
});

export default router;
