<?php
/**
 * Manejador de cancelaciones y retención para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar el flujo de cancelación y ofertas de retención
 */
class ACF_Woo_Fasciculos_Cancellations {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Registrar endpoints para el área de cliente
     */
    public function add_endpoints() {
        add_rewrite_endpoint( 'cancelar-fasciculo', EP_ROOT | EP_PAGES );

        // Comprobación robusta en tiempo real para evitar errores 404
        // Si las reglas actuales no incluyen nuestro endpoint, forzamos un flush inmediato.
        global $wp_rewrite;
        $rules = $wp_rewrite->wp_rewrite_rules();

        // Buscamos si nuestra regla existe (WordPress la añade al array con una clave que termina en /?$)
        $endpoint_exists = false;
        if ( is_array( $rules ) ) {
            foreach ( $rules as $rule_key => $rule_val ) {
                if ( strpos( $rule_key, 'cancelar-fasciculo' ) !== false ) {
                    $endpoint_exists = true;
                    break;
                }
            }
        }

        // Si no existe, forzamos la regeneración de las reglas.
        if ( ! $endpoint_exists ) {
            // Es preferible no llamar flush_rewrite_rules en el init, sino en wp_loaded o admin_init
            add_action( 'wp_loaded', function() {
                flush_rewrite_rules();
            } );
        }
    }

    /**
     * Añadir query vars
     *
     * @param array $vars Query vars actuales
     * @return array
     */
    public function add_query_vars( $vars ) {
        $vars[] = 'cancelar-fasciculo';
        return $vars;
    }

    /**
     * Añadir título personalizado al endpoint
     *
     * @param string $title Título original
     * @param int $endpoint Título del endpoint
     * @return string
     */
    public function endpoint_title( $title, $endpoint ) {
        if ( 'cancelar-fasciculo' === $endpoint ) {
            $title = __( 'Cancelar Suscripción', 'acf-woo-fasciculos' );
        }
        return $title;
    }

    /**
     * Interceptar el botón de cancelar suscripción
     * Cambia la URL de cancelación estándar a nuestra página de flujo
     *
     * @param array $actions Acciones disponibles para la suscripción
     * @param WC_Subscription $subscription La suscripción
     * @return array
     */
    public function intercept_cancel_button( $actions, $subscription ) {
        // Solo actuar si es una suscripción válida
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return $actions;
        }

        // Verificar si es una suscripción de fascículos
        $plan_cache = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        if ( empty( $plan_cache ) ) {
            return $actions;
        }

