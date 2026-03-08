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
     * Aplicar período de renovación personalizado en días
     *
     * Este método se ejecuta cuando se crea una suscripción.
     * Si el producto tiene configurado un número personalizado de días entre renovaciones,
     * modifica el período de facturación de la suscripción.
     *
     * @param WC_Subscription $subscription Suscripción creada.
     * @param WC_Order        $order Pedido padre.
     * @param WC_Cart         $recurring_cart Carrito recurrente.
     * @return void
     */
    public function apply_custom_renewal_days( $subscription, $order, $recurring_cart ) {
        // Validar suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        // Buscar producto con plan de fascículos en la suscripción
        foreach ( $subscription->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product_id = $item->get_product_id();
            
            // Verificar si tiene plan de fascículos
            $plan = ACF_Woo_Fasciculos_Utils::get_plan_for_product( $product_id );
            if ( empty( $plan ) ) {
                continue;
            }
            
            // Obtener días personalizados desde ACF
            $custom_days = get_field( 'fasciculo_renewal_days', $product_id );
            
            if ( $custom_days && intval( $custom_days ) > 0 ) {
                $days = intval( $custom_days );
                
                // Guardar en meta de la suscripción
                $subscription->update_meta_data( ACF_Woo_Fasciculos::META_RENEWAL_DAYS, $days );
                
                // Aplicar período en días
                $subscription->set_billing_period( 'day' );
                $subscription->set_billing_interval( $days );
                
                // Recalcular próxima fecha de pago basada en el nuevo período
                $start_date = $subscription->get_date( 'start' );
                if ( $start_date ) {
                    $next_payment = gmdate( 'Y-m-d H:i:s', strtotime( $start_date . ' + ' . $days . ' days' ) );
                    $subscription->update_dates( array( 'next_payment' => $next_payment ) );
                }
                
                $subscription->save();
                
                // Agregar nota informativa
                $subscription->add_order_note( sprintf(
                    /* translators: %d: number of days */
                    __( '📅 Período de renovación personalizado aplicado: cada %d días', 'acf-woo-fasciculos' ),
                    $days
                ) );
                
                break; // Solo procesar el primer producto con plan
            }
        }
    }

    /**
     * Manejar la creación de un pedido de renovación (filtro wcs_renewal_order_created)
     *
     * @param WC_Order        $renewal_order Pedido de renovación.
     * @param WC_Subscription $subscription Suscripción relacionada.
     * @return WC_Order Pedido de renovación modificado.
     */
