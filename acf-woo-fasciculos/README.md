# ACF + Woo Subscriptions Fascículos

**Versión:** 3.0.0
**Requiere:** WordPress 5.0+, WooCommerce 8.2+, WooCommerce Subscriptions, Advanced Custom Fields (ACF)
**Compatible con:** WooCommerce High-Performance Order Storage (HPOS) ✅

Plugin de WordPress que implementa un sistema de suscripción por fascículos para WooCommerce, permitiendo crear planes de entrega semanal con diferentes productos y precios.

## Características

- 📅 **Planes Semanales**: Crea planes de suscripción con diferentes productos por semana
- 💰 **Precios Variables**: Establece precios diferentes para cada semana del plan
- 🔄 **Renovación Automática**: El sistema avanza automáticamente a la siguiente semana
- ✅ **Finalización Automática**: La suscripción se cancela automáticamente al completar el plan
- 📊 **Panel de Administración**: Visualiza el progreso de cada suscripción en el panel de administración
- ⚡ **Optimizado**: Sistema de caché para mejorar el rendimiento
- 🏪 **Compatible con HPOS**: Totalmente compatible con el nuevo sistema de almacenamiento de pedidos de alto rendimiento
- 🔒 **Moderno y Seguro**: Cumple con los estándares más recientes de WooCommerce

## Requisitos

- WordPress 5.0 o superior
- WooCommerce 8.2 o superior (recomendado para HPOS completo)
- WooCommerce Subscriptions (última versión)
- Advanced Custom Fields (ACF) Pro o gratuito
- PHP 7.4 o superior (recomendado PHP 8.0+)

## Compatibilidad con HPOS

Este plugin es **totalmente compatible** con WooCommerce High-Performance Order Storage (HPOS):

- ✅ Declaración automática de compatibilidad con `custom_order_tables`
- ✅ Uso de métodos modernos de WooCommerce para manejo de pedidos
- ✅ Soporte completo para tablas de pedidos personalizadas
- ✅ Retrocompatibilidad con el sistema de pedidos tradicional
- ✅ Optimizado para el rendimiento mejorado de HPOS

**Nota:** Si tu tienda utiliza HPOS, el plugin funcionará automáticamente sin configuración adicional.

## Instalación

### Instalación automática:
1. Descarga el plugin como archivo ZIP
2. En WordPress, ve a **Plugins > Añadir nuevo > Subir plugin**
3. Sube el archivo ZIP y activa el plugin

### Instalación manual:
1. Descarga el plugin
2. Sube la carpeta `acf-woo-fasciculos` a `/wp-content/plugins/`
3. Activa el plugin desde el panel de administración de WordPress

## Configuración

### 1. Crear un Producto de Suscripción

1. Ve a **Productos > Añadir nuevo**
2. Establece el tipo de producto como "Suscripción simple" o "Suscripción variable"
3. Configura los detalles básicos de la suscripción

### 2. Configurar el Plan de Fascículos

1. En la página del producto, busca la sección **"Plan de Fascículos"**
2. Haz clic en **"Añadir semana"** para agregar cada semana del plan
3. Para cada semana, configura:
   - **Producto de la semana**: El producto que se enviará
   - **Precio de la semana**: El precio que se cobrará
   - **Nota**: Información adicional (opcional)

## Estructura del Código

El plugin está estructurado siguiendo las mejores prácticas de WordPress:

```
acf-woo-fasciculos/
├── acf-woo-fasciculos.php          # Archivo principal del plugin
├── includes/                         # Directorio de includes
│   ├── class-acf-woo-fasciculos.php # Clase principal
│   ├── core/                        # Funcionalidad principal
│   │   ├── class-acf-woo-fasciculos-utils.php       # Utilidades
│   │   ├── class-acf-woo-fasciculos-products.php    # Productos
│   │   ├── class-acf-woo-fasciculos-cart.php        # Carrito
│   │   ├── class-acf-woo-fasciculos-subscriptions.php # Suscripciones
│   │   ├── class-acf-woo-fasciculos-orders.php      # Pedidos
│   │   └── class-acf-woo-fasciculos-acf.php         # ACF
│   └── admin/                       # Administración
│       └── class-acf-woo-fasciculos-admin.php       # Panel admin
└── README.md                        # Este archivo
```

## Hooks y Filtros

### Filtros

- `woocommerce_hidden_order_itemmeta`: Oculta metadatos internos
- `woocommerce_add_cart_item_data`: Agrega datos del plan al carrito
- `woocommerce_get_item_data`: Muestra información en el carrito
- `wcs_renewal_order_items`: Modifica items en renovaciones

### Acciones

- `woocommerce_single_product_summary`: Muestra la tabla del plan
- `woocommerce_before_calculate_totals`: Actualiza precios en el carrito
- `woocommerce_checkout_create_order_line_item`: Guarda plan en el pedido
- `woocommerce_checkout_subscription_created`: Copia plan a la suscripción
- `woocommerce_subscription_activated`: Maneja activación de suscripción
- `woocommerce_order_status_changed`: Maneja cambios de estado en renovaciones

## Uso del Código

### Obtener el Plan de un Producto

```php
$products_handler = new ACF_Woo_Fasciculos_Products();
$plan = $products_handler->get_product_plan( $product_id );
```

### Verificar si un Producto tiene Plan

```php
$has_plan = $products_handler->has_plan( $product_id );
```

### Obtener Información de la Semana Actual

```php
$week_info = $products_handler->get_week_info( $product_id, $week_index );
```

### Obtener el Progreso de una Suscripción

```php
$subscriptions_handler = new ACF_Woo_Fasciculos_Subscriptions();
$progress = $subscriptions_handler->get_subscription_progress( $subscription );
```

## Optimizaciones

El plugin incluye varias optimizaciones de rendimiento:

1. **Sistema de Caché**: Reduce consultas repetidas a la base de datos
2. **Validación de Datos**: Previene errores y mejora la seguridad
3. **Lazy Loading**: Solo carga lo necesario cuando se necesita
4. **Optimización de Consultas**: Reduce el número de consultas SQL

## Seguridad

- Validación estricta de tipos de datos
- Sanitización de todas las entradas
- Prevención de acceso a índices no existentes
- Uso de funciones de WordPress para seguridad

## Soporte

Para soporte y documentación adicional, visita:
- Documentación: https://tuequipo.com/docs/acf-woo-fasciculos
- Soporte: https://tuequipo.com/soporte

## Licencia

Este plugin está licenciado bajo GPLv2 o superior.

## Créditos

Desarrollado por Tu Equipo - https://tuequipo.com