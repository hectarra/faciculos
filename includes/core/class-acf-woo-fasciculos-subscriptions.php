<?php
/**
 * Manejador de suscripciones para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar toda la funcionalidad relacionada con suscripciones
 */
class ACF_Woo_Fasciculos_Subscriptions {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Manejar la activación de una suscripción
     *
     * Este método se ejecuta cuando una suscripción se activa por primera vez.
     * Prepara la suscripción para la siguiente renovación.
     *
     * @param WC_Subscription $subscription Suscripción que se activó.
     * @return void
     */
    public function on_subscription_activated( $subscription ) {
        // Validar la suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        // Obtener el plan de la suscripción
        $plan = $this->get_subscription_plan( $subscription );
        if ( empty( $plan ) ) {
            return;
        }

        // Verificar si ya se procesó esta activación
        if ( $this->is_first_update_done( $subscription ) ) {
            return;
        }

        // Obtener el índice actual
        $current_index = $this->get_active_index( $subscription );
        
        // Solo procesar si estamos en la primera semana (índice 0)
        if ( 0 !== $current_index ) {
            return;
        }

        // Preparar para la siguiente semana
        $this->add_product_to_subscription_order( $subscription, $plan );
        $this->prepare_next_week( $subscription, $plan );
    }

    /**
     * Verificar cuando se completa el pago de una suscripción
     *
     * @param int $order_id ID del pedido.
     * @return void
     */
    public function on_payment_complete_check_subscription( $order_id ) {
        $order = wc_get_order( $order_id );
        
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
            return;
        }

        // Verificar si el pedido contiene suscripciones
        if ( ! function_exists( 'wcs_order_contains_subscription' ) || ! wcs_order_contains_subscription( $order_id ) ) {
            return;
        }

        // Obtener las suscripciones del pedido
        $subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'parent' ) );
        
        foreach ( $subscriptions as $subscription ) {
            // Solo procesar suscripciones principales (no renovaciones)
            if ( $subscription->get_parent_id() == $order_id ) {
                $this->on_subscription_activated( $subscription );
            }
        }
    }

    /**
     * Modificar items antes de copiar a pedido de renovación
     *
     * @param array           $items Items del pedido.
     * @param WC_Subscription $subscription Suscripción.
     * @param WC_Order        $renewal_order Pedido de renovación.
     * @return array Items modificados.
     */
public function modify_renewal_items_before_copy( $items, $subscription, $renewal_order ) {
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return $items;
    }

    $plan = $this->get_subscription_plan( $subscription );
    if ( empty( $plan ) ) {
        return $items;
    }

    $current_active = $this->get_active_index( $subscription );
    
    $row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $current_active );
    if ( ! $row ) {
        return $items;
    }

    $new_product = wc_get_product( intval( $row['product_id'] ) );
    if ( ! $new_product ) {
        return $items;
    }

    $new_items = $this->create_renewal_items( $items, $new_product, $row, $current_active, $plan );
    
    return $new_items;
}

    /**
     * Obtener el plan de fascículos de una suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return array Plan de fascículos.
     */
    private function get_subscription_plan( $subscription ) {
        // Obtener el plan desde los metadatos de la suscripción
        $plan_json = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        
        if ( ! $plan_json ) {
            return array();
        }

        $plan = json_decode( $plan_json, true );
        return is_array( $plan ) ? $plan : array();
    }

    /**
     * Obtener el índice activo de una suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return int Índice activo.
     */
    private function get_active_index( $subscription ) {
        $index = $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
        return '' !== $index && $index !== null ? intval( $index ) : 0;
    }

    /**
     * Verificar si la primera actualización ya fue realizada
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return bool True si ya se realizó.
     */
    private function is_first_update_done( $subscription ) {
        return ! ! $subscription->get_meta( ACF_Woo_Fasciculos::META_FIRST_UPDATE );
    }

    /**
     * Marcar la primera actualización como realizada
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return void
     */
    private function mark_first_update_done( $subscription ) {
        $subscription->update_meta_data( ACF_Woo_Fasciculos::META_FIRST_UPDATE, 'yes' );
        $subscription->save();
    }

        /**
 * Verificar si el plan de fascículos está completado
 *
 * @param int $subscription_id ID de la suscripción.
 * @return void
 */
