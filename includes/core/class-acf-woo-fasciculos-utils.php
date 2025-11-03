<?php
/**
 * Clase de utilidades para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase de utilidades con funciones comunes
 */
class ACF_Woo_Fasciculos_Utils {

    /**
     * Caché interno para optimizar consultas
     *
     * @var array
     */
    private static $cache = array();

    /**
     * Limpiar todo el caché
     *
     * @return void
     */
    public static function clear_cache() {
        self::$cache = array();
    }

    /**
     * Verificar si un producto es de suscripción
     *
     * @param WC_Product|int $product Producto a verificar.
     * @return bool True si es un producto de suscripción.
     */
    public static function is_subscription_product( $product ) {
        if ( ! $product ) {
            return false;
        }

        // Obtener el ID del producto si es un objeto
        $product_id = is_object( $product ) ? $product->get_id() : intval( $product );
        
        // Verificar caché
        $cache_key = 'is_subscription_' . $product_id;
        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::$cache[ $cache_key ];
        }

        // Obtener el producto si solo tenemos el ID
        if ( ! is_object( $product ) ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                self::$cache[ $cache_key ] = false;
                return false;
            }
        }

        // Verificar el tipo de producto
        $type = $product->get_type();
        $result = in_array( $type, array( 'subscription', 'variable-subscription' ), true );

        // Guardar en caché
        self::$cache[ $cache_key ] = $result;
        return $result;
    }

    /**
     * Obtener el nombre de un producto de forma optimizada
     *
     * @param int $product_id ID del producto.
     * @return string Nombre del producto o ID como fallback.
     */
    public static function get_product_name( $product_id ) {
        $product_id = intval( $product_id );
        
        // Verificar caché
        $cache_key = 'product_name_' . $product_id;
        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::$cache[ $cache_key ];
        }

        // Obtener el producto
        $product = wc_get_product( $product_id );
        
        if ( $product ) {
            $name = $product->get_name();
        } else {
            $name = sprintf( __( 'ID %d', 'acf-woo-fasciculos' ), $product_id );
        }

        // Guardar en caché
        self::$cache[ $cache_key ] = $name;
        return $name;
    }

    /**
     * Obtener el plan de fascículos para un producto
     *
     * @param int $product_id ID del producto.
     * @return array Array con el plan de fascículos.
     */
    public static function get_plan_for_product( $product_id ) {
        $product_id = intval( $product_id );
        
        // Verificar caché
        $cache_key = 'plan_' . $product_id;
        if ( isset( self::$cache[ $cache_key ] ) ) {
            return self::$cache[ $cache_key ];
        }

        // Verificar que ACF esté activo
        if ( ! function_exists( 'get_field' ) ) {
            self::$cache[ $cache_key ] = array();
            return array();
        }

        // Obtener el plan desde ACF
        $rows = get_field( ACF_Woo_Fasciculos::META_PLAN_KEY, $product_id );

        if ( empty( $rows ) || ! is_array( $rows ) ) {
            self::$cache[ $cache_key ] = array();
            return array();
        }

        // Procesar el plan
        $plan = array();
        foreach ( $rows as $row ) {
            $product = isset( $row['fasciculo_product'] ) ? $row['fasciculo_product'] : null;
            $price = isset( $row['fasciculo_price'] ) ? floatval( $row['fasciculo_price'] ) : null;
            $note = isset( $row['fasciculo_note'] ) ? wc_clean( $row['fasciculo_note'] ) : '';

            $product_id_from_field = self::get_product_id_from_field( $product );

            if ( $product_id_from_field && $price !== null ) {
                $plan[] = array(
                    'product_id' => $product_id_from_field,
                    'price' => $price,
                    'note' => $note,
                );
            }
        }

        // Guardar en caché
        self::$cache[ $cache_key ] = $plan;
        return $plan;
    }

    /**
     * Obtener el ID de producto desde un campo ACF
     *
     * @param mixed $product_field Campo de producto de ACF.
     * @return int ID del producto o 0 si no es válido.
     */
    private static function get_product_id_from_field( $product_field ) {
        if ( is_object( $product_field ) && isset( $product_field->ID ) ) {
            return intval( $product_field->ID );
        }
        if ( is_numeric( $product_field ) ) {
            return intval( $product_field );
        }
        return 0;
    }

    /**
     * Obtener una fila específica del plan
     *
     * @param array $plan Plan de fascículos.
     * @param int   $index Índice de la fila a obtener.
     * @return array|null Fila del plan o null si no existe.
     */
    public static function get_plan_row( $plan, $index ) {
        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return null;
        }

        $index = intval( $index );
        if ( $index < 0 ) {
            $index = 0;
        }

        if ( $index >= count( $plan ) ) {
            return null;
        }

        return $plan[ $index ];
    }

    /**
     * Verificar si una suscripción es válida
     *
     * @param mixed $subscription Suscripción a verificar.
     * @return bool True si es una suscripción válida.
     */
    public static function is_valid_subscription( $subscription ) {
        return $subscription && is_a( $subscription, 'WC_Subscription' );
    }

    /**
     * Verificar si un pedido es válido
     *
     * @param mixed $order Pedido a verificar.
     * @return bool True si es un pedido válido.
     */
    public static function is_valid_order( $order ) {
        return $order && is_a( $order, 'WC_Order' );
    }

    /**
     * Verificar si un pedido es de renovación
     *
     * @param WC_Order $order Pedido a verificar.
     * @return bool True si es un pedido de renovación.
     */
    public static function is_renewal_order( $order ) {
        if ( ! self::is_valid_order( $order ) ) {
            return false;
        }

        if ( ! function_exists( 'wcs_order_contains_renewal' ) ) {
            return false;
        }

        return wcs_order_contains_renewal( $order );
    }

    /**
     * Obtener suscripciones asociadas a un pedido de renovación
     *
     * @param int $order_id ID del pedido.
     * @return array Array de suscripciones.
     */
    public static function get_renewal_subscriptions( $order_id ) {
        if ( ! function_exists( 'wcs_get_subscriptions_for_order' ) ) {
            return array();
        }

        return wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'renewal' ) );
    }

    /**
     * Verificar si un pedido ya fue procesado para una suscripción
     *
     * @param WC_Subscription $subscription Suscripción a verificar.
     * @param int             $order_id ID del pedido.
     * @return bool True si el pedido ya fue procesado.
     */
    public static function is_order_processed( $subscription, $order_id ) {
        if ( ! self::is_valid_subscription( $subscription ) ) {
            return false;
        }

        $flag_key = '_fasciculo_advanced_on_order_' . intval( $order_id );
        return ! ! $subscription->get_meta( $flag_key );
    }

    /**
     * Marcar un pedido como procesado para una suscripción
     *
     * @param WC_Subscription $subscription Suscripción a actualizar.
     * @param int             $order_id ID del pedido.
     * @return void
     */
    public static function mark_order_as_processed( $subscription, $order_id ) {
        if ( ! self::is_valid_subscription( $subscription ) ) {
            return;
        }

        $flag_key = '_fasciculo_advanced_on_order_' . intval( $order_id );
        $subscription->update_meta_data( $flag_key, 'yes' );
    }

    /**
     * Obtener el producto desde un item del carrito
     *
     * @param array $cart_item Item del carrito.
     * @return WC_Product|null Producto o null si no existe.
     */
    public static function get_product_from_cart_item( $cart_item ) {
        if ( ! isset( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
            return null;
        }
        return $cart_item['data'];
    }

    /**
     * Obtener el índice activo de un item del carrito
     *
     * @param array $cart_item Item del carrito.
     * @param int   $default Valor por defecto.
     * @return int Índice activo.
     */
    public static function get_active_index_from_cart_item( $cart_item, $default = 0 ) {
        if ( ! isset( $cart_item[ ACF_Woo_Fasciculos::META_ACTIVE_INDEX ] ) ) {
            return intval( $default );
        }
        return intval( $cart_item[ ACF_Woo_Fasciculos::META_ACTIVE_INDEX ] );
    }

    /**
     * Obtener el plan desde un item del carrito
     *
     * @param array $cart_item Item del carrito.
     * @return array|null Plan o null si no existe.
     */
    public static function get_plan_from_cart_item( $cart_item ) {
        if ( ! isset( $cart_item[ ACF_Woo_Fasciculos::META_PLAN_CACHE ] ) ) {
            return null;
        }
        return $cart_item[ ACF_Woo_Fasciculos::META_PLAN_CACHE ];
    }

    /**
     * Formatear precio para mostrar
     *
     * @param float $price Precio a formatear.
     * @return string Precio formateado.
     */
    public static function format_price( $price ) {
        return wc_price( floatval( $price ) );
    }

    /**
     * Generar nota de pedido para renovación
     *
     * @param int    $order_id ID del pedido.
     * @param string $new_status Nuevo estado del pedido.
     * @param int    $current_active Índice actual.
     * @param int    $total_weeks Total de semanas.
     * @param string $current_name Nombre del producto actual.
     * @param int    $next_index Índice siguiente.
     * @param string $next_name Nombre del próximo producto.
     * @param float  $next_price Precio del próximo producto.
     * @return string Nota formateada.
     */
    public static function generate_renewal_note( $order_id, $new_status, $current_active, $total_weeks, $current_name, $next_index, $next_name, $next_price ) {
        return sprintf(
            /* translators: 1: Order ID, 2: New status, 3: Current week, 4: Total weeks, 5: Current product name, 6: Next week, 7: Total weeks, 8: Next product name, 9: Next product price */
            __( '🔁 Renovación confirmada (pedido #%1$d → %2$s). Semana %3$d/%4$d cobrada: %5$s. Próxima renovación (semana %6$d/%7$d): %8$s — %9$s', 'acf-woo-fasciculos' ),
            $order_id,
            $new_status,
            $current_active + 1,
            $total_weeks,
            $current_name,
            $next_index + 1,
            $total_weeks,
            $next_name,
            self::format_price( $next_price )
        );
    }

    /**
     * Devuelve los productos de un bundle si el producto es un bundle
     *
     * @param int $product_id
     * @return array Array de WC_Product
     */
    public static function get_bundle_products_if_bundle($product_id){
        if (!class_exists('WC_Product_Bundle')) {
            return array();
        }

        $product = wc_get_product($product_id);

        if (!$product || $product->get_type() !== 'bundle') {
            return array();
        }

        $bundled_items = $product->get_bundled_items();
        $bundle_products = array();

        if ($bundled_items) {
            foreach ($bundled_items as $bundled_item) {
                $bundled_product = $bundled_item->get_product();
                if ($bundled_product) {
                    $bundle_products[] = $bundled_product;
                }
            }
        }

        return $bundle_products;
    }
}