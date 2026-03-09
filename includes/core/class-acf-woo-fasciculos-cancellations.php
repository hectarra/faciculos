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

        // Comprobar si hay que hacer flush de las reglas
        if ( ! get_option( 'acf_woo_fasciculos_flush_rewrite_rules' ) ) {
            add_action( 'wp_loaded', function() {
                flush_rewrite_rules();
                update_option( 'acf_woo_fasciculos_flush_rewrite_rules', true );
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

        // Si existe el botón de cancelar, modificar su URL
        if ( isset( $actions['cancel'] ) ) {
            $cancel_url = wc_get_endpoint_url( 'cancelar-fasciculo', $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) );
            $actions['cancel']['url'] = $cancel_url;
            // Opcionalmente podemos añadir una clase CSS para evitar JS de WCS si lo hubiera
            $actions['cancel']['class'] .= ' fasciculos-cancel-btn';
        }

        return $actions;
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

        // "despues de la primera renovacion" significa índice > 0 (es decir, ya ha cobrado la primera y está en la semana 2 o más)
        // Pero el user dice "despues de la primera renovacion (entrega 2)".
        // Entendamos:
        // 0 -> checkout (entrega 1)
        // 1 -> 1ra renovación (entrega 2)
        // 2 -> 2da renovación (entrega 3)
        // 3 -> 3ra renovación (entrega 4)

        if ( $active_index >= 3 ) { // Después de la tercera renovación (índice 3 o más)
            return array(
                'type' => 'after_3rd',
                'discount' => 5, // 5%
                'duration' => 10, // proximas 10 entregas
                'message' => __( 'Piénsalo, te ofrecemos un 5% de descuento en tus próximas 10 entregas* para ayudarte a que finalices tu colección', 'acf-woo-fasciculos' )
            );
        } elseif ( $active_index >= 1 ) { // Después de la primera renovación (índice 1 o 2)
            return array(
                'type' => 'after_1st',
                'discount' => 15, // 15%
                'target_delivery' => 30, // hasta la entrega 30 incluida
                'message' => __( 'Piénsalo, te ofrecemos un 15% de descuento hasta la entrega 30* (incluida) para ayudarte a que finalices tu colección', 'acf-woo-fasciculos' )
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
                echo '<p>' . esc_html__( 'Al aceptar esta nueva oferta, este descuento se sumará al que ya tienes por permanencia.', 'acf-woo-fasciculos' ) . '</p>';
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
            // Ya tiene una oferta activa, advertir sobre la penalización si rompe la permanencia
            echo '<p>' . esc_html__( 'Actualmente disfrutas de un descuento por permanencia en tu suscripción. Si cancelas ahora, se cobrarán las entregas ya realizadas al PVP establecido sin descuento.', 'acf-woo-fasciculos' ) . '</p>';

            echo '<div class="cancellation-actions" style="margin-top:20px; display:flex; gap:10px;">';
            echo '<a href="' . esc_url( $subscription->get_view_order_url() ) . '" class="button alt">' . esc_html__( 'No, quiero completarla', 'acf-woo-fasciculos' ) . '</a>';
            $next_url = add_query_arg( 'step', '2', $base_url );
            echo '<a href="' . esc_url( $next_url ) . '" class="button">' . esc_html__( 'Sí, quiero cancelar asumiendo la penalización', 'acf-woo-fasciculos' ) . '</a>';
            echo '</div>';

        } else {
            // No hay oferta (probablemente es la primera entrega o checkout apenas hecho)
            echo '<div class="cancellation-actions" style="margin-top:20px; display:flex; gap:10px;">';
            echo '<a href="' . esc_url( $subscription->get_view_order_url() ) . '" class="button alt">' . esc_html__( 'No quiero cancelar', 'acf-woo-fasciculos' ) . '</a>';
            $next_url = add_query_arg( 'step', '2', $base_url );
            echo '<a href="' . esc_url( $next_url ) . '" class="button">' . esc_html__( 'Continuar con la cancelación', 'acf-woo-fasciculos' ) . '</a>';
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

        $offer_type = isset( $_POST['offer_type'] ) ? sanitize_text_field( $_POST['offer_type'] ) : '';
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        $discount_amount = 0;
        $target_delivery = 0;

        if ( $offer_type === 'after_3rd' ) {
            $discount_amount = 5; // 5%
            // Si está en la semana 4 (índice 3), las próximas 10 entregas deben cubrir hasta la 14 (índice 13).
            // La expiración comprueba `$active_index >= $target_delivery`. Así que si es 14, en el índice 14 expirará.
            // Por lo tanto, el target index es índice actual (ej 3) + 11 = 14.
            $target_delivery = $active_index + 11;
        } elseif ( $offer_type === 'after_1st' ) {
            $discount_amount = 15; // 15%
            $target_delivery = 30; // Hasta entrega 30. Cuando active_index llegue a 30 (lo que sería la semana 31), se expira.
        }

        if ( $discount_amount > 0 ) {
            $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
            $final_discount = $discount_amount;
            $final_target = $target_delivery;
            $total_discounted_so_far = 0;

            if ( $active_offer ) {
                // Sumar al descuento existente
                $final_discount += floatval( $active_offer['discount'] );
                // Usar la permanencia más larga
                $final_target = max( $target_delivery, intval( $active_offer['target_delivery'] ) );
                $total_discounted_so_far = floatval( $active_offer['total_discounted'] );

                // Borrar cupón anterior
                $old_coupon_code = $subscription->get_meta( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
                if ( $old_coupon_code ) {
                    // Remover de la suscripción
                    $subscription->remove_coupon( $old_coupon_code );

                    $old_coupon = new WC_Coupon( $old_coupon_code );
                    if ( $old_coupon->get_id() ) {
                        $old_coupon->delete( true );
                    }
                }
            }

            // Guardar oferta activa
            $offer_data = array(
                'type' => $offer_type,
                'discount' => $final_discount,
                'start_index' => $active_index,
                'target_delivery' => $final_target,
                'total_discounted' => $total_discounted_so_far // Mantenemos el acumulado anterior
            );
            $subscription->update_meta_data( '_fasciculos_active_retention_offer', $offer_data );

            // Crear nuevo cupón acumulado
            $coupon_code = 'retencion-' . $subscription->get_id() . '-' . wp_generate_password( 6, false );
            $coupon = new WC_Coupon();
            $coupon->set_code( $coupon_code );
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( $final_discount );
            // IMPORTANTE: No limitamos el uso a 1 porque debe usarse en cada renovación durante la permanencia
            $coupon->save();

            // Guardar el cupón para que se aplique en renovaciones
            $subscription->update_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON, $coupon_code );

            // Aplicar el cupón a la suscripción
            $subscription->apply_coupon( $coupon_code );

            $subscription->add_order_note( sprintf( __( '✅ Oferta de retención aceptada. Descuento total ahora es %d%%. Permanencia hasta entrega %d.', 'acf-woo-fasciculos' ), $final_discount, $final_target ) );
            $subscription->calculate_totals();
            $subscription->save();

            wc_add_notice( __( '¡Gracias por quedarte! Tu descuento se ha sumado a tu suscripción para las próximas entregas.', 'acf-woo-fasciculos' ), 'success' );

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

        // Comprobar penalización por romper permanencia
        $this->check_and_apply_penalty( $subscription );

        // Cancelar suscripción
        $subscription->update_status( 'cancelled', __( 'Suscripción cancelada por el usuario desde su cuenta.', 'acf-woo-fasciculos' ) );
        $subscription->save();

        // Redirigir a la página de agradecimiento
        $success_url = wc_get_endpoint_url( 'cancelar-fasciculo', $subscription->get_id(), wc_get_page_permalink( 'myaccount' ) );
        $success_url = add_query_arg( 'step', '3', $success_url );

        wp_redirect( $success_url );
        exit;
    }

    /**
     * Comprueba si el usuario rompe la permanencia y aplica penalización si es así
     *
     * @param WC_Subscription $subscription
     */
    private function check_and_apply_penalty( $subscription ) {
        $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );

        if ( ! $active_offer ) {
            return; // No hay oferta activa, no hay permanencia que romper
        }

        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );
        $target_delivery = intval( $active_offer['target_delivery'] );
        $total_discounted = floatval( $active_offer['total_discounted'] );

        // Si el índice actual (que es la entrega que va a recibir o acaba de recibir) es MENOR que la entrega objetivo, rompe permanencia.
        // Recordar que active_index = 0 es entrega 1. Así que entrega 30 es active_index 29.
        if ( $active_index < $target_delivery && $total_discounted > 0 ) {

            // Crear un pedido de penalización
            $penalty_order = wc_create_order( array(
                'customer_id' => $subscription->get_customer_id(),
                'status' => 'pending'
            ) );

            if ( is_wp_error( $penalty_order ) ) {
                $subscription->add_order_note( __( 'Error al intentar crear pedido de penalización.', 'acf-woo-fasciculos' ) );
                return;
            }

            // Crear ítem virtual "Penalización por cancelación anticipada"
            $item = new WC_Order_Item_Fee();
            $item->set_name( __( 'Penalización por cancelación anticipada (Rotura de permanencia)', 'acf-woo-fasciculos' ) );
            $item->set_amount( $total_discounted );
            $item->set_total( $total_discounted );
            $penalty_order->add_item( $item );

            // Añadir información de facturación (copiada de la suscripción)
            $penalty_order->set_billing_first_name( $subscription->get_billing_first_name() );
            $penalty_order->set_billing_last_name( $subscription->get_billing_last_name() );
            $penalty_order->set_billing_email( $subscription->get_billing_email() );
            $penalty_order->set_billing_phone( $subscription->get_billing_phone() );
            // ... resto de campos si fuera necesario, o simplemente usar customer_id.

            $penalty_order->calculate_totals();
            $penalty_order->add_order_note( sprintf( __( 'Pedido generado automáticamente por rotura de permanencia en suscripción #%d.', 'acf-woo-fasciculos' ), $subscription->get_id() ) );
            $penalty_order->save();

            // Enlazar pedido a la suscripción
            $subscription->add_order_note( sprintf( __( '❌ Permanencia rota. Se ha generado un cargo pendiente de %s en el pedido #%d correspondiente a los descuentos disfrutados.', 'acf-woo-fasciculos' ), wc_price( $total_discounted ), $penalty_order->get_id() ) );
        }

        // Eliminar el cupón activo
        $coupon_code = $subscription->get_meta( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
        if ( $coupon_code ) {
            $subscription->remove_coupon( $coupon_code );
            // Eliminar el cupón para que no siga aplicándose, o no se use más.
            $subscription->delete_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
        }

        // Limpiar oferta activa para dejarlo registrado que ya no está activa, pero no borrar historial total
        // O simplemente dejarlo, ya que la suscripción se cancela.
    }

    /**
     * Acumula el descuento aplicado a la suscripción para el cálculo de penalización
     * Este método se llama cuando un pedido se completa
     *
     * @param int $order_id
     */
    public function accumulate_retention_discount( $order_id ) {
        $order = wc_get_order( $order_id );

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

            // Verificar si el descuento total en el pedido es mayor que 0
            $discount_total = $order->get_discount_total();

            if ( $discount_total > 0 ) {
                // Acumulamos el descuento
                $active_offer['total_discounted'] = (isset($active_offer['total_discounted']) ? floatval($active_offer['total_discounted']) : 0) + floatval($discount_total);
                $subscription->update_meta_data( '_fasciculos_active_retention_offer', $active_offer );
                $subscription->save();
            }
        }
    }

    /**
     * Comprueba si la suscripción ha alcanzado la entrega objetivo y elimina el descuento por retención
     *
     * @param int $order_id
     */
    public function check_and_expire_retention_discount( $order_id ) {
        $order = wc_get_order( $order_id );

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

                    // Borrar el cupón físico
                    $coupon = new WC_Coupon( $coupon_code );
                    if ( $coupon->get_id() ) {
                        $coupon->delete( true );
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