public function check_if_plan_completed( $subscription_id ) {
    $subscription = wcs_get_subscription( $subscription_id );
    
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return;
    }

    // Obtener el plan
    $plan = $this->get_subscription_plan( $subscription );
    if ( empty( $plan ) ) {
        return;
    }

    // Obtener el índice activo
    $active = $this->get_active_index( $subscription );
    
    // Si estamos en la última semana, marcar para cancelación pendiente
    if ( $active >= ( count( $plan ) - 1 ) ) {
        // Marcar que la suscripción está pendiente de cancelación
        $subscription->update_meta_data( '_fasciculos_pending_cancellation', 'yes' );
        $subscription->save();
        
        // Agregar nota informativa
        $subscription->add_order_note( __( '⏳ Plan de fascículos completado. La suscripción se cancelará cuando se confirme el pago de esta renovación.', 'acf-woo-fasciculos' ) );
    }
}

/**
 * Procesar la cancelación pendiente cuando se complete el pago
 *
 * @param int $order_id ID del pedido.
 * @return void
 */
public function process_pending_cancellation( $order_id ) {
    $order = wc_get_order( $order_id );
    
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
        return;
    }

    // Obtener suscripciones del pedido
    $subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'any' ) );
    
    foreach ( $subscriptions as $subscription ) {
        // Verificar si hay cancelación pendiente
        if ( $subscription->get_meta( '_fasciculos_pending_cancellation' ) === 'yes' ) {
            // Verificar que el pedido esté pagado
            if ( $order->is_paid() ) {
                // Cancelar la suscripción
                $subscription->delete_meta_data( '_fasciculos_pending_cancellation' );
                $subscription->update_status(
                    'cancelled',
                    __( 'Plan de fascículos completado y pago confirmado.', 'acf-woo-fasciculos' )
                );
                $subscription->add_order_note( __( '🎉 Suscripción cancelada tras confirmación del pago del último fascículo.', 'acf-woo-fasciculos' ) );
                $subscription->save();
            }
        }
    }
}

/**
 * Agregar Producto al pedido de suscripción inicial
 *
 * @param WC_Subscription $subscription Suscripción.
 * @param array           $plan Plan de fascículos.
 * @return void
 */
private function add_product_to_subscription_order( $subscription, $plan ) {
    $first_row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, 0 );
    if ( ! $first_row ) {
        return;
    }

    $product_product = wc_get_product( intval( $first_row['product_id'] ) );
    if ( ! $product_product ) {
        return;
    }

    $parent_order = wc_get_order( $subscription->get_parent_id() );
    if ( ! $parent_order ) {
        return;
    }

    $qty = 1;
    $product_price = floatval( $first_row['price'] );

    $item = new WC_Order_Item_Product();
    $item->set_product( $product_product );
    $item->set_name( $product_product->get_name() );
    $item->set_quantity( $qty );
    $item->set_subtotal( $product_price * $qty );
    $item->set_total( $product_price * $qty );
    $item->set_tax_class( $product_product->get_tax_class() );
    
    $item->add_meta_data( '_product_item', 'yes' );
    $item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, 0 );
    $item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
    
    $item->save();
    
    $parent_order->add_item( $item );
    
    // Ajustar el precio de la suscripción a 0€ en el pedido padre
    foreach ( $parent_order->get_items() as $order_item_id => $order_item ) {
        if ( $order_item instanceof WC_Order_Item_Product ) {
            $product = $order_item->get_product();
            if ( $product && $product->is_type( 'subscription' ) ) {
                $order_item->set_subtotal( 0 );
                $order_item->set_total( 0 );
                $order_item->save();
            }
        }
    }
    
    $parent_order->calculate_totals();
    $parent_order->save();

    $subscription->add_order_note( sprintf(
        __( '📦 Producto inicial agregado al pedido: %1$s — %2$s', 'acf-woo-fasciculos' ),
        $product_product->get_name(),
        ACF_Woo_Fasciculos_Utils::format_price( $product_price )
    ));
}

