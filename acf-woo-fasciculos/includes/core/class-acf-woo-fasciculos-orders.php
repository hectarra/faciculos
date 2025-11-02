<?php
/**
 * Manejador de pedidos para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar toda la funcionalidad relacionada con pedidos
 */
class ACF_Woo_Fasciculos_Orders {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Guardar el plan de fascículos en un item del pedido
     *
     * Este método se ejecuta cuando se crea un item de pedido durante el checkout.
     * Guarda el plan de fascículos como metadatos del item.
     *
     * @param WC_Order_Item_Product $item Item del pedido.
     * @param string                $cart_item_key Clave del item del carrito.
     * @param array                 $values Valores del item del carrito.
     * @param WC_Order              $order Pedido.
     * @return void
     */
    public function save_plan_to_order_item( $item, $cart_item_key, $values, $order ) {
        // Guardar el plan como JSON
        if ( isset( $values[ ACF_Woo_Fasciculos::META_PLAN_CACHE ] ) ) {
            $item->add_meta_data(
                ACF_Woo_Fasciculos::META_PLAN_CACHE,
                wp_json_encode( $values[ ACF_Woo_Fasciculos::META_PLAN_CACHE ] ),
                false
            );
        }

        // Guardar el índice activo
        if ( isset( $values[ ACF_Woo_Fasciculos::META_ACTIVE_INDEX ] ) ) {
            $item->add_meta_data(
                ACF_Woo_Fasciculos::META_ACTIVE_INDEX,
                intval( $values[ ACF_Woo_Fasciculos::META_ACTIVE_INDEX ] ),
                false
            );
        }
    }

    /**
     * Copiar el plan de fascículos a la suscripción
     *
     * Este método se ejecuta cuando se crea una suscripción desde un pedido.
     * Copia el plan de fascículos desde los items del pedido a la suscripción.
     *
     * @param WC_Subscription $subscription Suscripción creada.
     * @param WC_Order        $order Pedido original.
     * @param mixed           $recurring_cart Carrito recurrente.
     * @return void
     */
    public function copy_plan_to_subscription( $subscription, $order, $recurring_cart = null ) {
        // Validar suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        // Verificar que la suscripción tenga un ID válido
        if ( ! $subscription->get_id() ) {
            return;
        }

        // Validar pedido
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
            return;
        }

        $plan_copied = false;

        // Recorrer los items del pedido
        foreach ( $order->get_items() as $order_item_id => $order_item ) {
            // Copiar el plan
            $plan_json = $order_item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            if ( $plan_json ) {
                $subscription->update_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, $plan_json );
                $plan_copied = true;
            }

            // Copiar el índice activo
            $index = $order_item->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
            if ( '' !== $index && $index !== null ) {
                $subscription->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, intval( $index ) );
            } else {
                $subscription->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, 0 );
            }
        }

        // Guardar cambios si se copió algún plan
        if ( $plan_copied ) {
            $subscription->save();
        }
    }

    /**
     * Manejar la creación de un pedido de renovación
     *
     * @param WC_Order        $renewal_order Pedido de renovación.
     * @param WC_Subscription $subscription Suscripción relacionada.
     * @return void
     */
public function on_renewal_order_created( $renewal_order, $subscription ) {
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $renewal_order ) || ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return;
    }

    $plan = $this->get_subscription_plan( $subscription );
    if ( empty( $plan ) ) {
        return;
    }

    $active = $this->get_active_index( $subscription );
    
    $row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $active );
    if ( ! $row ) {
        return;
    }

    foreach ( $renewal_order->get_items() as $item_id => $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }

        $item->delete_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
        $item->delete_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        
        $item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $active, true );
        $item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ), true );
        $item->save();
    }

    $renewal_order->save();

    $product_name = ACF_Woo_Fasciculos_Utils::get_product_name( $row['product_id'] );
    $renewal_order->add_order_note( sprintf(
        __( '📦 Fascículo semana %1$d/%2$d: %3$s — %4$s', 'acf-woo-fasciculos' ),
        $active + 1,
        count( $plan ),
        $product_name,
        ACF_Woo_Fasciculos_Utils::format_price( $row['price'] )
    ) );
}


