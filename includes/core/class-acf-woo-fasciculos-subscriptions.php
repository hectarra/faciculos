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

    // Para renovaciones, usamos el primer producto de la lista como el producto principal
    // pero mostramos todos los productos en las notas
    $first_product_id = isset( $row['product_ids'][0] ) ? intval( $row['product_ids'][0] ) : 0;
    if ( ! $first_product_id ) {
        return $items;
    }

    $new_product = wc_get_product( $first_product_id );
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

    // Verificar que haya productos
    if ( ! isset( $first_row['product_ids'] ) || empty( $first_row['product_ids'] ) ) {
        return;
    }

    $parent_order = wc_get_order( $subscription->get_parent_id() );
    if ( ! $parent_order ) {
        return;
    }

    $qty = 1;
    $total_price = floatval( $first_row['price'] );
    $product_ids = $first_row['product_ids'];

    // Agregar todos los productos de la semana con precio 0€
    // El precio se muestra en la línea de suscripción
    foreach ( $product_ids as $index => $product_id ) {
        $product = wc_get_product( intval( $product_id ) );
        if ( ! $product ) {
            continue;
        }

        $item = new WC_Order_Item_Product();
        $item->set_product( $product );
        $item->set_name( $product->get_name() );
        $item->set_quantity( $qty );
        
        // Todos los productos individuales van a 0€ (el precio está en la suscripción)
        $item->set_subtotal( 0 );
        $item->set_total( 0 );
        
        $item->set_tax_class( $product->get_tax_class() );
        
        $item->add_meta_data( '_product_item', 'yes' );
        $item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, 0 );
        $item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
        
        // Marcar productos adicionales (los que tienen precio 0)
        if ( $index > 0 ) {
            $item->add_meta_data( '_fasciculo_included', 'yes' );
        }
        
        $item->save();
        $parent_order->add_item( $item );
    }
    
    // Ajustar el precio de la suscripción al precio del plan en el pedido padre
    foreach ( $parent_order->get_items() as $order_item_id => $order_item ) {
        if ( $order_item instanceof WC_Order_Item_Product ) {
            $product = $order_item->get_product();
            if ( $product && $product->is_type( 'subscription' ) ) {
                // Mantener el precio de la suscripción para que se muestre ahí
                $order_item->set_subtotal( $total_price * $qty );
                $order_item->set_total( $total_price * $qty );
                $order_item->save();
            }
        }
    }
    
    $parent_order->calculate_totals();
    $parent_order->save();

    // Agregar nota con todos los productos
    $product_names = ACF_Woo_Fasciculos_Utils::get_product_names( $product_ids );
    $subscription->add_order_note( sprintf(
        __( '📦 Productos iniciales agregados al pedido: %1$s — %2$s', 'acf-woo-fasciculos' ),
        $product_names,
        ACF_Woo_Fasciculos_Utils::format_price( $total_price )
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

        // Obtener los nombres de los productos
        $next_product_names = '';
        if ( isset( $next_row['product_ids'] ) && is_array( $next_row['product_ids'] ) ) {
            $next_product_names = ACF_Woo_Fasciculos_Utils::get_product_names( $next_row['product_ids'] );
        }

        // Agregar nota informativa
        $subscription->add_order_note( sprintf(
            /* translators: 1: next week number, 2: total weeks, 3: product names, 4: price */
            __( '🔄 Suscripción actualizada para próxima renovación (semana %1$d/%2$d): %3$s — %4$s', 'acf-woo-fasciculos' ),            $next_index + 1,
            count( $plan ),
            $next_product_names,
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

        // Usar el primer producto de la lista para la renovación
        $first_product_id = isset( $row['product_ids'][0] ) ? intval( $row['product_ids'][0] ) : 0;
        if ( ! $first_product_id ) {
            return;
        }

        $new_product = wc_get_product( $first_product_id );
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
        $product_ids = isset( $row['product_ids'] ) ? $row['product_ids'] : array();

        // Si no hay productos, retornar items originales
        if ( empty( $product_ids ) ) {
            return $items;
        }

        $subscription_item_found = false;

        foreach ( $items as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                // Mantener items que no sean productos sin cambios
                $new_items[ $item_id ] = $item;
                continue;
            }

            // Mantener el item de suscripción original pero actualizar su precio
            $product = $item->get_product();
            if ( $product && $product->is_type( array( 'subscription', 'variable-subscription' ) ) ) {
                $qty = max( 1, intval( $item->get_quantity() ) );
                
                // Actualizar el precio del item de suscripción
                $item->set_subtotal( $new_price * $qty );
                $item->set_total( $new_price * $qty );
                
                // Actualizar metadatos
                $item->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $current_active );
                $item->update_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
                
                $new_items[ $item_id ] = $item;
                $subscription_item_found = true;
            }
            // Ignorar productos que no sean de suscripción (se reemplazarán)
        }

        // Si no se encontró el item de suscripción, crearlo desde el primer item original
        if ( ! $subscription_item_found ) {
            // Buscar algún item original para obtener el producto de suscripción
            foreach ( $items as $item_id => $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }
                
                // Crear un nuevo item de suscripción basado en el item original
                $subscription_item = new WC_Order_Item_Product();
                
                // Intentar obtener el producto original de suscripción
                $original_product = $item->get_product();
                if ( $original_product ) {
                    $subscription_item->set_product( $original_product );
                    $subscription_item->set_name( $original_product->get_name() );
                } else {
                    // Fallback: usar el nombre del item
                    $subscription_item->set_name( $item->get_name() );
                }
                
                $subscription_item->set_quantity( 1 );
                $subscription_item->set_subtotal( $new_price );
                $subscription_item->set_total( $new_price );
                
                // Copiar la clase de impuestos
                $subscription_item->set_tax_class( $item->get_tax_class() );
                
                // Agregar metadatos
                $subscription_item->update_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $current_active );
                $subscription_item->update_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
                
                $new_items[ 'subscription_item' ] = $subscription_item;
                break; // Solo necesitamos crear un item de suscripción
            }
        }

        // Ahora agregar todos los productos individuales con precio 0€
        foreach ( $product_ids as $index => $product_id ) {
            $product = wc_get_product( intval( $product_id ) );
            if ( ! $product ) {
                continue;
            }

            $new_item = new WC_Order_Item_Product();
            $new_item->set_product( $product );
            $new_item->set_name( $product->get_name() );
            $new_item->set_quantity( 1 );
            
            // Todos los productos individuales van a 0€ (están incluidos en el precio de la suscripción)
            $new_item->set_subtotal( 0 );
            $new_item->set_total( 0 );
            
            $new_item->set_tax_class( $product->get_tax_class() );

            // Agregar metadatos
            $new_item->add_meta_data( '_product_item', 'yes' );
            $new_item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $current_active );
            $new_item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
            
            // Marcar productos adicionales (todos son incluidos ya que el precio está en la suscripción)
            $new_item->add_meta_data( '_fasciculo_included', 'yes' );

            // Usar un ID único para cada producto
            $new_items[ 'fasciculo_product_' . $index ] = $new_item;
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

