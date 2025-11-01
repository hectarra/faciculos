<?php
/**
 * Clase para manejar suscripciones y renovaciones
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar suscripciones y renovaciones
 */
class ACF_Woo_Fasciculos_Subscriptions {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Inicializar hooks
     *
     * @return void
     */
    private function init_hooks() {
        // Manejar la activación de la suscripción
        add_action( 'woocommerce_subscription_activated', array( $this, 'handle_subscription_activated' ), 10, 1 );

        // Manejar el cambio de estado de los pedidos de renovación
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_renewal_order_status_change' ), 10, 4 );

        // Programar la cancelación de la suscripción cuando se complete el plan
        add_action( 'acf_woo_fasciculos_cancel_subscription', array( $this, 'cancel_subscription_after_plan_completion' ), 10, 1 );

        // Agregar información del plan al panel de administración de la suscripción
        add_action( 'woocommerce_admin_subscription_data_after_subscription_details', array( $this, 'add_plan_info_to_subscription_admin' ), 10, 1 );

        // HPOS compatibility: Ensure subscription metadata works with new storage
        add_action( 'woocommerce_subscription_object_updated_props', array( $this, 'handle_subscription_props_update' ), 10, 2 );

        // Añadir producto de la semana al pedido inicial
        add_action( 'woocommerce_checkout_subscription_created', array( $this, 'add_weekly_product_to_initial_order' ), 15, 3 );

        // Añadir producto de la semana a pedidos de renovación
        add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'add_weekly_product_to_renewal_order' ), 15, 2 );
    }

    /**
     * Manejar la activación de la suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return void
     */
    public function handle_subscription_activated( $subscription ) {
        $plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( is_string( $plan ) ) {
            $plan = json_decode( $plan, true );
        }

        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return;
        }

        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
        $current_week = isset( $plan[ $active_index ] ) ? $plan[ $active_index ] : null;

        if ( ! $current_week ) {
            return;
        }

        // Agregar nota informativa
        if ( count( $plan ) === 1 ) {
            $subscription->add_order_note( __( '⚠️ Plan de 1 semana. La suscripción se cancelará en la próxima renovación.', 'acf-woo-fasciculos' ) );
        } else {
            $next_week = isset( $plan[ $active_index + 1 ] ) ? $plan[ $active_index + 1 ] : null;
            $subscription->add_order_note( sprintf(
                /* translators: 1: next week number, 2: total weeks, 3: product name, 4: price/note */
                __( '🎉 Suscripción activada - Semana 1 completada. Próxima renovación (semana %1$d/%2$d): %3$s — %4$s', 'acf-woo-fasciculos' ),
                $active_index + 2,
                count( $plan ),
                $next_week && isset( $next_week['product'] ) ? get_the_title( $next_week['product'] ) : '',
                $next_week && isset( $next_week['note'] ) ? $next_week['note'] : ''
            ) );
        }
    }

    /**
     * Manejar el cambio de estado de los pedidos de renovación
     *
     * @param int $order_id ID del pedido.
     * @param string $status_from Estado anterior.
     * @param string $status_to Estado nuevo.
     * @param WC_Order $order Pedido.
     * @return void
     */
    public function handle_renewal_order_status_change( $order_id, $status_from, $status_to, $order ) {
        // Solo procesar pedidos completados o en procesamiento
        if ( 'completed' !== $status_to && 'processing' !== $status_to ) {
            return;
        }

        // Verificar si es un pedido de renovación
        if ( ! function_exists( 'wcs_order_contains_renewal' ) || ! wcs_order_contains_renewal( $order ) ) {
            return;
        }

        // Obtener la(s) suscripción(es) asociada(s)
        $subscriptions = wcs_get_subscriptions_for_renewal_order( $order );

        foreach ( $subscriptions as $subscription ) {
            $this->process_subscription_renewal( $subscription, $order );
        }
    }

    /**
     * Procesar la renovación de la suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param WC_Order $order Pedido de renovación.
     * @return void
     */
    private function process_subscription_renewal( $subscription, $order ) {
        $plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( is_string( $plan ) ) {
            $plan = json_decode( $plan, true );
        }

        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return;
        }

        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
        $current_week = isset( $plan[ $active_index ] ) ? $plan[ $active_index ] : null;

        if ( ! $current_week ) {
            return;
        }

        // Agregar nota informativa al pedido
        $order->add_order_note( sprintf(
            __( '📦 Fascículo semana %1$d/%2$d: %3$s — %4$s', 'acf-woo-fasciculos' ),
            $active_index + 1,
            count( $plan ),
            isset( $current_week['product'] ) ? get_the_title( $current_week['product'] ) : '',
            isset( $current_week['note'] ) ? $current_week['note'] : ''
        ) );

        // Añadir el producto de la semana al pedido de renovación (si no existe ya)
        $this->add_weekly_product_to_order( $subscription, $order, $active_index );

        // Si es la última semana, agregar nota especial
        if ( $active_index + 1 >= count( $plan ) ) {
            $order->add_order_note( __( '🎉 Plan de fascículos completado al confirmar la renovación.', 'acf-woo-fasciculos' ) );
        }
    }

    /**
     * Cancelar la suscripción después de completar el plan
     *
     * @param int $subscription_id ID de la suscripción.
     * @return void
     */
    public function cancel_subscription_after_plan_completion( $subscription_id ) {
        $subscription = wcs_get_subscription( $subscription_id );

        if ( ! $subscription ) {
            return;
        }

        // Verificar que el plan esté marcado como completado
        if ( 'yes' !== $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_COMPLETED ) ) {
            return;
        }

        // Cancelar la suscripción
        $subscription->update_status( 'cancelled', __( 'Plan de fascículos completado. Todas las semanas han sido enviadas.', 'acf-woo-fasciculos' ) );
    }

    /**
     * Agregar información del plan al panel de administración de la suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return void
     */
    public function add_plan_info_to_subscription_admin( $subscription ) {
        $plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( is_string( $plan ) ) {
            $plan = json_decode( $plan, true );
        }
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return;
        }

        $current_week = isset( $plan[ $active_index ] ) ? $plan[ $active_index ] : null;

        if ( ! $current_week ) {
            return;
        }

        echo '<div class="fasciculos-info" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-left: 3px solid #2271b1; font-size: 13px;">';
        echo '<h4 style="margin: 0 0 10px 0; color: #2271b1;">' . __( 'Plan de Fascículos', 'acf-woo-fasciculos' ) . '</h4>';
        echo '<p style="margin: 0 0 5px 0;"><strong>' . __( 'Semana actual:', 'acf-woo-fasciculos' ) . '</strong> ' . sprintf( '%1$s de %2$s', $active_index + 1, count( $plan ) ) . '</p>';

        if ( isset( $current_week['product'] ) && $current_week['product'] ) {
            echo '<p style="margin: 0 0 5px 0;"><strong>' . __( 'Producto:', 'acf-woo-fasciculos' ) . '</strong> ' . get_the_title( $current_week['product'] ) . '</p>';
        }

        if ( isset( $current_week['price'] ) && $current_week['price'] ) {
            echo '<p style="margin: 0 0 5px 0;"><strong>' . __( 'Precio:', 'acf-woo-fasciculos' ) . '</strong> ' . wc_price( $current_week['price'] ) . '</p>';
        }

        if ( isset( $current_week['note'] ) && $current_week['note'] ) {
            echo '<p style="margin: 0;"><strong>' . __( 'Nota:', 'acf-woo-fasciculos' ) . '</strong> ' . esc_html( $current_week['note'] ) . '</p>';
        }

        echo '</div>';
    }

    /**
     * Manejar actualizaciones de propiedades de suscripción con HPOS
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param array $updated_props Propiedades actualizadas.
     * @return void
     */
    public function handle_subscription_props_update( $subscription, $updated_props ) {
        // Asegurar que los metadatos del plan se mantengan sincronizados con HPOS
        if ( isset( $updated_props['meta_data'] ) ) {
            $plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            if ( ! empty( $plan ) ) {
                // Verificar que el caché esté actualizado
                $cached_plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
                $current_cache = wp_json_encode( $plan );

                if ( $cached_plan !== $current_cache ) {
                    $subscription->update_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, $current_cache );
                }
            }
        }
    }

    /**
     * Añadir producto de la semana al pedido inicial
     *
     * @param WC_Subscription $subscription Suscripción creada.
     * @param WC_Order $order Pedido.
     * @param int $recurring_cart Cart recurrente.
     * @return void
     */
    public function add_weekly_product_to_initial_order( $subscription, $order, $recurring_cart ) {
        $this->add_weekly_product_to_order( $subscription, $order, 0 );
    }

    /**
     * Añadir producto de la semana a pedidos de renovación
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param WC_Order $order Pedido de renovación.
     * @return void
     */
    public function add_weekly_product_to_renewal_order( $subscription, $order ) {
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
        $this->add_weekly_product_to_order( $subscription, $order, $active_index );
    }

    /**
     * Añadir producto de la semana específica al pedido
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param WC_Order $order Pedido.
     * @param int $week_index Índice de la semana.
     * @return void
     */
    private function add_weekly_product_to_order( $subscription, $order, $week_index ) {
        $plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( is_string( $plan ) ) {
            $plan = json_decode( $plan, true );
        }

        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return;
        }

        // Obtener la semana actual
        $current_week = isset( $plan[ $week_index ] ) ? $plan[ $week_index ] : null;

        if ( ! $current_week || ! isset( $current_week['product'] ) || ! $current_week['product'] ) {
            return;
        }

        $weekly_product_id = $current_week['product'];
        $weekly_product = wc_get_product( $weekly_product_id );

        if ( ! $weekly_product ) {
            return;
        }

        // Comprobar si el pedido ya contiene un item del producto semanal
        foreach ( $order->get_items() as $existing_item ) {
            if ( $existing_item instanceof WC_Order_Item_Product && intval( $existing_item->get_product_id() ) === intval( $weekly_product_id ) ) {
                // Ya existe: no añadir duplicado
                return;
            }
        }

        // Crear nuevo item para el producto de la semana
        $item = new WC_Order_Item_Product();
        $item->set_product( $weekly_product );
        $item->set_quantity( 1 );

        // Establecer precio si está definido
        if ( isset( $current_week['price'] ) && $current_week['price'] ) {
            $item->set_subtotal( $current_week['price'] );
            $item->set_total( $current_week['price'] );
        } else {
            // Usar el precio regular del producto
            $price = $weekly_product->get_price();
            $item->set_subtotal( $price );
            $item->set_total( $price );
        }

        // Añadir metadatos informativos
        $item->add_meta_data( ACF_Woo_Fasciculos::META_WEEKLY_PRODUCT, 'yes' );
        $item->add_meta_data( __( 'Semana Actual', 'acf-woo-fasciculos' ), sprintf( 'Semana %d de %d', $week_index + 1, count( $plan ) ) );

        if ( isset( $current_week['note'] ) && $current_week['note'] ) {
            $item->add_meta_data( __( 'Nota', 'acf-woo-fasciculos' ), $current_week['note'] );
        }

        // Añadir el item al pedido
        $order->add_item( $item );

        // Recalcular totales del pedido
        $order->calculate_totals();

        // Guardar el pedido
        $order->save();

        // Agregar nota informativa al pedido
        $order->add_order_note( sprintf(
            __( '📦 Producto de la semana añadido: %1$s (Semana %2$d de %3$d)', 'acf-woo-fasciculos' ),
            $weekly_product->get_name(),
            $week_index + 1,
            count( $plan )
        ) );
    }

    /**
     * Obtener suscripción compatible con HPOS
     *
     * @param int $subscription_id ID de la suscripción.
     * @return WC_Subscription|false Suscripción o false si no existe.
     */
    public function get_hpos_compatible_subscription( $subscription_id ) {
        // Usar el método moderno para obtener suscripciones con HPOS
        if ( function_exists( 'wcs_get_subscription' ) ) {
            return wcs_get_subscription( $subscription_id );
        }

        // Fallback para compatibilidad hacia atrás
        return false;
    }

    /**
     * Verificar si el almacenamiento de suscripciones usa HPOS
     *
     * @return bool True si usa HPOS.
     */
    public function is_hpos_enabled_for_subscriptions() {
        if ( ! class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Obtener información de una suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return array Información de la suscripción.
     */
    public function get_subscription_info( $subscription ) {
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return array();
        }

        $plan = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( is_string( $plan ) ) {
            $plan = json_decode( $plan, true );
        }
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        return array(
            'has_plan' => ! empty( $plan ),
            'total_weeks' => count( $plan ),
            'current_week' => $active_index + 1,
            'active_index' => $active_index,
            'is_first_update_done' => $this->is_first_update_done( $subscription ),
            'plan' => $plan,
        );
    }

    /**
     * Obtener el progreso de la suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return array Progreso de la suscripción.
     */
    public function get_subscription_progress( $subscription ) {
        $info = $this->get_subscription_info( $subscription );
        
        if ( empty( $info ) || ! $info['has_plan'] ) {
            return array(
                'has_plan' => false,
                'progress_percentage' => 0,
                'weeks_completed' => 0,
                'weeks_remaining' => 0,
                'is_complete' => false,
            );
        }

        $weeks_completed = $info['current_week'];
        $total_weeks = $info['total_weeks'];
        $weeks_remaining = max( 0, $total_weeks - $weeks_completed );
        $progress_percentage = $total_weeks > 0 ? ( $weeks_completed / $total_weeks ) * 100 : 0;
        $is_complete = $weeks_completed >= $total_weeks;

        return array(
            'has_plan' => true,
            'progress_percentage' => round( $progress_percentage, 2 ),
            'weeks_completed' => $weeks_completed,
            'weeks_remaining' => $weeks_remaining,
            'is_complete' => $is_complete,
            'total_weeks' => $total_weeks,
            'current_week' => $info['current_week'],
        );
    }
}