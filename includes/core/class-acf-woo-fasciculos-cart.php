<?php
/**
 * Manejador del carrito para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar toda la funcionalidad relacionada con el carrito
 */
class ACF_Woo_Fasciculos_Cart {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Adjuntar el plan de fascículos a un item del carrito
     *
     * Este método se ejecuta cuando un producto se agrega al carrito.
     * Guarda el plan de fascículos en los datos del item para uso posterior.
     *
     * @param array $cart_item_data Datos actuales del item del carrito.
     * @param int   $product_id ID del producto.
     * @param int   $variation_id ID de la variación (si aplica).
     * @return array Datos modificados del item del carrito.
     */
    public function attach_plan_to_cart_item( $cart_item_data, $product_id, $variation_id ) {
        // Determinar qué ID usar (variación o producto principal)
        $product_to_check = $variation_id ? $variation_id : $product_id;
        
        // Obtener el producto
        $product = wc_get_product( $product_to_check );
        
        // Verificar si es un producto de suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_subscription_product( $product ) ) {
            return $cart_item_data;
        }

        // Obtener el plan de fascículos
        $plan = ACF_Woo_Fasciculos_Utils::get_plan_for_product( $product->get_id() );
        
        // Si no hay plan, no hacer nada
        if ( empty( $plan ) ) {
            return $cart_item_data;
        }

        // Adjuntar el plan y el índice activo al item del carrito
        $cart_item_data[ ACF_Woo_Fasciculos::META_PLAN_CACHE ] = $plan;
        $cart_item_data[ ACF_Woo_Fasciculos::META_ACTIVE_INDEX ] = 0;

