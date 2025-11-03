# Estructura Completa del Plugin ACF + Woo Subscriptions Fascículos

## Árbol de Archivos

```
acf-woo-fasciculos/
├── acf-woo-fasciculos.php                    # Archivo principal del plugin
├── README.md                              # Documentación completa
├── PLUGIN_STRUCTURE.md                    # Este archivo - estructura del plugin
├── includes/                              # Directorio de includes
│   ├── class-acf-woo-fasciculos.php       # Clase principal (Singleton)
│   ├── core/                                # Funcionalidad principal
│   │   ├── class-acf-woo-fasciculos-utils.php       # Utilidades y helpers
│   │   ├── class-acf-woo-fasciculos-products.php    # Manejo de productos
│   │   ├── class-acf-woo-fasciculos-cart.php       # Manejo del carrito
│   │   ├── class-acf-woo-fasciculos-subscriptions.php # Manejo de suscripciones
│   │   ├── class-acf-woo-fasciculos-orders.php     # Manejo de pedidos
│   │   └── class-acf-woo-fasciculos-acf.php        # Integración con ACF
│   └── admin/                               # Funcionalidad de administración
│       └── class-acf-woo-fasciculos-admin.php   # Panel de administración
├── assets/                                # Recursos del plugin
│   └── css/                               # Estilos CSS
│       └── acf-woo-fasciculos.css         # Estilos principales
├── languages/                             # Archivos de traducción
│   └── acf-woo-fasciculos-es_ES.po        # Traducción al español
└── tests/                                 # Pruebas del plugin
    └── test-plugin-structure.php          # Verificación de estructura
```

## Descripción de Archivos

### Archivos Principales

**`acf-woo-fasciculos.php`**
- Punto de entrada del plugin
- Verificación de requisitos
- Definición de constantes
- Hooks de activación/desactivación
- Carga de textos de traducción

**`README.md`**
- Documentación completa del plugin
- Instrucciones de instalación
- Ejemplos de uso
- Información de requisitos

### Clases Principales

**`includes/class-acf-woo-fasciculos.php`**
- Clase principal del plugin (patrón Singleton)
- Orquesta toda la funcionalidad
- Define constantes de metadatos
- Configura todos los hooks necesarios

**`includes/core/class-acf-woo-fasciculos-utils.php`**
- Utilidades y funciones auxiliares
- Sistema de caché interno
- Validaciones de datos
- Funciones de ayuda para productos y suscripciones

**`includes/core/class-acf-woo-fasciculos-products.php`**
- Manejo de productos y planes
- Renderizado de la tabla de plan en frontend
- Obtención de información de semanas
- Validación de planes

**`includes/core/class-acf-woo-fasciculos-cart.php`**
- Integración con el carrito de WooCommerce
- Modificación de precios según el plan
- Visualización de información en el carrito
- Manejo de items con plan de fascículos

**`includes/core/class-acf-woo-fasciculos-subscriptions.php`**
- Manejo de suscripciones de WooCommerce
- Procesamiento de renovaciones
- Avance automático entre semanas
- Finalización automática del plan

**`includes/core/class-acf-woo-fasciculos-orders.php`**
- Manejo de pedidos y renovaciones
- Copia de plan desde pedido a suscripción
- Actualización de suscripciones en renovaciones
- Notas informativas en pedidos

**`includes/core/class-acf-woo-fasciculos-acf.php`**
- Integración con Advanced Custom Fields
- Registro de campos personalizados
- Manejo seguro de datos de ACF
- Verificación de disponibilidad de ACF

**`includes/admin/class-acf-woo-fasciculos-admin.php`**
- Funcionalidad del panel de administración
- Visualización de información en pedidos
- Columnas personalizadas en listas
- Enlaces de acción en plugins

### Recursos

**`assets/css/acf-woo-fasciculos.css`**
- Estilos para la tabla de plan en frontend
- Estilos para información en el carrito
- Estilos responsive
- Estilos para el panel de administración

