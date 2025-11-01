<?php
/**
 * Clase para manejar pedidos y renovaciones
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar pedidos y renovaciones
 */
class ACF_Woo_Fasciculos_Orders {

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
        // Guardar el plan en el pedido cuando se crea
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_plan_to_order_item' ), 10, 4 );

        // Copiar el plan a la suscripción cuando se crea desde el pedido
        add_action( 'woocommerce_checkout_subscription_created', array( $this, 'copy_plan_to_subscription' ), 10, 3 );

        // Manejar la creación de pedidos de renovación
        add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'handle_renewal_payment_complete' ), 10, 2 );

        // Agregar información del plan a los pedidos
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'add_plan_info_to_order_admin' ), 10, 1 );

        // Mostrar información del fascículo en los emails
        add_action( 'woocommerce_email_after_order_table', array( $this, 'add_plan_info_to_email' ), 10, 4 );

        // HPOS compatibility: Use the new order storage methods
        add_action( 'woocommerce_before_order_object_save', array( $this, 'handle_hpos_order_save' ), 10, 2 );
    }

    /**
     * Guardar el plan de fascículos en el item del pedido
     *
     * @param WC_Order_Item_Product $item Item del pedido.
     * @param string $cart_item_key Clave del item en el carrito.
     * @param array $values Valores del item del carrito.
     * @param WC_Order $order Pedido.
     * @return void
     */
    public function save_plan_to_order_item( $item, $cart_item_key, $values, $order ) {
        if ( ! isset( $values['fasciculos_plan'] ) ) {
            return;
        }

        $plan = $values['fasciculos_plan'];

        // Guardar el plan completo como metadata del item
        $item->add_meta_data( '_fasciculos_plan', $plan );

        // Guardar el índice activo inicial (0 para la primera semana)
        $item->add_meta_data( '_fasciculo_active_index', 0 );

        // Guardar información de la semana actual
        if ( isset( $plan[0] ) ) {
            $current_week = $plan[0];
            $item->add_meta_data( 'Semana actual', sprintf( 'Semana 1 de %d', count( $plan ) ) );

            if ( isset( $current_week['product'] ) && $current_week['product'] ) {
                $item->add_meta_data( 'Producto de la semana', get_the_title( $current_week['product'] ) );
            }
        }
    }

    /**
     * Copiar el plan de fascículos a la suscripción
     *
     * @param WC_Subscription $subscription Suscripción creada.
     * @param WC_Order $order Pedido.
     * @param int $recurring_cart Cart recurrente.
     * @return void
     */
    public function copy_plan_to_subscription( $subscription, $order, $recurring_cart ) {
        // Obtener el plan del pedido
        $order_items = $order->get_items();

        foreach ( $order_items as $item ) {
            $plan = $item->get_meta( '_fasciculos_plan' );

            if ( ! empty( $plan ) ) {
                // Copiar el plan a la suscripción
                $subscription->add_meta_data( '_fasciculos_plan', $plan );
                $subscription->add_meta_data( '_fasciculo_active_index', 0 );
                $subscription->add_meta_data( '_fasciculos_plan_cache', wp_json_encode( $plan ) );
                $subscription->save();

                // También copiar a los items de la suscripción
                foreach ( $subscription->get_items() as $subscription_item ) {
                    $subscription_item->add_meta_data( '_fasciculos_plan', $plan );
                    $subscription_item->add_meta_data( '_fasciculo_active_index', 0 );
                    $subscription_item->save_meta_data();
                }

                break;
            }
        }
    }

    /**
     * Manejar el pago completo de una renovación
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param WC_Order $order Pedido de renovación.
     * @return void
     */
    public function handle_renewal_payment_complete( $subscription, $order ) {
        // Obtener el plan actual
        $plan = $subscription->get_meta( '_fasciculos_plan' );
        $active_index = intval( $subscription->get_meta( '_fasciculo_active_index' ) );

        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return;
        }

        // Avanzar al siguiente fascículo
        $next_index = $active_index + 1;

        // Verificar si hay más semanas en el plan
        if ( $next_index < count( $plan ) ) {
            // Actualizar el índice activo
            $subscription->update_meta_data( '_fasciculo_active_index', $next_index );
            $subscription->save();

            // Actualizar el próximo item de la suscripción
            $this->update_subscription_item_for_next_week( $subscription, $plan, $next_index );

            // Agregar nota informativa al pedido
            $next_week = $plan[ $next_index ];
            $order->add_order_note( sprintf(
                __( '🔁 Renovación confirmada (pedido #%1$d → %2$s). Semana %3$d/%4$d cobrada: %5$s. Próxima renovación (semana %6$d/%7$d): %8$s — %9$s', 'acf-woo-fasciculos' ),
                $order->get_id(),
                $order->get_formatted_order_total(),
                $active_index + 1,
                count( $plan ),
                isset( $next_week['price'] ) ? wc_price( $next_week['price'] ) : '',
                $next_index + 1,
                count( $plan ),
                isset( $next_week['product'] ) ? get_the_title( $next_week['product'] ) : '',
                isset( $next_week['note'] ) ? $next_week['note'] : ''
            ) );
        } else {
            // Última semana - el plan se completará
            $order->add_order_note( __( '🎉 Plan de fascículos completado tras esta renovación. La suscripción se cancelará.', 'acf-woo-fasciculos' ) );

            // Programar la cancelación de la suscripción
            $subscription->update_meta_data( '_fasciculo_plan_completed', 'yes' );
            $subscription->save();

            // Cancelar la suscripción después de un breve retraso para asegurar que el pedido se procese completamente
            wp_schedule_single_event( time() + 300, 'acf_woo_fasciculos_cancel_subscription', array( $subscription->get_id() ) );
        }
    }

    /**
     * Actualizar el item de la suscripción para la próxima semana
     *
     * @param WC_Subscription $subscription Suscripción.
     * @param array $plan Plan de fascículos.
     * @param int $next_index Índice de la próxima semana.
     * @return void
     */
    private function update_subscription_item_for_next_week( $subscription, $plan, $next_index ) {
        if ( ! isset( $plan[ $next_index ] ) ) {
            return;
        }

        $next_week = $plan[ $next_index ];
        $items = $subscription->get_items();

        foreach ( $items as $item ) {
            // Actualizar el producto si es diferente
            if ( isset( $next_week['product'] ) && $next_week['product'] != $item->get_product_id() ) {
                $item->set_product_id( $next_week['product'] );
                $item->set_name( get_the_title( $next_week['product'] ) );
            }

            // Actualizar el precio
            if ( isset( $next_week['price'] ) ) {
                $item->set_subtotal( $next_week['price'] );
                $item->set_total( $next_week['price'] );
            }

            // Actualizar la información de la semana
            $item->update_meta_data( '_fasciculo_active_index', $next_index );
            $item->update_meta_data( 'Semana actual', sprintf( 'Semana %d de %d', $next_index + 1, count( $plan ) ) );

            if ( isset( $next_week['product'] ) ) {
                $item->update_meta_data( 'Producto de la semana', get_the_title( $next_week['product'] ) );
            }

            $item->save();
            break; // Solo actualizar el primer item
        }

        // Recalcular totales
        $subscription->calculate_totals();
    }

    /**
     * Agregar información del plan al panel de administración del pedido
     *
     * @param WC_Order $order Pedido.
     * @return void
     */
    public function add_plan_info_to_order_admin( $order ) {
        if ( ! $order ) {
            return;
        }

        // Buscar información del plan en los items
        foreach ( $order->get_items() as $item ) {
            $plan = $item->get_meta( '_fasciculos_plan' );
            $active_index = $item->get_meta( '_fasciculo_active_index' );

            if ( ! empty( $plan ) && is_array( $plan ) ) {
                $current_week = isset( $plan[ $active_index ] ) ? $plan[ $active_index ] : null;

                if ( $current_week ) {
                    echo '<div class="fasciculos-info" style="margin-top: 10px; padding: 10px; background: #f9f9f9; border-left: 3px solid #2271b1; font-size: 13px;">';
                    echo '<strong>' . __( 'Plan de Fascículos', 'acf-woo-fasciculos' ) . '</strong><br>';
                    echo sprintf(
                        __( 'Semana actual: %1$s de %2$s', 'acf-woo-fasciculos' ),
                        $active_index + 1,
                        count( $plan )
                    ) . '<br>';

                    if ( isset( $current_week['product'] ) && $current_week['product'] ) {
                        echo '<strong>' . __( 'Producto:', 'acf-woo-fasciculos' ) . '</strong> ' . get_the_title( $current_week['product'] ) . '<br>';
                    }

                    if ( isset( $current_week['price'] ) && $current_week['price'] ) {
                        echo '<strong>' . __( 'Precio:', 'acf-woo-fasciculos' ) . '</strong> ' . wc_price( $current_week['price'] ) . '<br>';
                    }

                    if ( isset( $current_week['note'] ) && $current_week['note'] ) {
                        echo '<strong>' . __( 'Nota:', 'acf-woo-fasciculos' ) . '</strong> ' . esc_html( $current_week['note'] ) . '<br>';
                    }

                    echo '</div>';
                }

                break; // Solo mostrar para el primer item con plan
            }
        }
    }

    /**
     * Agregar información del plan a los emails
     *
     * @param WC_Order $order Pedido.
     * @param bool $sent_to_admin Si se envía al administrador.
     * @param bool $plain_text Si es texto plano.
     * @param WC_Email $email Objeto email.
     * @return void
     */
    public function add_plan_info_to_email( $order, $sent_to_admin, $plain_text, $email ) {
        if ( ! $order ) {
            return;
        }

        // Buscar información del plan en los items
        foreach ( $order->get_items() as $item ) {
            $plan = $item->get_meta( '_fasciculos_plan' );
            $active_index = $item->get_meta( '_fasciculo_active_index' );

            if ( ! empty( $plan ) && is_array( $plan ) ) {
                $current_week = isset( $plan[ $active_index ] ) ? $plan[ $active_index ] : null;

                if ( $current_week ) {
                    if ( $plain_text ) {
                        echo "\n\n" . __( '=== INFORMACIÓN DEL PLAN DE FASCÍCULOS ===', 'acf-woo-fasciculos' ) . "\n\n";
                        echo sprintf( __( 'Semana actual: %1$s de %2$s', 'acf-woo-fasciculos' ), $active_index + 1, count( $plan ) ) . "\n";

                        if ( isset( $current_week['product'] ) && $current_week['product'] ) {
                            echo __( 'Producto:', 'acf-woo-fasciculos' ) . ' ' . get_the_title( $current_week['product'] ) . "\n";
                        }

                        if ( isset( $current_week['price'] ) && $current_week['price'] ) {
                            echo __( 'Precio:', 'acf-woo-fasciculos' ) . ' ' . wc_price( $current_week['price'] ) . "\n";
                        }

                        if ( isset( $current_week['note'] ) && $current_week['note'] ) {
                            echo __( 'Nota:', 'acf-woo-fasciculos' ) . ' ' . $current_week['note'] . "\n";
                        }
                    } else {
                        echo '<div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px;">';
                        echo '<h3 style="margin: 0 0 10px 0; color: #333; font-size: 16px;">' . __( 'Información del Plan de Fascículos', 'acf-woo-fasciculos' ) . '</h3>';
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
                }

                break; // Solo mostrar para el primer item con plan
            }
        }
    }

    /**
     * Manejar el guardado de pedidos con HPOS (High-Performance Order Storage)
     *
     * @param WC_Order $order Pedido.
     * @param WC_Data_Store $data_store Almacén de datos.
     * @return void
     */
    public function handle_hpos_order_save( $order, $data_store ) {
        // Asegurar que los metadatos del plan se guarden correctamente con HPOS
        if ( $order->meta_exists( '_fasciculos_plan' ) ) {
            // El plan ya está guardado, no hacer nada
            return;
        }

        // Si hay información del plan en los items, copiarla al pedido
        foreach ( $order->get_items() as $item ) {
            $plan = $item->get_meta( '_fasciculos_plan' );
            if ( ! empty( $plan ) ) {
                $order->add_meta_data( '_fasciculos_plan', $plan );
                $order->save_meta_data();
                break;
            }
        }
    }
    private function add_renewal_completion_note( $subscription, $order_id, $new_status, $current_active, $next_index, $plan ) {
        $current_name = ACF_Woo_Fasciculos_Utils::get_product_name( $plan[ $current_active ]['product_id'] );
        $next_name = ACF_Woo_Fasciculos_Utils::get_product_name( $plan[ $next_index ]['product_id'] );

        $note = ACF_Woo_Fasciculos_Utils::generate_renewal_note(
            $order_id,
            $new_status,
            $current_active,
            count( $plan ),
            $current_name,
            $next_index,
            $next_name,
            $plan[ $next_index ]['price']
        );

        $subscription->add_order_note( $note );
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