        // Comprobar si hay una oferta activa y si el usuario aún está dentro de la permanencia
        $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        if ( $active_offer && $active_index < intval( $active_offer['target_delivery'] ) ) {
            // El usuario tiene una permanencia activa
            // Quitar el botón de cancelar original
            if ( isset( $actions['cancel'] ) ) {
                unset( $actions['cancel'] );
            }

            // Añadir botón de contacto
            $actions['contact'] = array(
                'url'  => 'https://singularicolecciones.com/contacto/',
                'name' => __( 'Contacto *', 'acf-woo-fasciculos' ),
                'class'=> 'button contact',
            );

        } else {
            // No tiene oferta activa o ya ha cumplido la permanencia. Modificar la URL del botón de cancelar.
            if ( isset( $actions['cancel'] ) ) {
                $cancel_url = wc_get_endpoint_url( 'cancelar-fasciculo', $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) );
                $actions['cancel']['url'] = $cancel_url;
                $actions['cancel']['class'] .= ' fasciculos-cancel-btn';
            }
        }

        return $actions;
    }

    /**
     * Muestra el texto legal de permanencia debajo de la tabla de la suscripción
     *
     * @param WC_Subscription $subscription
     */
    public function show_permanence_legal_text( $subscription ) {
        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        // Si tiene la permanencia activa, inyectar el texto justo después de la tabla
        if ( $active_offer && $active_index < intval( $active_offer['target_delivery'] ) ) {
            $text = esc_html__( '*Permanencia Obligatioria de entregas para obtener el descuento. Si se cancela antes de la entrega acordada por la permanencia se cobrarán las entregas ya realizadas al PVP establecido sin descuento.', 'acf-woo-fasciculos' );

            // Usamos JavaScript para colocarlo exactamente debajo del contenedor de .subscription_actions (o .order-actions)
            // donde WooCommerce imprime los botones, para asegurar que visualmente quede "justo debajo del botón".
            ?>
            <script>
            document.addEventListener("DOMContentLoaded", function() {
                var actionTables = document.querySelectorAll('table.shop_table.subscription_details');
                var actionButtons = document.querySelectorAll('.subscription_actions, .order-actions');

                var legalText = document.createElement('div');
                legalText.style.cssText = 'width: 100%; text-align: right; margin-top: 5px; clear: both;';
                legalText.innerHTML = '<p style="display: inline-block; max-width: 400px; text-align: left; font-size: 0.85em; color: #666; line-height: 1.4;"><?php echo $text; ?></p>';

                if (actionButtons.length > 0) {
                    var lastActionContainer = actionButtons[actionButtons.length - 1];
                    lastActionContainer.parentNode.insertBefore(legalText, lastActionContainer.nextSibling);
                } else if (actionTables.length > 0) {
                    var lastTable = actionTables[actionTables.length - 1];
                    lastTable.parentNode.insertBefore(legalText, lastTable.nextSibling);
                }
            });
            </script>
            <?php
        }
    }

    /**
     * Muestra el contenido del endpoint
     *
     * @param int $subscription_id
     */
    public function endpoint_content( $subscription_id ) {
        $subscription = wcs_get_subscription( $subscription_id );

        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            echo '<p>' . esc_html__( 'Suscripción no válida.', 'acf-woo-fasciculos' ) . '</p>';
            return;
        }

        // Verificar permisos
        if ( ! current_user_can( 'view_order', $subscription_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
            echo '<p>' . esc_html__( 'No tienes permisos para ver esto.', 'acf-woo-fasciculos' ) . '</p>';
            return;
        }

        $step = isset( $_GET['step'] ) ? sanitize_text_field( $_GET['step'] ) : '1';

        // Evaluar oferta (para paso 1)
        $offer = $this->get_retention_offer( $subscription );

        // Comprobar si ya tiene una oferta activa.
        $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
        if ( $active_offer && $offer && $active_offer['type'] === $offer['type'] ) {
            // Ya tiene esta misma oferta activa. No le ofrecemos de nuevo.
            $offer = false;
        }

        // Si estamos en el paso 1, pero no hay oferta (porque no cumple requisitos de antigüedad, o porque ya tuvo esta oferta y no hay otra, o porque la oferta ya venció)
        // Y tampoco tiene una oferta activa rompiendo permanencia (o si la tenía, ya venció el target_delivery)
        // -> Saltamos directamente al paso 2 (motivo de cancelación)
        if ( $step === '1' ) {
            $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
            $has_unmet_permanence = ( $active_offer && $active_index < intval( $active_offer['target_delivery'] ) );

            if ( ! $offer && ! $has_unmet_permanence ) {
                $step = '2';
            }
        }

        echo '<div class="acf-woo-fasciculos-cancellation-flow">';

        switch ( $step ) {
            case '1':
                $this->render_step_1( $subscription, $offer, $active_offer );
                break;
            case '2':
                $this->render_step_2( $subscription );
                break;
            case '3':
                $this->render_step_3();
                break;
            default:
                $this->render_step_1( $subscription, $offer, $active_offer );
                break;
        }

        echo '</div>';
    }

    /**
     * Determina qué oferta de retención mostrar basada en el índice actual
     *
     * @param WC_Subscription $subscription
     * @return array|false Datos de la oferta o false si no aplica
     */
    private function get_retention_offer( $subscription ) {
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        // "despues del primer pedido" (el checkout fue 0, el primer pedido de renovacion lo pone en 1) -> 20% hasta el envio 13
        // "despues del segundo pedido" (el checkout fue 0, el primer pedido de renovacion 1, el segundo pedido 2) -> 10% hasta envio 5

        if ( $active_index === 1 ) {
            return array(
                'type' => 'after_1st',
                'discount' => 20, // 20%
                'target_delivery' => 13, // hasta la entrega 13 incluida (indice 12)
                'message' => __( 'Piénsalo, te ofrecemos un 20% de descuento hasta la entrega 13* (incluida) para ayudarte a que finalices tu colección', 'acf-woo-fasciculos' )
            );
        } elseif ( $active_index === 2 ) {
            return array(
                'type' => 'after_2nd',
                'discount' => 10, // 10%
                'target_delivery' => 5, // hasta la entrega 5 incluida (indice 4)
                'message' => __( 'Piénsalo, te ofrecemos un 10% de descuento hasta la entrega 5* (incluida) para ayudarte a que finalices tu colección', 'acf-woo-fasciculos' )
            );
        }

        return false;
    }

    /**
     * Renderiza el paso 1 (Oferta de Retención)
     */
    private function render_step_1( $subscription, $offer, $active_offer ) {
        $subscription_id = $subscription->get_id();
        $base_url = wc_get_endpoint_url( 'cancelar-fasciculo', $subscription_id, wc_get_page_permalink( 'myaccount' ) );

        echo '<h2>' . esc_html__( '¿Seguro que deseas cancelar?', 'acf-woo-fasciculos' ) . '</h2>';

        if ( $offer ) {
            // Mostrar la oferta de retención
            echo '<p><strong>' . esc_html( $offer['message'] ) . '</strong></p>';
            if ( $active_offer ) {
                echo '<p>' . esc_html__( 'Al aceptar esta nueva oferta, el descuento actual que tienes se sustituirá por este nuevo descuento.', 'acf-woo-fasciculos' ) . '</p>';
            }
            echo '<p><small>' . esc_html__( '*Permanencia Obligatoria de entregas para obtener el descuento. Si se cancela antes de la entrega acordada por la permanencia se cobrarán las entregas ya realizadas al PVP establecido sin descuento.', 'acf-woo-fasciculos' ) . '</small></p>';

            echo '<div class="cancellation-actions" style="margin-top:20px; display:flex; gap:10px;">';
            // Botón aceptar oferta
            echo '<form method="post" action="">';
            wp_nonce_field( 'accept_retention_offer_' . $subscription_id, 'fasciculos_retention_nonce' );
            echo '<input type="hidden" name="fasciculos_action" value="accept_offer">';
            echo '<input type="hidden" name="offer_type" value="' . esc_attr( $offer['type'] ) . '">';
            echo '<button type="submit" class="button alt">' . esc_html__( 'No, quiero completarla', 'acf-woo-fasciculos' ) . '</button>';
            echo '</form>';

            // Botón continuar cancelando
            $next_url = add_query_arg( 'step', '2', $base_url );
            echo '<a href="' . esc_url( $next_url ) . '" class="button">' . esc_html__( 'Sí, quiero cancelar', 'acf-woo-fasciculos' ) . '</a>';
            echo '</div>';

        } elseif ( $active_offer ) {
            // Ya tiene una oferta activa y está dentro del periodo de permanencia
            echo '<p>' . esc_html__( 'Actualmente disfrutas de un descuento por permanencia en tu suscripción. Si cancelas ahora, se cobrarán las entregas ya realizadas al PVP establecido sin descuento.', 'acf-woo-fasciculos' ) . '</p>';

            echo '<div class="cancellation-actions" style="margin-top:20px; display:flex; gap:10px;">';
            echo '<a href="' . esc_url( $subscription->get_view_order_url() ) . '" class="button alt">' . esc_html__( 'No, quiero completarla', 'acf-woo-fasciculos' ) . '</a>';
            $next_url = add_query_arg( 'step', '2', $base_url );
            echo '<a href="' . esc_url( $next_url ) . '" class="button">' . esc_html__( 'Sí, quiero cancelar asumiendo la penalización', 'acf-woo-fasciculos' ) . '</a>';
            echo '</div>';

        }
    }

    /**
     * Renderiza el paso 2 (Confirmación Final y Motivo)
     */
    private function render_step_2( $subscription ) {
        $subscription_id = $subscription->get_id();

        echo '<h2>' . esc_html__( 'Importante sobre la cancelación de tu entrega', 'acf-woo-fasciculos' ) . '</h2>';
        echo '<p>' . esc_html__( 'Si tu envío ya está en curso o ha salido de nuestras instalaciones de reparto, no será posible anular esa entrega. En ese caso, procederemos a cancelar automáticamente la siguiente entrega pendiente, para que no tengas que hacer ningún trámite adicional.', 'acf-woo-fasciculos' ) . '</p>';
        echo '<p>' . esc_html__( 'Nuestro objetivo es garantizarte un servicio rápido, seguro y transparente. Si necesitas más información o gestionar cualquier cambio, estamos aquí para ayudarte.', 'acf-woo-fasciculos' ) . '</p>';

        echo '<form method="post" action="">';
        wp_nonce_field( 'confirm_cancellation_' . $subscription_id, 'fasciculos_cancel_nonce' );
        echo '<input type="hidden" name="fasciculos_action" value="confirm_cancel">';

        echo '<p class="form-row form-row-wide">';
        echo '<label for="cancellation_reason">' . esc_html__( 'Motivo de la cancelación', 'acf-woo-fasciculos' ) . '</label>';
        echo '<textarea name="cancellation_reason" id="cancellation_reason" rows="3" required></textarea>';
        echo '</p>';

        echo '<p>';
        echo '<button type="submit" class="button alt">' . esc_html__( 'He leido y Cancelo Suscripción', 'acf-woo-fasciculos' ) . '</button>';
        echo '</p>';
        echo '</form>';
    }

    /**
     * Procesa los formularios de cancelación (aceptar oferta o confirmar cancelación)
     */
    public function process_cancellation_forms() {
        if ( ! isset( $_POST['fasciculos_action'] ) ) {
            return;
        }

        global $wp;
        if ( ! isset( $wp->query_vars['cancelar-fasciculo'] ) ) {
            return;
        }

        $subscription_id = intval( $wp->query_vars['cancelar-fasciculo'] );
        $subscription = wcs_get_subscription( $subscription_id );

        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
            return;
        }

        // Verificar permisos
        if ( ! current_user_can( 'view_order', $subscription_id ) && ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( $_POST['fasciculos_action'] === 'accept_offer' ) {
            $this->handle_accept_offer( $subscription );
        } elseif ( $_POST['fasciculos_action'] === 'confirm_cancel' ) {
            $this->handle_confirm_cancellation( $subscription );
        }
    }

    /**
     * Maneja la aceptación de la oferta de retención
     *
     * @param WC_Subscription $subscription
     */
    private function handle_accept_offer( $subscription ) {
        if ( ! isset( $_POST['fasciculos_retention_nonce'] ) || ! wp_verify_nonce( $_POST['fasciculos_retention_nonce'], 'accept_retention_offer_' . $subscription->get_id() ) ) {
            wc_add_notice( __( 'Error de seguridad al procesar la oferta.', 'acf-woo-fasciculos' ), 'error' );
            return;
        }

        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
        $posted_offer_type = isset( $_POST['offer_type'] ) ? sanitize_text_field( $_POST['offer_type'] ) : '';

        // Recalcular cuál es la oferta legítima a la que tiene derecho ahora mismo
        $valid_offer = $this->get_retention_offer( $subscription );

        if ( ! $valid_offer || $valid_offer['type'] !== $posted_offer_type ) {
            wc_add_notice( __( 'La oferta seleccionada ya no es válida o no está disponible.', 'acf-woo-fasciculos' ), 'error' );
            return;
        }

        $offer_type = $valid_offer['type'];
        $discount_amount = intval( $valid_offer['discount'] );
        $target_delivery = intval( $valid_offer['target_delivery'] );

        if ( $discount_amount > 0 ) {

            // Si el cliente tenía un cupón anteriormente, se sustituye y no se acumula
            $old_coupon_code = $subscription->get_meta( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
            if ( $old_coupon_code ) {
                $subscription->remove_coupon( $old_coupon_code );

                // Solo borrar físicamente si es un cupón generado por nosotros para esta función
                if ( strpos( $old_coupon_code, 'retencion-' ) === 0 ) {
                    $old_coupon = new WC_Coupon( $old_coupon_code );
                    if ( $old_coupon->get_id() ) {
                        $old_coupon->delete( true );
                    }
                }
            }

            // Guardar oferta activa
            $offer_data = array(
                'type' => $offer_type,
                'discount' => $discount_amount,
                'start_index' => $active_index,
                'target_delivery' => $target_delivery,
                'total_discounted' => 0 // Ya no lo usamos para pedido automatico, pero lo podemos guardar para logs de soporte
            );
            $subscription->update_meta_data( '_fasciculos_active_retention_offer', $offer_data );

            // Crear nuevo cupón
            $coupon_code = 'retencion-' . $subscription->get_id() . '-' . wp_generate_password( 6, false );
            $coupon = new WC_Coupon();
            $coupon->set_code( $coupon_code );
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( $discount_amount );
            // IMPORTANTE: No limitamos el uso a 1 porque debe usarse en cada renovación durante la permanencia
            $coupon->save();

            // Guardar el cupón para que se aplique en renovaciones
            $subscription->update_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON, $coupon_code );

            // Aplicar el cupón a la suscripción
            $subscription->apply_coupon( $coupon_code );

            $subscription->add_order_note( sprintf( __( '✅ Oferta de retención aceptada: %d%% de descuento. Permanencia hasta entrega %d.', 'acf-woo-fasciculos' ), $discount_amount, $target_delivery ) );
            $subscription->calculate_totals();
            $subscription->save();

            wc_add_notice( __( '¡Gracias por quedarte! Tu descuento se ha aplicado a tu suscripción para las próximas entregas.', 'acf-woo-fasciculos' ), 'success' );

            // Redirigir de vuelta a la suscripción
            wp_redirect( $subscription->get_view_order_url() );
            exit;
        }
    }

    /**
     * Maneja la confirmación de la cancelación
     *
     * @param WC_Subscription $subscription
     */
    private function handle_confirm_cancellation( $subscription ) {
        if ( ! isset( $_POST['fasciculos_cancel_nonce'] ) || ! wp_verify_nonce( $_POST['fasciculos_cancel_nonce'], 'confirm_cancellation_' . $subscription->get_id() ) ) {
            wc_add_notice( __( 'Error de seguridad al procesar la cancelación.', 'acf-woo-fasciculos' ), 'error' );
            return;
        }

        $reason = isset( $_POST['cancellation_reason'] ) ? sanitize_textarea_field( $_POST['cancellation_reason'] ) : '';

        // Guardar motivo
        $subscription->update_meta_data( '_fasciculos_cancellation_reason', $reason );
        $subscription->add_order_note( sprintf( __( 'Motivo de cancelación proporcionado: %s', 'acf-woo-fasciculos' ), $reason ) );

        // Cancelar suscripción
        $subscription->update_status( 'cancelled', __( 'Suscripción cancelada por el usuario desde su cuenta.', 'acf-woo-fasciculos' ) );

        // Si tenía cupón de permanencia, limpiarlo
        $coupon_code = $subscription->get_meta( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
        if ( $coupon_code ) {
            $subscription->remove_coupon( $coupon_code );
            $subscription->delete_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
        }

        $subscription->save();

        // Redirigir a la página de agradecimiento
        $success_url = wc_get_endpoint_url( 'cancelar-fasciculo', $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) );
        $success_url = add_query_arg( 'step', '3', $success_url );

        wp_redirect( $success_url );
        exit;
    }

    /**
     * Comprueba si la suscripción ha alcanzado la entrega objetivo y elimina el descuento por retención
     * Este método se engancha a `woocommerce_order_status_changed` con prioridad alta para que
     * se ejecute DESPUÉS de que ACF_Woo_Fasciculos_Orders haya avanzado el `active_index` de la suscripción.
     *
     * @param int $order_id ID del pedido.
     * @param string $old_status Estado anterior.
     * @param string $new_status Nuevo estado.
     * @param WC_Order $order Objeto del pedido.
     */
    public function check_and_expire_retention_discount( $order_id, $old_status, $new_status, $order ) {
        if ( ! in_array( $new_status, array( 'processing', 'completed' ), true ) ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        if ( ! ACF_Woo_Fasciculos_Utils::is_valid_order( $order ) || ! ACF_Woo_Fasciculos_Utils::is_renewal_order( $order ) ) {
            return;
        }

        $subscriptions = ACF_Woo_Fasciculos_Utils::get_renewal_subscriptions( $order_id );

        foreach ( $subscriptions as $subscription ) {
            if ( ! ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
                continue;
            }

            // Comprobar si hay una oferta activa
            $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
            if ( ! $active_offer ) {
                continue;
            }

            // Obtener el índice actual (ya actualizado por el order_status_progresses_renewal que se ejecuta antes)
            $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
            $target_delivery = intval( $active_offer['target_delivery'] );

            // Si el índice activo ha superado o alcanzado la entrega objetivo
            // Por ejemplo, si el target_delivery era 30 (entrega 30), expirará cuando active_index llegue a 30 (lo que significa que la entrega 30 se pagó y pasamos a preparar la 31).
            if ( $active_index >= $target_delivery ) {

                // 1. Eliminar cupón de la suscripción
                $coupon_code = $subscription->get_meta( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
                if ( $coupon_code ) {
                    $subscription->remove_coupon( $coupon_code );
                    $subscription->delete_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );

                    // Solo borrar físicamente si es un cupón generado por nosotros para esta función
                    if ( strpos( $coupon_code, 'retencion-' ) === 0 ) {
                        $coupon = new WC_Coupon( $coupon_code );
                        if ( $coupon->get_id() ) {
                            $coupon->delete( true );
                        }
                    }
                }

                // 2. Limpiar la oferta activa
                $subscription->delete_meta_data( '_fasciculos_active_retention_offer' );

                // 3. Añadir nota de caducidad
                $subscription->add_order_note( sprintf( __( '⏳ El periodo de retención ha finalizado al alcanzar la entrega %d. Los descuentos acumulados han sido retirados para próximas entregas.', 'acf-woo-fasciculos' ), $target_delivery ) );
                $subscription->save();
            }
        }
    }

    /**
     * Renderiza el paso 3 (Agradecimiento)
     */
    private function render_step_3() {
        echo '<h2>' . esc_html__( '¡Gracias!', 'acf-woo-fasciculos' ) . '</h2>';
        echo '<p>' . esc_html__( 'Tu petición ha sido enviada.', 'acf-woo-fasciculos' ) . '</p>';
        echo '<p><a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '" class="button">' . esc_html__( 'Volver a mi cuenta', 'acf-woo-fasciculos' ) . '</a></p>';
    }

}