/**
 * Actualizar metadatos de items en pedido de renovación
 *
 * @param WC_Order        $renewal_order Pedido de renovación.
 * @param WC_Subscription $subscription Suscripción relacionada.
 * @return void
 */
public function update_renewal_order_items_meta( $renewal_order, $subscription ) {
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $renewal_order ) || ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return;
    }

    $plan = $this->get_subscription_plan( $subscription );
    if ( empty( $plan ) ) {
        return;
    }

    $active_index = $this->get_active_index( $subscription );

    foreach ( $renewal_order->get_items() as $item_id => $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }

        $item->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $active_index );
        $item->update_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
        $item->save();
    }

    $renewal_order->save();
}

    /**
     * Manejar cambios de estado en pedidos de renovación
     *
     * Este método se ejecuta cuando cambia el estado de un pedido.
     * Si es un pedido de renovación y cambia a 'processing' o 'completed',
     * avanza el plan de fascículos.
     *
     * @param int    $order_id ID del pedido.
     * @param string $old_status Estado anterior.
     * @param string $new_status Nuevo estado.
     * @param WC_Order $order Objeto del pedido.
     * @return void
     */
    public function on_order_status_progresses_renewal( $order_id, $old_status, $new_status, $order ) {
        // Solo nos interesan cambios a 'processing' o 'completed'
        if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
            return;
        }

        // Obtener el pedido si no lo tenemos
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        // Verificar que tengamos un pedido válido
        if ( ! $order ) {
            return;
        }

        // Verificar si es un pedido de renovación
        if ( ! ACF_Woo_Fasciculos_Utils::is_renewal_order( $order ) ) {
            return;
        }

        // Obtener las suscripciones asociadas
        $subscriptions = ACF_Woo_Fasciculos_Utils::get_renewal_subscriptions( $order_id );
        
        foreach ( $subscriptions as $subscription ) {
            $this->process_renewal_completion( $subscription, $order_id, $new_status );
        }
    }

    /**
     * Procesar la finalización de una renovación
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param int             $order_id ID del pedido de renovación.
     * @param string          $new_status Nuevo estado del pedido.
     * @return void
     */
    private function process_renewal_completion( $subscription, $order_id, $new_status ) {
        // Validar suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        // Verificar si este pedido ya fue procesado
        if ( ACF_Woo_Fasciculos_Utils::is_order_processed( $subscription, $order_id ) ) {
            return;
        }

        // Obtener el plan de la suscripción
        $plan = $this->get_subscription_plan( $subscription );
        if ( empty( $plan ) ) {
            return;
        }

        // Obtener el índice actual
        $current_active = $this->get_active_index( $subscription );
        
        // Calcular el siguiente índice
        $next_index = $current_active + 1;
        $next_row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $next_index );

        if ( $next_row ) {
            // Hay siguiente semana, actualizar la suscripción
            $this->advance_to_next_week( $subscription, $next_index, $next_row, $plan, $order_id, $new_status, $current_active );
        } else {
            // No hay siguiente semana, completar el plan
            $this->complete_subscription_plan( $subscription, $order_id );
        }
    }

    /**
     * Avanzar a la siguiente semana
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param int             $next_index Índice de la siguiente semana.
     * @param array           $next_row Datos de la siguiente semana.
     * @param array           $plan Plan completo.
     * @param int             $order_id ID del pedido.
     * @param string          $new_status Nuevo estado del pedido.
     * @param int             $current_active Índice actual.
     * @return void
     */
    private function advance_to_next_week( $subscription, $next_index, $next_row, $plan, $order_id, $new_status, $current_active ) {
        // Actualizar el total recurrente de la suscripción
        $this->update_subscription_recurring_total( $subscription, $next_index, $plan );

        // Actualizar el índice activo
        $subscription->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $next_index );

        // Agregar nota informativa
        $this->add_renewal_completion_note( $subscription, $order_id, $new_status, $current_active, $next_index, $plan );

        // Marcar el pedido como procesado
        ACF_Woo_Fasciculos_Utils::mark_order_as_processed( $subscription, $order_id );

        // Guardar cambios
        $subscription->save();
    }

    /**
     * Completar el plan de suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param int             $order_id ID del pedido.
     * @return void
     */
    private function complete_subscription_plan( $subscription, $order_id ) {
        // Agregar nota de finalización
        $subscription->add_order_note( __( '🎉 Plan de fascículos completado tras esta renovación. La suscripción se cancelará.', 'acf-woo-fasciculos' ) );

        // Marcar el pedido como procesado
        ACF_Woo_Fasciculos_Utils::mark_order_as_processed( $subscription, $order_id );

        // Guardar cambios
        $subscription->save();

        // Cancelar la suscripción
        $subscription->update_status(
            'cancelled',
            __( 'Plan completado al confirmar la renovación.', 'acf-woo-fasciculos' )
        );
    }

