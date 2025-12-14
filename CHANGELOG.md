# Changelog

Todos los cambios notables en este plugin serán documentados en este archivo.

El formato está basado en [Keep a Changelog](https://keepachangelog.com/es-ES/1.0.0/),
y este proyecto sigue [Semantic Versioning](https://semver.org/lang/es/).

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