public function on_renewal_order_created( $renewal_order, $subscription ) {
    if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $renewal_order ) || ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
        return $renewal_order;
    }

    $plan = $this->get_subscription_plan( $subscription );
    if ( empty( $plan ) ) {
        return $renewal_order;
    }

    $active = $this->get_active_index( $subscription );
    
    $row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $active );
    if ( ! $row ) {
        return $renewal_order;
    }

    $new_price = floatval( $row['price'] );
    $product_ids = isset( $row['product_ids'] ) ? $row['product_ids'] : array();

    // Obtener el producto de suscripción original
    $subscription_product = null;
    $subscription_product_name = __( 'Suscripción', 'acf-woo-fasciculos' );
    $tax_class = '';
    
    // Intentar obtener el producto de suscripción desde el pedido padre
    $parent_order = wc_get_order( $subscription->get_parent_id() );
    if ( $parent_order ) {
        foreach ( $parent_order->get_items() as $parent_item ) {
            if ( $parent_item instanceof WC_Order_Item_Product ) {
                $product = $parent_item->get_product();
                if ( $product && $product->is_type( array( 'subscription', 'variable-subscription' ) ) ) {
                    $subscription_product = $product;
                    $subscription_product_name = $product->get_name();
                    $tax_class = $product->get_tax_class();
                    break;
                }
            }
        }
    }
    
    // Si no se encontró en el pedido padre, buscar en la suscripción
    if ( ! $subscription_product ) {
        foreach ( $subscription->get_items() as $sub_item ) {
            if ( $sub_item instanceof WC_Order_Item_Product ) {
                // Obtener el nombre del item directamente (puede no tener producto asociado)
                $item_name = $sub_item->get_name();
                if ( ! empty( $item_name ) ) {
                    $subscription_product_name = $item_name;
                }
                $product = $sub_item->get_product();
                if ( $product ) {
                    if ( $product->is_type( array( 'subscription', 'variable-subscription' ) ) ) {
                        $subscription_product = $product;
                        $subscription_product_name = $product->get_name();
                        $tax_class = $product->get_tax_class();
                        break;
                    }
                }
                // Si encontramos un item, usar su clase de impuestos
                $tax_class = $sub_item->get_tax_class();
                break;
            }
        }
    }

    // Eliminar todos los items existentes del pedido de renovación
    foreach ( $renewal_order->get_items() as $item_id => $item ) {
        $renewal_order->remove_item( $item_id );
    }

    // 1. Agregar primero el item de suscripción con el precio
    $subscription_item = new WC_Order_Item_Product();
    if ( $subscription_product ) {
        $subscription_item->set_product( $subscription_product );
    }
    $subscription_item->set_name( $subscription_product_name );
    $subscription_item->set_quantity( 1 );
    $subscription_item->set_subtotal( $new_price );
    $subscription_item->set_total( $new_price );
    $subscription_item->set_tax_class( $tax_class );
    $subscription_item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $active );
    $subscription_item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
    
    $renewal_order->add_item( $subscription_item );

    // 2. Agregar todos los productos individuales con precio 0€
    foreach ( $product_ids as $index => $product_id ) {
        $product = wc_get_product( intval( $product_id ) );
        if ( ! $product ) {
            continue;
        }

        $new_item = new WC_Order_Item_Product();
        $new_item->set_product( $product );
        $new_item->set_name( $product->get_name() );
        $new_item->set_quantity( 1 );
        $new_item->set_subtotal( 0 );
        $new_item->set_total( 0 );
        $new_item->set_tax_class( $product->get_tax_class() );
        $new_item->add_meta_data( '_product_item', 'yes' );
        $new_item->add_meta_data( '_fasciculo_included', 'yes' );
        $new_item->add_meta_data( ACF_Woo_Fasciculos::META_ACTIVE_INDEX, $active );
        $new_item->add_meta_data( ACF_Woo_Fasciculos::META_PLAN_CACHE, wp_json_encode( $plan ) );
        
        $renewal_order->add_item( $new_item );
    }

    // Recalcular totales y guardar
    $renewal_order->calculate_totals();
    $renewal_order->save();

    // Aplicar cupón de descuento si existe
    $this->apply_discount_coupon_to_renewal( $renewal_order, $subscription );

    // Obtener los nombres de los productos para la nota
    $product_names = '';
    if ( ! empty( $product_ids ) ) {
        $product_names = ACF_Woo_Fasciculos_Utils::get_product_names( $product_ids );
    }

    $renewal_order->add_order_note( sprintf(
        __( '📦 Fascículo semana %1$d/%2$d: %3$s — %4$s', 'acf-woo-fasciculos' ),
        $active + 1,
        count( $plan ),
        $product_names,
        ACF_Woo_Fasciculos_Utils::format_price( $new_price )
    ) );

    return $renewal_order;
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

    /**
     * Reducir el stock de los productos del fascículo cuando se paga la renovación
     *
     * @param int $order_id ID del pedido.
     * @return void
     */
    public function reduce_fasciculo_stock_on_payment( $order_id ) {
        $order = wc_get_order( $order_id );
        
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
            return;
        }

        // Verificar si ya se redujo el stock para este pedido
        if ( $order->get_meta( '_fasciculo_stock_reduced' ) === 'yes' ) {
            return;
        }

        // Verificar si es un pedido de renovación o tiene productos de fascículos
        $has_fasciculos = false;
        $products_reduced = array();

        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Verificar si el item es un producto de fascículo
            $is_product_item = $item->get_meta( '_product_item' );
            $is_fasciculo_included = $item->get_meta( '_fasciculo_included' );
            
            if ( $is_product_item === 'yes' || $is_fasciculo_included === 'yes' ) {
                $has_fasciculos = true;
                
                $product = $item->get_product();
                if ( ! $product ) {
                    continue;
                }

                // Solo reducir stock si el producto gestiona stock
                if ( ! $product->managing_stock() ) {
                    continue;
                }

                $product_id = $product->get_id();
                $qty = $item->get_quantity();

                // Reducir el stock
                $new_stock = wc_update_product_stock( $product, $qty, 'decrease' );
                
                if ( $new_stock !== false ) {
                    $products_reduced[] = sprintf(
                        '%s (-%d → stock: %d)',
                        $product->get_name(),
                        $qty,
                        $new_stock
                    );
                }
            }
        }

        // Si se redujo algún stock, marcar el pedido y agregar nota
        if ( $has_fasciculos && ! empty( $products_reduced ) ) {
            $order->update_meta_data( '_fasciculo_stock_reduced', 'yes' );
            $order->save();

            $order->add_order_note( sprintf(
                __( '📦 Stock reducido para productos del fascículo: %s', 'acf-woo-fasciculos' ),
                implode( ', ', $products_reduced )
            ) );
        }
    }

    /**
     * Prevenir la reducción automática de stock para pedidos con productos de fascículos
     *
     * Este filtro evita que WooCommerce reduzca automáticamente el stock
     * para los productos de fascículos. El stock solo se reduce cuando
     * se paga el pedido mediante el hook woocommerce_payment_complete.
     *
     * @param bool     $reduce_stock Si se debe reducir el stock.
     * @param WC_Order $order Pedido.
     * @return bool False para prevenir reducción automática en pedidos de fascículos.
     */
    public function prevent_automatic_stock_reduction( $reduce_stock, $order ) {
        if ( ! $reduce_stock ) {
            return $reduce_stock;
        }

        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) ) {
            return $reduce_stock;
        }

        // Verificar si el pedido tiene productos de fascículos
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            // Verificar si es un item de fascículo
            $is_product_item = $item->get_meta( '_product_item' );
            $is_fasciculo_included = $item->get_meta( '_fasciculo_included' );
            $has_plan = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );

            if ( $is_product_item === 'yes' || $is_fasciculo_included === 'yes' || ! empty( $has_plan ) ) {
                // Pedido tiene productos de fascículos - prevenir reducción automática
                // El stock se reducirá manualmente en reduce_fasciculo_stock_on_payment
                return false;
            }
        }

        return $reduce_stock;
    }

    /**
     * Copiar código de cupón de descuento a la suscripción al crearla
     *
     * Este método se ejecuta cuando se crea una suscripción desde un pedido.
     * Busca si el producto tiene configurado un cupón de descuento para renovaciones
     * y lo guarda en los metadatos de la suscripción.
     *
     * @param WC_Subscription $subscription Suscripción creada.
     * @param WC_Order        $order Pedido original.
     * @param mixed           $recurring_cart Carrito recurrente.
     * @return void
     */
    public function copy_coupon_to_subscription( $subscription, $order, $recurring_cart = null ) {
        // Validar suscripción
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        // Buscar producto con plan de fascículos en la suscripción
        foreach ( $subscription->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product_id = $item->get_product_id();

            // Verificar si tiene plan de fascículos
            $plan = ACF_Woo_Fasciculos_Utils::get_plan_for_product( $product_id );
            if ( empty( $plan ) ) {
                continue;
            }

            // Obtener cupón de descuento desde ACF
            $coupon_code = get_field( 'fasciculo_discount_coupon', $product_id );

            if ( ! empty( $coupon_code ) ) {
                $coupon_code = wc_strtolower( sanitize_text_field( $coupon_code ) );

                // Verificar que el cupón existe en WooCommerce
                $coupon = new WC_Coupon( $coupon_code );
                if ( $coupon->get_id() > 0 ) {
                    // Guardar en meta de la suscripción
                    $subscription->update_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON, $coupon_code );
                    $subscription->save();

                    // Agregar nota informativa
                    $subscription->add_order_note( sprintf(
                        /* translators: %s: coupon code */
                        __( '🏷️ Cupón de descuento configurado para renovaciones: %s', 'acf-woo-fasciculos' ),
                        strtoupper( $coupon_code )
                    ) );
                }
            }

            break; // Solo procesar el primer producto con plan
        }
    }

    /**
     * Aplicar cupón de descuento a un pedido de renovación
     *
     * Busca si la suscripción tiene un cupón de descuento configurado
     * y lo aplica al pedido de renovación.
     *
     * @param WC_Order        $renewal_order Pedido de renovación.
     * @param WC_Subscription $subscription Suscripción relacionada.
     * @return void
     */
    private function apply_discount_coupon_to_renewal( $renewal_order, $subscription ) {
        // Obtener el código de cupón desde la suscripción
        $coupon_code = $subscription->get_meta( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );

        if ( empty( $coupon_code ) ) {
            return;
        }

        // Verificar que el cupón existe y es válido
        $coupon = new WC_Coupon( $coupon_code );
        if ( ! $coupon->get_id() ) {
            return;
        }

        // Verificar que el cupón no esté ya aplicado
        $applied_coupons = $renewal_order->get_coupon_codes();
        if ( in_array( $coupon_code, $applied_coupons, true ) ) {
            return;
        }

        // Aplicar el cupón al pedido de renovación
        $result = $renewal_order->apply_coupon( $coupon );

        if ( ! is_wp_error( $result ) ) {
            $renewal_order->calculate_totals();
            $renewal_order->save();

            $renewal_order->add_order_note( sprintf(
                /* translators: 1: coupon code, 2: discount amount */
                __( '🏷️ Cupón de descuento aplicado: %1$s (-%2$s)', 'acf-woo-fasciculos' ),
                strtoupper( $coupon_code ),
                ACF_Woo_Fasciculos_Utils::format_price( $renewal_order->get_discount_total() )
            ) );
        }
    }
}