/**
 * Deshabilitar acciones de renovación y reactivación en el área de usuario
 *
 * Filtra las acciones disponibles para el cliente en la vista de suscripción,
 * eliminando las opciones de "renovar", "resuscribir" y "reactivar" para suscripciones
 * que tienen un plan de fascículos.
 *
 * @param array           $actions Acciones disponibles.
 * @param WC_Subscription $subscription Suscripción.
 * @return array Acciones filtradas.
 */
public function disable_user_renewal_reactivate_actions( $actions, $subscription ) {
    // Verificar si la suscripción tiene un plan de fascículos
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return $actions;
    }

    $plan_cache = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
    
    // Si la suscripción no tiene plan de fascículos, no modificar acciones
    if ( empty( $plan_cache ) ) {
        return $actions;
    }

    // Lista de acciones específicas a eliminar
    $actions_to_remove = array(
        'resubscribe',              // Botón "Resuscribirse" para suscripciones canceladas/expiradas
        'renew',                    // Botón "Renovar" para renovación manual
        'subscription_renewal_early', // Botón "Renovar anticipadamente"
        'reactivate',               // Botón "Reactivar" para suscripciones en espera
    );

    foreach ( $actions_to_remove as $action_key ) {
        if ( isset( $actions[ $action_key ] ) ) {
            unset( $actions[ $action_key ] );
        }
    }

    // Además, eliminar cualquier acción que contenga "renew" en su clave
    // Esto captura variantes que puedan existir en diferentes versiones de WCS
    foreach ( array_keys( $actions ) as $action_key ) {
        if ( strpos( $action_key, 'renew' ) !== false ) {
            unset( $actions[ $action_key ] );
        }
    }

    return $actions;
}

/**
 * Deshabilitar el permiso de reactivación y resuscripción de suscripciones
 *
 * Este filtro previene que el usuario pueda reactivar o resuscribirse a una suscripción
 * con plan de fascículos, incluso si intenta acceder directamente a la URL.
 *
 * @param bool            $can_perform Si el usuario puede realizar la acción.
 * @param WC_Subscription $subscription Suscripción.
 * @return bool False si la suscripción tiene plan de fascículos.
 */
public function disable_user_reactivation( $can_perform, $subscription ) {
    if ( ! $can_perform ) {
        return false;
    }

    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return $can_perform;
    }

    $plan_cache = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
    
    // Si la suscripción tiene plan de fascículos, no permitir reactivación ni resuscripción
    if ( ! empty( $plan_cache ) ) {
        return false;
    }

    return $can_perform;
}

/**
 * Deshabilitar la renovación anticipada de suscripciones
 *
 * El filtro wcs_subscription_can_be_renewed_early puede pasar los parámetros
 * en diferente orden dependiendo de la versión de WooCommerce Subscriptions.
 *
 * @param bool            $can_renew_early Si el usuario puede renovar anticipadamente.
 * @param WC_Subscription $subscription Suscripción.
 * @return bool False si la suscripción tiene plan de fascículos.
 */
public function disable_early_renewal( $can_renew_early, $subscription = null ) {
    // Si el primer parámetro es una suscripción (algunas versiones de WCS)
    if ( is_a( $can_renew_early, 'WC_Subscription' ) ) {
        $subscription = $can_renew_early;
        $can_renew_early = true;
    }

    if ( ! $can_renew_early ) {
        return false;
    }

    if ( ! $subscription || ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return $can_renew_early;
    }

    $plan_cache = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
    
    // Si la suscripción tiene plan de fascículos, no permitir renovación anticipada
    if ( ! empty( $plan_cache ) ) {
        return false;
    }

    return $can_renew_early;
}

}