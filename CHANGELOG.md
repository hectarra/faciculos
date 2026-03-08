# Changelog

Todos los cambios notables en este plugin serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto sigue [Semantic Versioning](https://semver.org/lang/es/).

## [3.6.0] - 2026-03-06

### Añadido
- **Código de descuento automático para renovaciones**
  - Nuevo campo ACF "Código de descuento para renovaciones" en productos de suscripción
  - Permite configurar un cupón de WooCommerce que se aplica automáticamente a todas las renovaciones
  - El primer pedido (compra inicial) NO recibe el descuento
  - Usa la infraestructura nativa de cupones de WooCommerce
  - El descuento es visible en cada pedido de renovación como un cupón aplicado
  - Notas informativas automáticas en suscripciones y pedidos de renovación

### Funciones nuevas
- `copy_coupon_to_subscription()` - Copia el código de cupón del producto a la suscripción al crearla
- `apply_discount_coupon_to_renewal()` - Aplica el cupón automáticamente a cada pedido de renovación
- Nueva constante `META_DISCOUNT_COUPON` para almacenar el cupón en la suscripción

### Hook
- Nuevo action hook registrado: `woocommerce_checkout_subscription_created` → `copy_coupon_to_subscription` (prioridad 20)

---

## [3.5.0] - 2026-02-26

### Añadido
- **Filtro de tienda: solo productos de suscripción**
  - La página de tienda de WooCommerce ahora solo muestra productos de tipo suscripción
  - Los productos simples quedan ocultos del catálogo público
  - El filtro también aplica en categorías y etiquetas de producto
  - El panel de administración no se ve afectado (muestra todos los productos)

### Funciones nuevas
- `filter_shop_subscription_only()` - Filtra la query principal de la tienda usando `pre_get_posts` y la taxonomía `product_type`

### Hook
- Nuevo action hook registrado: `pre_get_posts` → `filter_shop_subscription_only`

---

## [Infraestructura] - 2026-02-23

### Añadido
- **Redis como Object Cache**
  - Instalado Redis Server y extensión `lsphp83-redis`
  - Configurado como Object Cache en LiteSpeed Cache (host: `127.0.0.1`, puerto: `6379`)
  - Reduce drásticamente las consultas a la base de datos en cada petición

- **OPcache + JIT habilitados**
  - OPcache activado con 256MB de memoria para scripts compilados
  - Interned strings buffer: 32MB, max archivos: 30.000
  - JIT (Just-In-Time) en modo `tracing` con 128MB de buffer
  - Revalidación de archivos cada 60 segundos

- **AIO io_uring habilitado**
  - Configurado `io_uring` como método de I/O asíncrono en OpenLiteSpeed
  - Mejora el rendimiento de servir archivos estáticos con menos llamadas al sistema

### Cambiado
- **WP-Cron migrado al sistema**
  - Desactivado WP-Cron por HTTP (`DISABLE_WP_CRON = true` en `wp-config.php`)
  - Configurado cron del sistema (`crontab`) para ejecutar `wp cron event run --due-now` cada 5 minutos
  - Resuelto problema de ~20 días de crons atrasados que causaban deadlocks LSAPI

- **Configuración de PHP (`php.ini`)**
  - `memory_limit`: 512M
  - `max_execution_time`: 300s
  - `upload_max_filesize` y `post_max_size`: 128M
  - `realpath_cache_size`: 4096K, `realpath_cache_ttl`: 600

- **Optimización de MariaDB**
  - `innodb_buffer_pool_size`: 3G (para 15GB RAM)
  - `innodb_log_file_size`: 512M
  - `max_connections`: 200
  - `innodb_flush_log_at_trx_commit`: 2 (mejor rendimiento escritura)
  - `innodb_flush_method`: O_DIRECT

- **Tuning de OpenLiteSpeed**
  - LSPHP: 50 max connections, 600s max idle time
  - Compresión GZIP (nivel 6) y Brotli habilitados
  - Small File Cache: 40MB, MMAP Cache: 80MB
  - Kernel: optimización de `sysctl` para conexiones de red

### Corregido
- **Deadlock LSAPI** (`No request delivery notification from LSAPI application, possible dead lock`)
  - Causa: crons atrasados ejecutándose en masa + timeouts bajos de LSAPI
  - Solución: migración a cron del sistema + aumento de timeouts

---

## [3.4.1] - 2025-12-26

### Cambiado
- **Nombre del plugin**: Cambiado a "Coleccionables Singulari"
- **Autor**: Actualizado a "Héctor & Ledys"
- **URLs**: Actualizadas a grupo-pro.es

---

## [3.4.0] - 2025-12-26

### Añadido
- **Creación automática de usuarios durante el checkout**
  - Crea automáticamente una cuenta de cliente cuando un usuario no registrado compra una suscripción de fascículos
  - Genera contraseña segura automáticamente
  - Asigna el usuario al pedido y a la suscripción
  - Guarda la dirección de facturación en el perfil del usuario

- **Email con datos de acceso**
  - Envío automático de email HTML con las credenciales de acceso (usuario y contraseña)
  - Diseño profesional que respeta los colores configurados en WooCommerce
  - Incluye botón de acceso directo a "Mi cuenta"
  
- **Mejoras en el checkout**
  - Aviso informativo en el checkout indicando que se creará una cuenta automáticamente
  - Oculta campos innecesarios de "crear cuenta" ya que la creación es automática
  - Si ya existe un usuario con el email, se asigna automáticamente al pedido

### Clases nuevas
- `ACF_Woo_Fasciculos_Checkout` - Manejador de la creación automática de usuarios en checkout

### Funciones nuevas
- `process_new_user_after_order()` - Procesa la creación de usuario tras crear el pedido
- `create_user_for_order()` - Crea el usuario con los datos del pedido
- `send_credentials_email()` - Envía email con las credenciales
- `force_account_creation_for_fasciculos()` - Fuerza requerir cuenta para suscripciones de fascículos
- `maybe_require_account_fields()` - Modifica campos del checkout
- `add_auto_account_notice()` - Agrega aviso informativo en el checkout

### Hooks
- Nuevo action hook: `acf_woo_fasciculos_user_created` - Se ejecuta después de crear un usuario
- Nuevo filter hook: `acf_woo_fasciculos_credentials_email_args` - Permite modificar el email de credenciales

---

## [3.3.1] - 2024-12-24

### Corregido
- **Eliminados logs de debug en producción**
  - Eliminadas llamadas a `error_log()` en el registro de campos ACF
  - Solucionados mensajes NOTICE repetidos: "Registrando campos ACF v2 para fascículos"
  - Archivo afectado: `class-acf-woo-fasciculos-acf.php`

---

## [3.3.0]

### Añadido
- **Días personalizados de renovación**
  - Nuevo campo ACF "Días entre renovaciones" en productos de suscripción
  - Permite configurar un número exacto de días entre cada renovación (ej: 7, 14, 30, etc.)
  - Si se deja vacío, se usa el período configurado en el producto de WooCommerce Subscriptions
  - Rango permitido: 1 a 365 días
  
### Funciones nuevas
- `apply_custom_renewal_days()` - Aplica el período de renovación personalizado al crear la suscripción
- Nueva constante `META_RENEWAL_DAYS` para almacenar los días personalizados en la suscripción
- Nota informativa automática cuando se aplica período personalizado: "📅 Período de renovación personalizado aplicado: cada X días"

---

## [3.2.0]

### Corregido
- **Pago de pedidos de renovación fallidos**
  - Solucionado error "Lo siento, este producto no se puede comprar" al pagar renovaciones fallidas
  - Los productos de fascículos (precio 0€) ahora son correctamente "comprables" en contexto de pago
  - Detecta automáticamente el contexto de renovación de WooCommerce Subscriptions
  - Nuevas funciones: `allow_fasciculo_products_purchasable()`, `validate_fasciculo_add_to_cart()`
  - Soporte para funciones de WCS: `wcs_cart_contains_renewal`, `wcs_cart_contains_failed_renewal_order_payment`

- **Precio correcto en el carrito para renovaciones fallidas**
  - El carrito ahora muestra el precio de la semana correspondiente del pedido de renovación
  - Anteriormente mostraba el precio inicial de la suscripción en lugar del precio del pedido
  - Nueva función `set_price_from_order()` obtiene el precio directamente del pedido
  - Función `get_renewal_order_from_cart()` detecta el pedido de renovación en el carrito

### Funciones nuevas
- `allow_payment_method_change_on_hold()` - Habilita cambio de método de pago para suscripciones on-hold
- `add_change_payment_action_on_hold()` - Agrega el botón de acción en la página de suscripción
- `retry_payment_after_method_change()` - Procesa automáticamente el pago pendiente
- `disable_user_renewal_reactivate_actions()` - Elimina botones de renovar, reactivar y resuscribir del área de usuario
- `disable_user_reactivation()` - Bloquea reactivación/resuscripción de suscripciones de fascículos
- `disable_early_renewal()` - Bloquea renovación anticipada (botón "Renovar ahora")

### Restricciones de usuario
- **Bloqueo de acciones del cliente** para suscripciones de fascículos:
  - ❌ Renovar ahora (early renewal)
  - ❌ Renovar manualmente
  - ❌ Reactivar suscripción
  - ❌ Resuscribirse
- Múltiples filtros de WCS con prioridad 999 para asegurar ejecución

---

## [3.1.0]

### Añadido
- **Barra de progreso visual** en los pedidos del panel de administración
  - Muestra progreso en porcentaje con colores dinámicos (naranja < 50%, azul ≥ 50%, verde = 100%)
  - Indica semana actual y total de semanas
  - Solo se muestra en la línea de suscripción, no en productos individuales

- **Control exclusivo de stock**
  - El stock solo se reduce al completar el pago del primer pedido o renovaciones
  - Nueva función `prevent_automatic_stock_reduction` previene reducción automática de WooCommerce
  - Nueva función `reduce_fasciculo_stock_on_payment` reduce stock manualmente al pagar
  - Flag `_fasciculo_stock_reduced` previene reducciones duplicadas
  - Nota informativa en el pedido con productos afectados

### Corregido
- **Hook de renovación corregido**: Cambiado de `woocommerce_subscription_renewal_order_created` (nunca se ejecutaba) a `wcs_renewal_order_created` (filtro correcto de WooCommerce Subscriptions)

- **Precio de suscripción en renovaciones**: El precio ahora se muestra correctamente en la línea de suscripción, no en el primer producto
  - Suscripción: X,XX€ (con el precio)
  - Productos individuales: 0,00€ (incluidos en el precio de suscripción)

### Eliminado
- Mensaje de texto "Semana actual fascículos: X" reemplazado por la barra de progreso visual

---

## [3.0.0] - 2024-12-05

### Añadido
- Arquitectura modular con clases separadas por funcionalidad
- Soporte para múltiples productos por semana en el plan de fascículos
- Campos ACF con repeater anidado para productos por semana
- Sistema de caché interno para optimizar consultas
- Cancelación automática al completar el plan
- Notas informativas en pedidos y suscripciones
- Compatibilidad con HPOS (High-Performance Order Storage)
- Internacionalización completa (español)

### Clases principales
- `ACF_Woo_Fasciculos` - Clase principal (Singleton)
- `ACF_Woo_Fasciculos_Products` - Manejo de productos y planes
- `ACF_Woo_Fasciculos_Cart` - Integración con carrito
- `ACF_Woo_Fasciculos_Orders` - Manejo de pedidos
- `ACF_Woo_Fasciculos_Subscriptions` - Manejo de suscripciones
- `ACF_Woo_Fasciculos_ACF` - Registro de campos ACF
- `ACF_Woo_Fasciculos_Admin` - Funcionalidad de administración
- `ACF_Woo_Fasciculos_Utils` - Utilidades y helpers