        return $cart_item_data;
    }

    /**
     * Mostrar el plan de fascículos en el carrito
     *
     * Este método se ejecuta cuando se muestra el carrito.
     * Muestra información sobre el fascículo actual.
     *
     * @param array $item_data Datos del item a mostrar.
     * @param array $cart_item Item del carrito.
     * @return array Datos del item modificados.
     */
    public function display_plan_in_cart( $item_data, $cart_item ) {
        // Verificar si estamos pagando un pedido de renovación
        if ( $this->is_paying_for_order_context() ) {
            $order = $this->get_order_being_paid();
            if ( $order && $this->order_has_fasciculo_products( $order ) ) {
                return $this->display_fasciculo_info_from_order( $item_data, $cart_item, $order );
            }
        }

        // Verificar contexto de renovación de WooCommerce Subscriptions
        if ( $this->is_renewal_cart_context() ) {
            $renewal_order = $this->get_renewal_order_from_cart();
            if ( $renewal_order && $this->order_has_fasciculo_products( $renewal_order ) ) {
                return $this->display_fasciculo_info_from_order( $item_data, $cart_item, $renewal_order );
            }
        }

        // Comportamiento normal: usar el plan del carrito
        $plan = ACF_Woo_Fasciculos_Utils::get_plan_from_cart_item( $cart_item );
        if ( ! $plan ) {
            return $item_data;
        }

        // Obtener el índice activo
        $active_index = ACF_Woo_Fasciculos_Utils::get_active_index_from_cart_item( $cart_item );
        
        // Obtener la fila actual del plan
        $row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $active_index );
        if ( ! $row ) {
            return $item_data;
        }

        // Obtener los nombres de los productos
        $product_names = '';
        if ( isset( $row['product_ids'] ) && is_array( $row['product_ids'] ) ) {
            $product_names = ACF_Woo_Fasciculos_Utils::get_product_names( $row['product_ids'] );
        }

        // Agregar la información al item
        $item_data[] = array(
            'name'  => __( 'Fascículo (semana actual)', 'acf-woo-fasciculos' ),
            'value' => sprintf( '%s — %s', $product_names, ACF_Woo_Fasciculos_Utils::format_price( $row['price'] ) ),
        );

        return $item_data;
    }

    /**
     * Mostrar información del fascículo desde un pedido
     *
     * @param array    $item_data Datos del item a mostrar.
     * @param array    $cart_item Item del carrito.
     * @param WC_Order $order Pedido con la información.
     * @return array Datos del item modificados.
     */
    private function display_fasciculo_info_from_order( $item_data, $cart_item, $order ) {
        // Obtener el producto del carrito
        $product = ACF_Woo_Fasciculos_Utils::get_product_from_cart_item( $cart_item );
        if ( ! $product ) {
            return $item_data;
        }

        // Solo mostrar info para productos de suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_subscription_product( $product ) ) {
            return $item_data;
        }

        // Obtener los nombres de los productos y el precio desde el pedido
        $product_names = array();
        $subscription_price = 0;

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Productos de fascículo (con precio 0€)
            $is_fasciculo_product = $item->get_meta( '_fasciculo_included' ) === 'yes' 
                || $item->get_meta( '_product_item' ) === 'yes';
            
            if ( $is_fasciculo_product ) {
                $product_names[] = $item->get_name();
            }

            // Producto de suscripción (con el precio real)
            $item_total = floatval( $item->get_total() );
            $plan_cache = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            
            if ( $item_total > 0 && $plan_cache ) {
                $subscription_price = $item_total;
            }
        }

        // Si no hay productos específicos, intentar obtenerlos de otra manera
        if ( empty( $product_names ) ) {
            // Buscar cualquier producto que no sea de suscripción
            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }
                
                $item_product = $item->get_product();
                if ( $item_product && ! $item_product->is_type( array( 'subscription', 'variable-subscription' ) ) ) {
                    $product_names[] = $item->get_name();
                }
            }
        }

        // Solo agregar si tenemos información
        if ( ! empty( $product_names ) || $subscription_price > 0 ) {
            $names_string = ! empty( $product_names ) ? implode( ', ', $product_names ) : __( 'Productos de la semana', 'acf-woo-fasciculos' );
            
            $item_data[] = array(
                'name'  => __( 'Fascículo (semana actual)', 'acf-woo-fasciculos' ),
                'value' => sprintf( '%s — %s', $names_string, ACF_Woo_Fasciculos_Utils::format_price( $subscription_price ) ),
            );
        }

        return $item_data;
    }

    /**
     * Sobreescribir los precios del carrito según el plan
     *
     * Este método se ejecuta antes de calcular los totales del carrito.
     * Actualiza los precios de los productos según el plan de fascículos.
     *
     * @param WC_Cart $cart Objeto del carrito.
     * @return void
     */
    public function override_cart_prices( $cart ) {
        // No ejecutar en el panel de administración
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        // Verificar que tengamos un carrito válido
        if ( ! $cart ) {
            return;
        }

        // Verificar si estamos pagando un pedido fallido de renovación
        $order_being_paid = null;
        $paying_for_order = $this->is_paying_for_order_context();
        
        if ( $paying_for_order ) {
            $order_being_paid = $this->get_order_being_paid();
        }

        // También verificar contexto de renovación de WooCommerce Subscriptions
        $renewal_order = null;
        if ( $this->is_renewal_cart_context() ) {
            $renewal_order = $this->get_renewal_order_from_cart();
        }

        // Recorrer todos los items del carrito
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $this->process_cart_item_price( $cart_item_key, $cart_item, $order_being_paid, $renewal_order );
        }
    }

    /**
     * Obtener el pedido de renovación desde el carrito
     *
     * @return WC_Order|null Pedido de renovación o null.
     */
    private function get_renewal_order_from_cart() {
        if ( ! function_exists( 'wcs_cart_contains_renewal' ) || ! WC()->cart ) {
            return null;
        }

        $renewal_item = wcs_cart_contains_renewal();
        if ( ! $renewal_item || ! isset( $renewal_item['subscription_renewal'] ) ) {
            return null;
        }

        // Obtener el ID del pedido de renovación
        $renewal_order_id = isset( $renewal_item['subscription_renewal']['renewal_order_id'] ) 
            ? $renewal_item['subscription_renewal']['renewal_order_id'] 
            : 0;

        if ( $renewal_order_id ) {
            return wc_get_order( $renewal_order_id );
        }

        return null;
    }

    /**
     * Procesar el precio de un item del carrito
     *
     * @param string        $cart_item_key Clave del item del carrito.
     * @param array         $cart_item Item del carrito.
     * @param WC_Order|null $order_being_paid Pedido que se está pagando (si aplica).
     * @param WC_Order|null $renewal_order Pedido de renovación del carrito (si aplica).
     * @return void
     */
    private function process_cart_item_price( $cart_item_key, $cart_item, $order_being_paid = null, $renewal_order = null ) {
        // Obtener el producto del item
        $product = ACF_Woo_Fasciculos_Utils::get_product_from_cart_item( $cart_item );
        
        if ( ! $product ) {
            return;
        }

        // Si estamos pagando un pedido fallido, usar el precio de ese pedido
        if ( $order_being_paid && $this->order_has_fasciculo_products( $order_being_paid ) ) {
            $this->set_price_from_order( $cart_item, $product, $order_being_paid );
            return;
        }

        // Si tenemos un pedido de renovación en el carrito, usar ese precio
        if ( $renewal_order && $this->order_has_fasciculo_products( $renewal_order ) ) {
            $this->set_price_from_order( $cart_item, $product, $renewal_order );
            return;
        }

        // Comportamiento normal: usar el plan del carrito
        // Verificar si es un producto de suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_subscription_product( $product ) ) {
            return;
        }

        // Obtener el plan del item
        $plan = ACF_Woo_Fasciculos_Utils::get_plan_from_cart_item( $cart_item );
        if ( empty( $plan ) ) {
            return;
        }

        // Obtener el índice activo
        $active_index = ACF_Woo_Fasciculos_Utils::get_active_index_from_cart_item( $cart_item );
        
        // Obtener la fila actual del plan
        $row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $active_index );
        if ( ! $row || ! isset( $row['price'] ) ) {
            return;
        }

        // Actualizar el precio del producto
        $cart_item['data']->set_price( floatval( $row['price'] ) );
    }

    /**
     * Establecer el precio del item del carrito desde un pedido
     *
     * @param array      $cart_item Item del carrito.
     * @param WC_Product $product Producto.
     * @param WC_Order   $order Pedido con los precios.
     * @return void
     */
    private function set_price_from_order( $cart_item, $product, $order ) {
        $product_id = $product->get_id();
        
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $item_product_id = $item->get_product_id();
            $item_variation_id = $item->get_variation_id();
            
            // Verificar si es el mismo producto
            if ( $product_id === $item_product_id || $product_id === $item_variation_id ) {
                // Obtener el precio del item del pedido
                $item_total = floatval( $item->get_total() );
                $item_qty = max( 1, $item->get_quantity() );
                $unit_price = $item_total / $item_qty;
                
                // Establecer el precio
                $cart_item['data']->set_price( $unit_price );
                return;
            }
        }

        // Si no encontramos el producto exacto, buscar el producto de suscripción
        // (el que tiene el precio principal de la semana)
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Buscar item con precio > 0 (la suscripción)
            $item_total = floatval( $item->get_total() );
            $plan_cache = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            
            if ( $item_total > 0 && $plan_cache ) {
                // Es el item de suscripción con el precio de la semana
                // Solo aplicar si el producto actual es de suscripción
                if ( ACF_Woo_Fasciculos_Utils::is_subscription_product( $product ) ) {
                    $item_qty = max( 1, $item->get_quantity() );
                    $unit_price = $item_total / $item_qty;
                    $cart_item['data']->set_price( $unit_price );
                    return;
                }
            }
        }
    }

    /**
     * Obtener el plan de fascículos de un item del carrito
     *
     * @param array $cart_item Item del carrito.
     * @return array|null Plan de fascículos o null si no existe.
     */
    public function get_cart_item_plan( $cart_item ) {
        return ACF_Woo_Fasciculos_Utils::get_plan_from_cart_item( $cart_item );
    }

    /**
     * Obtener el índice activo de un item del carrito
     *
     * @param array $cart_item Item del carrito.
     * @return int Índice activo (por defecto 0).
     */
    public function get_cart_item_active_index( $cart_item ) {
        return ACF_Woo_Fasciculos_Utils::get_active_index_from_cart_item( $cart_item );
    }

    /**
     * Verificar si un item del carrito tiene plan de fascículos
     *
     * @param array $cart_item Item del carrito.
     * @return bool True si tiene plan de fascículos.
     */
    public function cart_item_has_plan( $cart_item ) {
        return ! ! $this->get_cart_item_plan( $cart_item );
    }

    /**
     * Obtener información del fascículo actual de un item del carrito
     *
     * @param array $cart_item Item del carrito.
     * @return array|null Información del fascículo actual o null.
     */
    public function get_current_fasciculo_info( $cart_item ) {
        $plan = $this->get_cart_item_plan( $cart_item );
        if ( ! $plan ) {
            return null;
        }

        $active_index = $this->get_cart_item_active_index( $cart_item );
        return ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $active_index );
    }

    /**
     * Obtener los nombres de los productos actuales del fascículo
     *
     * @param array $cart_item Item del carrito.
     * @return string Nombres de los productos o string vacío.
     */
    public function get_current_fasciculo_product_names( $cart_item ) {
        $fasciculo_info = $this->get_current_fasciculo_info( $cart_item );
        
        if ( ! $fasciculo_info || ! isset( $fasciculo_info['product_ids'] ) ) {
            return '';
        }

        return ACF_Woo_Fasciculos_Utils::get_product_names( $fasciculo_info['product_ids'] );
    }

    /**
     * Obtener el precio actual del fascículo
     *
     * @param array $cart_item Item del carrito.
     * @return float|null Precio o null si no existe.
     */
    public function get_current_fasciculo_price( $cart_item ) {
        $fasciculo_info = $this->get_current_fasciculo_info( $cart_item );
        
        if ( ! $fasciculo_info || ! isset( $fasciculo_info['price'] ) ) {
            return null;
        }

        return floatval( $fasciculo_info['price'] );
    }

    /**
     * Obtener la nota del fascículo actual
     *
     * @param array $cart_item Item del carrito.
     * @return string Nota o string vacío.
     */
    public function get_current_fasciculo_note( $cart_item ) {
        $fasciculo_info = $this->get_current_fasciculo_info( $cart_item );
        
        if ( ! $fasciculo_info || ! isset( $fasciculo_info['note'] ) ) {
            return '';
        }

        return sanitize_text_field( $fasciculo_info['note'] );
    }

    /**
     * Verificar si el fascículo actual es el último
     *
     * @param array $cart_item Item del carrito.
     * @return bool True si es el último fascículo.
     */
    public function is_current_fasciculo_last( $cart_item ) {
        $plan = $this->get_cart_item_plan( $cart_item );
        if ( ! $plan ) {
            return false;
        }

        $active_index = $this->get_cart_item_active_index( $cart_item );
        return $active_index >= ( count( $plan ) - 1 );
    }

    /**
     * Obtener el número de semana actual (para mostrar al cliente)
     *
     * @param array $cart_item Item del carrito.
     * @return int Número de semana (1-based).
     */
    public function get_current_week_number( $cart_item ) {
        return $this->get_cart_item_active_index( $cart_item ) + 1;
    }

    /**
     * Obtener el número total de semanas
     *
     * @param array $cart_item Item del carrito.
     * @return int Total de semanas.
     */
    public function get_total_weeks( $cart_item ) {
        $plan = $this->get_cart_item_plan( $cart_item );
        return $plan ? count( $plan ) : 0;
    }

    /**
     * Obtener información formateada para mostrar en el carrito
     *
     * @param array $cart_item Item del carrito.
     * @return string Información formateada.
     */
    public function get_formatted_fasciculo_info( $cart_item ) {
        if ( ! $this->cart_item_has_plan( $cart_item ) ) {
            return '';
        }

        $product_names = $this->get_current_fasciculo_product_names( $cart_item );
        $price = $this->get_current_fasciculo_price( $cart_item );
        $week_number = $this->get_current_week_number( $cart_item );
        $total_weeks = $this->get_total_weeks( $cart_item );

        return sprintf(
            /* translators: 1: product names, 2: formatted price, 3: current week, 4: total weeks */
            __( '%1$s — %2$s (Semana %3$d de %4$d)', 'acf-woo-fasciculos' ),
            $product_names,
            ACF_Woo_Fasciculos_Utils::format_price( $price ),
            $week_number,
            $total_weeks
        );
    }

    /**
     * Obtener todos los items del carrito que tienen plan de fascículos
     *
     * @param WC_Cart $cart Objeto del carrito.
     * @return array Items con plan de fascículos.
     */
    public function get_items_with_plan( $cart ) {
        $items_with_plan = array();

        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            if ( $this->cart_item_has_plan( $cart_item ) ) {
                $items_with_plan[ $cart_item_key ] = $cart_item;
            }
        }

        return $items_with_plan;
    }

    /**
     * Verificar si el carrito tiene items con plan de fascículos
     *
     * @param WC_Cart $cart Objeto del carrito.
     * @return bool True si hay items con plan.
     */
    public function cart_has_items_with_plan( $cart ) {
        return ! empty( $this->get_items_with_plan( $cart ) );
    }

    /**
     * Obtener estadísticas del carrito relacionadas con fascículos
     *
     * @param WC_Cart $cart Objeto del carrito.
     * @return array Estadísticas.
     */
    public function get_cart_fasciculos_stats( $cart ) {
        $items_with_plan = $this->get_items_with_plan( $cart );
        $total_items = count( $items_with_plan );
        
        if ( 0 === $total_items ) {
            return array(
                'has_items' => false,
                'total_items' => 0,
                'current_weeks' => array(),
                'total_weeks' => array(),
            );
        }

        $current_weeks = array();
        $total_weeks = array();

        foreach ( $items_with_plan as $cart_item ) {
            $current_weeks[] = $this->get_current_week_number( $cart_item );
            $total_weeks[] = $this->get_total_weeks( $cart_item );
        }

        return array(
            'has_items' => true,
            'total_items' => $total_items,
            'current_weeks' => $current_weeks,
            'total_weeks' => $total_weeks,
        );
    }

    /**
     * Permitir que productos de fascículos sean comprables cuando se paga un pedido fallido
     *
     * Los productos de fascículos tienen precio 0€ pero deben poder comprarse
     * cuando forman parte de un pedido de renovación que se está pagando.
     *
     * @param bool       $is_purchasable Si el producto es comprable.
     * @param WC_Product $product Producto a verificar.
     * @return bool True si el producto es comprable.
     */
    public function allow_fasciculo_products_purchasable( $is_purchasable, $product ) {
        // Si ya es comprable, no hacer nada
        if ( $is_purchasable ) {
            return true;
        }

        // Verificar si el carrito contiene una renovación (WooCommerce Subscriptions)
        if ( $this->is_renewal_cart_context() ) {
            // En contexto de renovación, verificar si el producto es parte de una suscripción de fascículos
            if ( $this->is_product_part_of_fasciculo_renewal( $product ) ) {
                return true;
            }
        }

        // Verificar si estamos en el contexto de pagar un pedido
        if ( ! $this->is_paying_for_order_context() ) {
            return $is_purchasable;
        }

        // Obtener el pedido que se está pagando
        $order = $this->get_order_being_paid();
        if ( ! $order ) {
            return $is_purchasable;
        }

        // Verificar si el producto está en el pedido como producto de fascículo
        if ( $this->is_product_in_fasciculo_order( $product, $order ) ) {
            return true;
        }

        return $is_purchasable;
    }

    /**
     * Verificar si estamos en un contexto de renovación de WooCommerce Subscriptions
     *
     * @return bool True si estamos en contexto de renovación.
     */
    private function is_renewal_cart_context() {
        // Verificar si WooCommerce Subscriptions tiene la función
        if ( function_exists( 'wcs_cart_contains_renewal' ) && WC()->cart ) {
            if ( wcs_cart_contains_renewal() ) {
                return true;
            }
        }

        // Verificar si hay items de renovación en la sesión o carrito
        if ( function_exists( 'wcs_cart_contains_failed_renewal_order_payment' ) && WC()->cart ) {
            if ( wcs_cart_contains_failed_renewal_order_payment() ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si un producto es parte de una renovación de fascículos
     *
     * @param WC_Product $product Producto a verificar.
     * @return bool True si el producto es parte de una renovación de fascículos.
     */
    private function is_product_part_of_fasciculo_renewal( $product ) {
        if ( ! function_exists( 'wcs_cart_contains_renewal' ) || ! WC()->cart ) {
            return false;
        }

        $renewal_item = wcs_cart_contains_renewal();
        if ( ! $renewal_item || ! isset( $renewal_item['subscription_renewal'] ) ) {
            return false;
        }

        // Obtener la suscripción
        $subscription_id = isset( $renewal_item['subscription_renewal']['subscription_id'] ) 
            ? $renewal_item['subscription_renewal']['subscription_id'] 
            : 0;

        if ( ! $subscription_id || ! function_exists( 'wcs_get_subscription' ) ) {
            return false;
        }

        $subscription = wcs_get_subscription( $subscription_id );
        if ( ! $subscription ) {
            return false;
        }

        // Verificar si la suscripción tiene plan de fascículos
        $plan_cache = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( ! $plan_cache ) {
            return false;
        }

        // El producto es parte de una suscripción de fascículos - permitir
        return true;
    }

    /**
     * Verificar si un producto está en un pedido de fascículos
     *
     * @param WC_Product $product Producto a verificar.
     * @param WC_Order   $order Pedido a verificar.
     * @return bool True si el producto está en el pedido como fascículo.
     */
    private function is_product_in_fasciculo_order( $product, $order ) {
        $product_id = $product->get_id();

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Verificar si es el producto que queremos
            $item_product_id = $item->get_product_id();
            $item_variation_id = $item->get_variation_id();
            
            if ( $product_id === $item_product_id || $product_id === $item_variation_id ) {
                // Es un producto del pedido - verificar si es de fascículo
                $is_fasciculo = $item->get_meta( '_fasciculo_included' ) === 'yes' 
                    || $item->get_meta( '_product_item' ) === 'yes'
                    || $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
                
                if ( $is_fasciculo ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Validar el carrito cuando se paga un pedido fallido con productos de fascículos
     *
     * @param bool   $valid Si el item es válido para agregar al carrito.
     * @param int    $product_id ID del producto.
     * @param int    $quantity Cantidad.
     * @param int    $variation_id ID de la variación.
     * @param array  $variation Datos de la variación.
     * @param array  $cart_item_data Datos adicionales del item del carrito.
     * @return bool True si es válido agregar al carrito.
     */
    public function validate_fasciculo_add_to_cart( $valid, $product_id, $quantity, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
        // Si ya es válido, no hacer nada más
        if ( $valid ) {
            return true;
        }

        // Verificar si es un contexto de renovación de suscripciones
        if ( isset( $cart_item_data['subscription_renewal'] ) ) {
            $subscription_id = isset( $cart_item_data['subscription_renewal']['subscription_id'] ) 
                ? $cart_item_data['subscription_renewal']['subscription_id'] 
                : 0;
            
            if ( $subscription_id && function_exists( 'wcs_get_subscription' ) ) {
                $subscription = wcs_get_subscription( $subscription_id );
                if ( $subscription && $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE ) ) {
                    // Es una renovación de fascículos - permitir
                    return true;
                }
            }
        }

        // Verificar contexto de pagar pedido
        if ( $this->is_paying_for_order_context() ) {
            $order = $this->get_order_being_paid();
            if ( $order ) {
                $check_id = $variation_id ? $variation_id : $product_id;
                $product = wc_get_product( $check_id );
                
                if ( $product && $this->is_product_in_fasciculo_order( $product, $order ) ) {
                    return true;
                }
            }
        }

        return $valid;
    }

    /**
     * Caché para el pedido que se está pagando
     *
     * @var WC_Order|null|false
     */
    private $order_being_paid_cache = false;

    /**
     * Verificar si estamos en el contexto de pagar un pedido
     *
     * @return bool True si estamos pagando un pedido.
     */
    public function is_paying_for_order_context() {
        // Verificar página de pagar pedido
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' ) ) {
            return true;
        }

        // Verificar parámetro de pago de pedido en la URL
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['pay_for_order'] ) && isset( $_GET['key'] ) ) {
            return true;
        }

        // Verificar si estamos en checkout con un pedido para pagar
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['order-pay'] ) ) {
            return true;
        }

        // Verificar query vars global
        global $wp;
        if ( isset( $wp->query_vars['order-pay'] ) && absint( $wp->query_vars['order-pay'] ) > 0 ) {
            return true;
        }

        return false;
    }

    /**
     * Obtener el pedido que se está pagando actualmente
     *
     * @return WC_Order|null Pedido o null si no hay ninguno.
     */
    public function get_order_being_paid() {
        // Usar caché para evitar múltiples consultas
        if ( $this->order_being_paid_cache !== false ) {
            return $this->order_being_paid_cache;
        }

        $order_id = 0;

        // Intentar obtener el ID del pedido de diferentes fuentes
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( isset( $_GET['order-pay'] ) ) {
            $order_id = absint( $_GET['order-pay'] );
        }

        // También verificar el endpoint de order-pay
        global $wp;
        if ( ! $order_id && isset( $wp->query_vars['order-pay'] ) ) {
            $order_id = absint( $wp->query_vars['order-pay'] );
        }

        if ( ! $order_id ) {
            $this->order_being_paid_cache = null;
            return null;
        }

        $order = wc_get_order( $order_id );
        
        if ( ! $order || ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
            $this->order_being_paid_cache = null;
            return null;
        }

        $this->order_being_paid_cache = $order;
        return $order;
    }

    /**
     * Modificar el precio del carrito para pedidos de renovación fallidos
     *
     * Cuando se paga un pedido fallido, usar el precio original del pedido
     * en lugar de intentar obtenerlo del producto.
     *
     * @param WC_Cart $cart Objeto del carrito.
     * @return void
     */
    public function set_correct_prices_for_failed_orders( $cart ) {
        // No ejecutar en el panel de administración
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }

        // Verificar si estamos pagando un pedido
        if ( ! $this->is_paying_for_order_context() ) {
            return;
        }

        $order = $this->get_order_being_paid();
        if ( ! $order ) {
            return;
        }

        // Verificar si es un pedido de renovación con fascículos
        $has_fasciculos = false;
        $subscription_price = 0;

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Buscar el item de suscripción que tiene el precio real
            $plan_cache = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            $item_total = floatval( $item->get_total() );
            
            if ( $plan_cache && $item_total > 0 ) {
                $subscription_price = $item_total;
                $has_fasciculos = true;
                break;
            }
        }

        if ( ! $has_fasciculos || $subscription_price <= 0 ) {
            return;
        }

        // No necesitamos modificar los precios del carrito aquí
        // porque el checkout de pagar pedido usa los totales originales del pedido
    }

    /**
     * Filtrar errores del carrito para pedidos de fascículos
     *
     * Cuando se intenta pagar un pedido fallido, remover errores sobre
     * productos no comprables si son productos de fascículos.
     *
     * @param string $message Mensaje de error.
     * @param string $message_code Código del mensaje.
     * @return string Mensaje filtrado.
     */
    public function filter_cart_errors_for_fasciculos( $message, $message_code = '' ) {
        // No filtrar si no estamos pagando un pedido
        if ( ! $this->is_paying_for_order_context() ) {
            return $message;
        }

        // Lista de mensajes a filtrar en contexto de pagar pedido de fascículos
        $messages_to_filter = array(
            __( 'Sorry, this product cannot be purchased.', 'woocommerce' ),
            __( 'Lo siento, este producto no se puede comprar.', 'woocommerce' ),
        );

        foreach ( $messages_to_filter as $filter_msg ) {
            if ( strpos( $message, $filter_msg ) !== false ) {
                $order = $this->get_order_being_paid();
                if ( $order && $this->order_has_fasciculo_products( $order ) ) {
                    // Suprimir el mensaje - retornar vacío
                    return '';
                }
            }
        }

        return $message;
    }

    /**
     * Verificar si un pedido tiene productos de fascículos
     *
     * @param WC_Order $order Pedido a verificar.
     * @return bool True si tiene productos de fascículos.
     */
    public function order_has_fasciculo_products( $order ) {
        if ( ! $order ) {
            return false;
        }

        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            if ( $item->get_meta( '_fasciculo_included' ) === 'yes' 
                || $item->get_meta( '_product_item' ) === 'yes'
                || $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE ) ) {
                return true;
            }
        }

        return false;
    }
}