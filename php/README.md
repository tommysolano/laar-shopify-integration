# LAAR-Shopify Integration (PHP)

Integración entre Shopify webhooks y la API de LAAR Courier para generación automática de guías de envío. Versión PHP compatible con cPanel.

## Requisitos

- PHP >= 7.4
- Composer
- Extensiones PHP: `curl`, `json`, `mbstring`, `openssl`

## Instalación en cPanel

### 1. Subir archivos

Sube la carpeta `php/` al directorio de tu hosting. Puedes renombrarla a `public_html` o colocarla dentro de un subdirectorio.

### 2. Instalar dependencias

Conecta por SSH o usa el Terminal de cPanel:

```bash
cd /home/tu-usuario/public_html
composer install --no-dev --optimize-autoloader
```

### 3. Configurar variables de entorno

Copia el archivo de ejemplo y edítalo con tus credenciales:

```bash
cp .env.example .env
nano .env
```

### 4. Configurar el dominio

Asegúrate de que el dominio apunte a la carpeta `public/` como Document Root. En cPanel:

1. Ve a **Dominios** o **Subdominios**
2. Configura el Document Root a `public_html/public` (o la ruta equivalente)

### 5. Verificar mod_rewrite

El archivo `.htaccess` ya está configurado. Asegúrate de que Apache tenga `mod_rewrite` habilitado (la mayoría de cPanel lo tiene por defecto).

### 6. Configurar permisos

```bash
chmod 755 -R public/
chmod 777 data/
chmod 777 logs/
```

## Estructura del proyecto

```
php/
├── .env.example          # Variables de entorno de ejemplo
├── .htaccess             # Rewrite rules (raíz)
├── composer.json         # Dependencias PHP
├── data/
│   └── shipping-rates.json  # Tarifas de envío por zona
├── logs/                 # Directorio de logs (se crea automáticamente)
├── public/
│   ├── .htaccess         # Rewrite rules (public)
│   └── index.php         # Entry point de la aplicación
└── src/
    ├── Config.php         # Configuración desde .env
    ├── Router.php         # Router HTTP simple
    ├── Routes/
    │   ├── auth.php       # OAuth flow con Shopify
    │   ├── carrierService.php  # Cálculo de tarifas de envío
    │   ├── labels.php     # Proxy para PDFs de etiquetas LAAR
    │   └── webhooks.php   # Webhooks de Shopify (orders/paid)
    ├── Services/
    │   ├── LaarService.php    # Servicio LAAR Courier API
    │   ├── ShopifyService.php # Servicio Shopify Admin API
    │   └── TokenStorage.php   # Almacenamiento de tokens OAuth
    └── Utils/
        ├── Logger.php         # Logger con Monolog
        └── VerifyShopifyHmac.php  # Verificación HMAC de webhooks
```

## Endpoints

| Método | Ruta | Descripción |
|--------|------|-------------|
| GET | `/` | Info de la aplicación |
| GET | `/health` | Health check |
| GET | `/auth?shop=x` | Iniciar OAuth con Shopify |
| GET | `/auth/callback` | Callback de OAuth |
| GET | `/auth/status` | Estado de autenticación |
| POST | `/auth/uninstall` | Webhook de desinstalación |
| POST | `/webhooks/orders_paid` | Webhook de pedidos pagados |
| POST | `/webhooks/test` | Webhook de prueba |
| POST | `/carrier-service/rates` | Cálculo de tarifas de envío |
| GET | `/labels/:guia` | Proxy de PDF de etiqueta |
| GET | `/token-status` | Estado del token |
| POST | `/register-carrier` | Registrar CarrierService |
| POST | `/setup-metafields` | Crear definiciones de metafields |

## Diferencias con la versión Node.js

- **Runtime**: PHP 7.4+ en lugar de Node.js 18+
- **HTTP Client**: GuzzleHTTP en lugar de Axios
- **Logger**: Monolog en lugar de Pino
- **Router**: Router personalizado en lugar de Express.js
- **Rate Limiting**: Manejado por cPanel/Apache en lugar de express-rate-limit
- **Seguridad**: Headers de seguridad manejados por .htaccess en lugar de Helmet