/**
 * Agregar nota de finalización de renovación
 *
 * @param WC_Subscription $subscription Suscripción.
 * @param int             $order_id ID del pedido.
 * @param string          $new_status Nuevo estado.
 * @param int             $current_active Índice actual.
 * @param int             $next_index Índice siguiente.
 * @param array           $plan Plan de fascículos.
 * @return void
 */
private function add_renewal_completion_note( $subscription, $order_id, $new_status, $current_active, $next_index, $plan ) {
    $current_week = $current_active + 1;
    $total_weeks = count( $plan );
    $next_week = $next_index + 1;
    
    if ( $next_index >= $total_weeks ) {
        // Última semana
        $message = sprintf(
            /* translators: 1: current week, 2: total weeks */
            __( '📦 Última semana completada: %1$d/%2$d. Esperando confirmación de pago para cancelar suscripción.', 'acf-woo-fasciculos' ),
            $current_week,
            $total_weeks
        );
    } else {
        // Semana normal
        $message = sprintf(
            /* translators: 1: current week, 2: total weeks, 3: next week */
            __( '📦 Semana %1$d/%2$d completada. Preparando semana %3$d.', 'acf-woo-fasciculos' ),
            $current_week,
            $total_weeks,
            $next_week
        );
    }
    
    $subscription->add_order_note( $message );
    
    // Actualizar también el pedido con la información del progreso
    $order = wc_get_order( $order_id );
    if ( $order ) {
        $order->add_order_note( $message );
    }
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
        $subscription->save();
    }

    /**
     * Obtener el plan de fascículos de una suscripción
     *
     * @param WC_Subscription $subscription Suscripción.
     * @return array Plan de fascículos.
     */
    private function get_subscription_plan( $subscription ) {
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
     * Obtener información de un pedido relacionado con fascículos
     *
     * @param WC_Order $order Pedido.
     * @return array Información del pedido.
     */
    public function get_order_fasciculos_info( $order ) {
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
            return array();
        }

        $has_fasciculos = false;
        $fasciculos_items = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            $plan_json = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            
            if ( $plan_json ) {
                $has_fasciculos = true;
                $active_index = $item->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
                
                $fasciculos_items[] = array(
                    'item_id' => $item_id,
                    'active_index' => '' !== $active_index && $active_index !== null ? intval( $active_index ) : 0,
                    'plan' => json_decode( $plan_json, true ),
                );
            }
        }

        return array(
            'has_fasciculos' => $has_fasciculos,
            'items' => $fasciculos_items,
            'total_items' => count( $fasciculos_items ),
        );
    }
}