/**
 * Preparar la suscripción para la siguiente renovación después de una renovación
 *
 * @param WC_Subscription $subscription Suscripción.
 * @param array           $plan Plan de fascículos.
 * @param int             $current_index Índice actual.
 * @return void
 */
private function prepare_next_week_after_renewal( $subscription, $plan, $current_index ) {
    // Calcular el siguiente índice
    $next_index = $current_index + 1;
    $next_row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $next_index );

    if ( $next_row ) {
        // Hay siguiente semana, preparar la suscripción
        $this->update_subscription_for_next_week( $subscription, $next_index, $next_row, $plan );
    } else {
        // No hay más semanas, completar suscripción
        $this->complete_subscription( $subscription );
    }
}

    /**
     * Preparar la suscripción para la siguiente semana
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param array           $plan Plan de fascículos.
     * @return void
     */
    private function prepare_next_week( $subscription, $plan ) {
        // Calcular el siguiente índice
        $next_index = 1; // Después de la semana 0, vamos a la semana 1
        $next_row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $next_index );

        if ( $next_row ) {
            // Hay siguiente semana, preparar la suscripción
            $this->update_subscription_for_next_week( $subscription, $next_index, $next_row, $plan );
        } else {
            // Solo hay una semana en el plan
            $this->handle_single_week_plan( $subscription );
        }
    }

    /**
     * Actualizar la suscripción para la siguiente semana
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param int             $next_index Índice de la siguiente semana.
     * @param array           $next_row Datos de la siguiente semana.
     * @param array           $plan Plan completo.
     * @return void
     */
    private function update_subscription_for_next_week( $subscription, $next_index, $next_row, $plan ) {
        // Actualizar el total recurrente de la suscripción
        $this->update_subscription_recurring_total( $subscription, $next_index, $plan );

        // Obtener el nombre del producto
        $next_product_name = ACF_Woo_Fasciculos_Utils::get_product_name( $next_row['product_id'] );

        // Agregar nota informativa
        $subscription->add_order_note( sprintf(
            /* translators: 1: next week number, 2: total weeks, 3: product name, 4: price */
            __( '🔄 Suscripción actualizada para próxima renovación (semana %1$d/%2$d): %3$s — %4$s', 'acf-woo-fasciculos' ),            $next_index + 1,
            count( $plan ),
            $next_product_name,
            ACF_Woo_Fasciculos_Utils::format_price( $next_row['price'] )
        ));

        // Marcar como actualizado
        $this->mark_first_update_done( $subscription );
    }

    /**
     * Manejar un plan de una sola semana
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return void
     */
    private function handle_single_week_plan( $subscription ) {
        $subscription->add_order_note( __( '⚠️ Plan de 1 semana. La suscripción se cancelará en la próxima renovación.', 'acf-woo-fasciculos' ) );
        $this->mark_first_update_done( $subscription );
    }

    /**
     * Actualizar el total recurrente de la suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param int             $week_index Índice de la semana.
     * @param array           $plan Plan de fascículos.
     * @return void
     */
    private function update_subscription_recurring_total( $subscription, $week_index, $plan ) {
        $row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $week_index );
        
        if ( ! $row ) {
            return;
        }

        $new_product = wc_get_product( intval( $row['product_id'] ) );
        if ( ! $new_product ) {
            return;
        }

        $new_price = floatval( $row['price'] );

        // Actualizar cada item de la suscripción
        foreach ( $subscription->get_items() as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $qty = max( 1, intval( $item->get_quantity() ) );

            // Actualizar producto y precio
            $item->set_product( $new_product );
            $item->set_name( $new_product->get_name() );
            $item->set_product_id( $new_product->get_id() );
            $item->set_subtotal( $new_price * $qty );
            $item->set_total( $new_price * $qty );
            $item->save();
        }

        // Recalcular totales
        $subscription->calculate_totals();
        $subscription->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $week_index );
        $subscription->save();
    }

    /**
     * Crear items de renovación con el producto correcto
     *
     * @param array      $items Items originales.
     * @param WC_Product $new_product Nuevo producto.
     * @param array      $row Datos de la semana.
     * @param int        $current_active Índice actual.
     * @param array      $plan Plan completo.
     * @return array Items modificados.
     */
    private function create_renewal_items( $items, $new_product, $row, $current_active, $plan ) {
        $new_items = array();
        $new_price = floatval( $row['price'] );
        $original_product_item = null;

        // 1. Separate product items from other items (shipping, fees, etc.)
        foreach ( $items as $item_id => $item ) {
            if ( $item instanceof WC_Order_Item_Product ) {
                $original_product_item = $item;
            } else {
                $new_items[ $item_id ] = $item;
            }
        }

        // If for some reason there's no product in the original items, abort.
        if ( ! $original_product_item ) {
            return $items; // Return original items to be safe
        }

        $bundle_products = ACF_Woo_Fasciculos_Utils::get_bundle_products_if_bundle( $new_product->get_id() );

        if ( ! empty( $bundle_products ) ) {
            // 2. It's a bundle. Add each child product.
            $qty = max( 1, intval( $original_product_item->get_quantity() ) );

            foreach ( $bundle_products as $bundle_product ) {
                $new_item = new WC_Order_Item_Product();
                $new_item->set_product( $bundle_product );
                $new_item->set_name( $bundle_product->get_name() );
                $new_item->set_quantity( $qty );
                // Price is handled by the subscription total, set to 0 to avoid double charging
                $new_item->set_subtotal( 0 );
                $new_item->set_total( 0 );
                $new_item->set_tax_class( $bundle_product->get_tax_class() );

                // Copy metadata
                $new_item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $current_active );
                $new_item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );

                $new_items[] = $new_item; // Use [] to add to the array without a specific key
            }

        } else {
            // 3. It's not a bundle. Use the existing logic.
            $qty = max( 1, intval( $original_product_item->get_quantity() ) );
            $new_item = new WC_Order_Item_Product();

            $new_item->set_product( $new_product );
            $new_item->set_name( $new_product->get_name() );
            $new_item->set_quantity( $qty );
            $new_item->set_subtotal( $new_price * $qty );
            $new_item->set_total( $new_price * $qty );
            $new_item->set_tax_class( $new_product->get_tax_class() );

            // Copy metadata
            $new_item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $current_active );
            $new_item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );

            $new_items[] = $new_item;
        }

        return $new_items;
    }

    /**
     * Completar una suscripción cuando se termina el plan
     *
     * @param WC_Subscription $subscription Suscripción a completar.
     * @return void
     */
    private function complete_subscription( $subscription ) {
        $subscription->update_status(
            'cancelled',
            __( 'Plan de fascículos completado. Todas las semanas han sido enviadas.', 'acf-woo-fasciculos' )
        );
        
        $subscription->add_order_note( __( '🎉 Plan de fascículos completado. Suscripción cancelada automáticamente.', 'acf-woo-fasciculos' ) );
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

        $plan = $this->get_subscription_plan( $subscription );
        $active_index = $this->get_active_index( $subscription );
        
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
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return array(
            'has_plan' => false,
            'progress_percentage' => 0,
            'weeks_completed' => 0,
            'weeks_remaining' => 0,
            'is_complete' => false,
        );
    }

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