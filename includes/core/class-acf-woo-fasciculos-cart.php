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
        // Verificar si el item tiene plan de fascículos
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

        // Recorrer todos los items del carrito
        foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
            $this->process_cart_item_price( $cart_item_key, $cart_item );
        }
    }

    /**
     * Procesar el precio de un item del carrito
     *
     * @param string $cart_item_key Clave del item del carrito.
     * @param array  $cart_item Item del carrito.
     * @return void
     */
    private function process_cart_item_price( $cart_item_key, $cart_item ) {
        // Obtener el producto del item
        $product = ACF_Woo_Fasciculos_Utils::get_product_from_cart_item( $cart_item );
        
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
}