**`languages/acf-woo-fasciculos-es_ES.po`**
- Traducción completa al español
- Todos los strings del plugin traducidos
- Formato PO estándar de WordPress

### Pruebas

**`tests/test-plugin-structure.php`**
- Verificación de estructura del plugin
- Pruebas de existencia de archivos
- Pruebas de clases y constantes
- Utilidad para debugging

## Flujo de Funcionamiento

### 1. Creación del Producto
- El administrador crea un producto de suscripción
- Configura el plan de fascículos usando ACF
- Define productos, precios y notas por semana

### 2. Compra Inicial
- El cliente compra el producto de suscripción
- Se cobra el precio de la semana 1
- El plan se guarda en la suscripción

### 3. Visualización en Frontend
- La tabla del plan se muestra en la página del producto
- En el carrito se muestra la semana actual
- Los precios se actualizan según la semana activa

### 4. Renovaciones Automáticas
- Cada renovación avanza a la siguiente semana
- Se actualiza el producto y precio de la suscripción
- Se agregan notas informativas al pedido

### 5. Finalización del Plan
- Al completar la última semana, la suscripción se cancela automáticamente
- Se notifica al cliente y administrador
- El plan queda marcado como completado

## Optimizaciones Implementadas

### 1. Sistema de Caché
- Caché interno para consultas repetidas
- Reducción de consultas a base de datos
- Mejora de rendimiento en frontend

### 2. Validaciones de Seguridad
- Verificación de tipos de datos
- Sanitización de entradas
- Prevención de acceso a índices no existentes

### 3. Manejo de Errores
- Validaciones exhaustivas antes de operaciones
- Mensajes de error claros
- Fallbacks seguros

### 4. Internacionalización
- Todos los textos traducibles
- Soporte para múltiples idiomas
- Archivo .po completo en español

## Constantes de Metadatos

- `META_PLAN_KEY`: 'fasciculos_plan' - Campo ACF con el plan
- `META_ACTIVE_INDEX`: '_fasciculo_active_index' - Índice de la semana actual
- `META_PLAN_CACHE`: '_fasciculos_plan_cache' - Caché del plan en JSON
- `META_FIRST_UPDATE`: '_fasciculo_first_update_done' - Flag de primera actualización

## Hooks Principales

### Filtros
- `woocommerce_hidden_order_itemmeta`: Oculta metadatos internos
- `woocommerce_add_cart_item_data`: Agrega plan al carrito
- `woocommerce_get_item_data`: Muestra info en carrito
- `wcs_renewal_order_items`: Modifica items en renovaciones

### Acciones
- `woocommerce_single_product_summary`: Muestra tabla del plan
- `woocommerce_before_calculate_totals`: Actualiza precios
- `woocommerce_checkout_create_order_line_item`: Guarda plan en pedido
- `woocommerce_checkout_subscription_created`: Copia plan a suscripción
- `woocommerce_subscription_activated`: Maneja activación
- `woocommerce_order_status_changed`: Maneja cambios de estado

## Requisitos del Sistema

- WordPress 5.0+
- WooCommerce 3.0+
- WooCommerce Subscriptions
- Advanced Custom Fields (ACF)
- PHP 7.2+

## Características Clave

✅ **Planes Semanales**: Configuración flexible de productos por semana
✅ **Precios Variables**: Precios diferentes para cada semana
✅ **Renovación Automática**: Avance automático entre semanas
✅ **Finalización Automática**: Cancelación al completar el plan
✅ **Panel Admin**: Visualización de progreso en administración
✅ **Optimizado**: Sistema de caché para mejor rendimiento
✅ **Seguro**: Validaciones exhaustivas y manejo de errores
✅ **Internacionalizado**: Soporte para múltiples idiomas
✅ **Documentado**: Código bien comentado y documentado

Este plugin representa una solución completa y robusta para implementar suscripciones por fascículos en WooCommerce, con un enfoque en la optimización, seguridad y facilidad de uso tanto para administradores como para clientes.