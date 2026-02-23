<?php
/**
 * Manejador del checkout para el plugin ACF + Woo Subscriptions Fascículos
 *
 * Gestiona la creación automática de usuarios durante el checkout
 * y el envío de credenciales por email.
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar la creación automática de usuarios durante el checkout
 */
class ACF_Woo_Fasciculos_Checkout {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Crear usuario automáticamente durante el checkout si no está logueado
     *
     * Este método se ejecuta antes de procesar el checkout.
     * Si el cliente no está logueado, crea una cuenta automáticamente.
     *
     * @param array $posted_data Datos enviados en el checkout.
     * @return void
     */
    public function maybe_create_user_on_checkout( $posted_data ) {
        // Si ya está logueado, no hacer nada
        if ( is_user_logged_in() ) {
            return;
        }

        // Verificar si el carrito contiene productos con plan de fascículos
        if ( ! $this->cart_has_fasciculo_plan() ) {
            return;
        }

        // Obtener el email del checkout
        $email = isset( $posted_data['billing_email'] ) ? sanitize_email( $posted_data['billing_email'] ) : '';
        
        if ( empty( $email ) ) {
            return;
        }

        // Verificar si ya existe un usuario con ese email
        $existing_user = get_user_by( 'email', $email );
        if ( $existing_user ) {
            // Iniciar sesión automáticamente
            wc_set_customer_auth_cookie( $existing_user->ID );
            return;
        }

        // Crear el usuario
        $this->create_user_from_checkout( $posted_data );
    }

    /**
     * Procesar la creación de usuario después de crear el pedido
     *
     * @param int      $order_id ID del pedido.
     * @param array    $posted_data Datos enviados en el checkout.
     * @param WC_Order $order Objeto del pedido.
     * @return void
     */
    public function process_new_user_after_order( $order_id, $posted_data, $order ) {
        // Si ya está logueado, no hacer nada
        if ( is_user_logged_in() && get_current_user_id() > 0 ) {
            // Verificar si el pedido ya tiene un customer asignado
            $customer_id = $order->get_customer_id();
            if ( $customer_id > 0 ) {
                return;
            }
        }

        // Verificar si el pedido contiene productos con plan de fascículos
        if ( ! $this->order_has_fasciculo_plan( $order ) ) {
            return;
        }

        $email = $order->get_billing_email();
        
        if ( empty( $email ) ) {
            return;
        }

        // Verificar si ya existe un usuario con ese email
        $existing_user = get_user_by( 'email', $email );
        if ( $existing_user ) {
            // Asignar el usuario existente al pedido si no tiene uno
            if ( ! $order->get_customer_id() ) {
                $order->set_customer_id( $existing_user->ID );
                $order->save();
            }
            return;
        }

        // Crear el nuevo usuario
        $user_id = $this->create_user_for_order( $order );

        if ( $user_id && ! is_wp_error( $user_id ) ) {
            // Asignar el usuario al pedido
            $order->set_customer_id( $user_id );
            $order->save();

            // Iniciar sesión automáticamente
            wc_set_customer_auth_cookie( $user_id );

            // Agregar nota al pedido
            $order->add_order_note(
                sprintf(
                    /* translators: 1: user email */
                    __( '👤 Usuario creado automáticamente: %s — Se ha enviado email con los datos de acceso.', 'acf-woo-fasciculos' ),
                    $email
                )
            );
        }
    }

    /**
     * Verificar si el carrito contiene productos con plan de fascículos
     *
     * @return bool True si el carrito contiene productos con plan de fascículos.
     */
    private function cart_has_fasciculo_plan() {
        if ( ! WC()->cart ) {
            return false;
        }

        foreach ( WC()->cart->get_cart() as $cart_item ) {
            if ( isset( $cart_item[ ACF_Woo_Fasciculos::META_PLAN_CACHE ] ) && ! empty( $cart_item[ ACF_Woo_Fasciculos::META_PLAN_CACHE ] ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verificar si el pedido contiene productos con plan de fascículos
     *
     * @param WC_Order $order Pedido a verificar.
     * @return bool True si el pedido contiene productos con plan de fascículos.
     */
    private function order_has_fasciculo_plan( $order ) {
        if ( ! $order ) {
            return false;
        }

        foreach ( $order->get_items() as $item ) {
            $plan_cache = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
            if ( ! empty( $plan_cache ) ) {
                return true;
            }
        }

        // También verificar si hay suscripciones con plan de fascículos
        if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription( $order ) ) {
            $subscriptions = wcs_get_subscriptions_for_order( $order->get_id(), array( 'order_type' => 'parent' ) );
            foreach ( $subscriptions as $subscription ) {
                $plan_cache = $subscription->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
                if ( ! empty( $plan_cache ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Crear usuario desde los datos del checkout
     *
     * @param array $posted_data Datos del checkout.
     * @return int|WP_Error ID del usuario creado o error.
     */
    private function create_user_from_checkout( $posted_data ) {
        $email = isset( $posted_data['billing_email'] ) ? sanitize_email( $posted_data['billing_email'] ) : '';
        $first_name = isset( $posted_data['billing_first_name'] ) ? sanitize_text_field( $posted_data['billing_first_name'] ) : '';
        $last_name = isset( $posted_data['billing_last_name'] ) ? sanitize_text_field( $posted_data['billing_last_name'] ) : '';

        return $this->create_user( $email, $first_name, $last_name );
    }

    /**
     * Crear usuario para un pedido
     *
     * @param WC_Order $order Pedido desde el que obtener los datos.
     * @return int|WP_Error ID del usuario creado o error.
     */
    private function create_user_for_order( $order ) {
        $email = $order->get_billing_email();
        $first_name = $order->get_billing_first_name();
        $last_name = $order->get_billing_last_name();

        return $this->create_user( $email, $first_name, $last_name, $order );
    }

    /**
     * Crear usuario con los datos proporcionados
     *
     * @param string        $email Email del usuario.
     * @param string        $first_name Nombre del usuario.
     * @param string        $last_name Apellido del usuario.
     * @param WC_Order|null $order Pedido asociado (opcional).
     * @return int|WP_Error ID del usuario creado o error.
     */
    private function create_user( $email, $first_name, $last_name, $order = null ) {
        if ( empty( $email ) ) {
            return new WP_Error( 'empty_email', __( 'Email vacío.', 'acf-woo-fasciculos' ) );
        }

        // Generar nombre de usuario único
        $username = $this->generate_unique_username( $email );

        // Generar contraseña segura
        $password = wp_generate_password( 12, true, false );

        // Crear el usuario
        $user_id = wp_create_user( $username, $password, $email );

        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Actualizar datos del usuario
        wp_update_user(
            array(
                'ID'           => $user_id,
                'first_name'   => $first_name,
                'last_name'    => $last_name,
                'display_name' => trim( $first_name . ' ' . $last_name ) ?: $username,
                'role'         => 'customer',
            )
        );

        // Guardar datos de facturación
        if ( $order ) {
            $this->save_billing_address_to_user( $user_id, $order );
        }

        // Enviar email con las credenciales
        $this->send_credentials_email( $user_id, $email, $username, $password, $first_name );

        /**
         * Hook para acciones adicionales después de crear el usuario
         *
         * @param int           $user_id ID del usuario creado.
         * @param string        $email Email del usuario.
         * @param string        $password Contraseña generada.
         * @param WC_Order|null $order Pedido asociado.
         */
        do_action( 'acf_woo_fasciculos_user_created', $user_id, $email, $password, $order );

        return $user_id;
    }

    /**
     * Generar un nombre de usuario único basado en el email
     *
     * @param string $email Email base para generar el username.
     * @return string Username único.
     */
    private function generate_unique_username( $email ) {
        // Usar la parte antes del @ como base del username
        $username_base = sanitize_user( current( explode( '@', $email ) ), true );
        
        // Asegurar que el username tiene al menos 3 caracteres
        if ( strlen( $username_base ) < 3 ) {
            $username_base = 'user_' . $username_base;
        }

        $username = $username_base;
        $counter = 1;

        // Si ya existe un usuario con ese username, agregar número
        while ( username_exists( $username ) ) {
            $username = $username_base . $counter;
            $counter++;
        }

        return $username;
    }

    /**
     * Guardar dirección de facturación del pedido al usuario
     *
     * @param int      $user_id ID del usuario.
     * @param WC_Order $order Pedido con los datos de facturación.
     * @return void
     */
    private function save_billing_address_to_user( $user_id, $order ) {
        $billing_fields = array(
            'billing_first_name',
            'billing_last_name',
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_postcode',
            'billing_country',
            'billing_state',
            'billing_email',
            'billing_phone',
        );

        foreach ( $billing_fields as $field ) {
            $method = 'get_' . $field;
            if ( method_exists( $order, $method ) ) {
                $value = $order->$method();
                if ( ! empty( $value ) ) {
                    update_user_meta( $user_id, $field, $value );
                }
            }
        }
    }

    /**
     * Enviar email con las credenciales de acceso
     *
     * @param int    $user_id ID del usuario.
     * @param string $email Email del usuario.
     * @param string $username Nombre de usuario.
     * @param string $password Contraseña generada.
     * @param string $first_name Nombre del usuario.
     * @return bool True si el email se envió correctamente.
     */
    private function send_credentials_email( $user_id, $email, $username, $password, $first_name ) {
        $site_name = get_bloginfo( 'name' );
        $login_url = wc_get_page_permalink( 'myaccount' );
        
        // Si no hay página de mi cuenta configurada, usar wp-login
        if ( ! $login_url ) {
            $login_url = wp_login_url();
        }

        $user_greeting = ! empty( $first_name ) ? $first_name : $username;

        // Asunto del email
        $subject = sprintf(
            /* translators: %s: site name */
            __( 'Tus datos de acceso en %s', 'acf-woo-fasciculos' ),
            $site_name
        );

        // Contenido del email
        $message = $this->get_credentials_email_content( $user_greeting, $username, $password, $login_url, $site_name );

        // Headers para email HTML
        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $site_name . ' <' . get_option( 'admin_email' ) . '>',
        );

        // Permitir filtrar el email antes de enviarlo
        $email_args = apply_filters(
            'acf_woo_fasciculos_credentials_email_args',
            array(
                'to'      => $email,
                'subject' => $subject,
                'message' => $message,
                'headers' => $headers,
            ),
            $user_id,
            $password
        );

        return wp_mail(
            $email_args['to'],
            $email_args['subject'],
            $email_args['message'],
            $email_args['headers']
        );
    }

    /**
     * Obtener el contenido del email con las credenciales
     *
     * @param string $user_greeting Saludo personalizado.
     * @param string $username Nombre de usuario.
     * @param string $password Contraseña.
     * @param string $login_url URL de login.
     * @param string $site_name Nombre del sitio.
     * @return string Contenido HTML del email.
     */
    private function get_credentials_email_content( $user_greeting, $username, $password, $login_url, $site_name ) {
        // Obtener colores del tema de WooCommerce si están disponibles
        $base_color = get_option( 'woocommerce_email_base_color', '#96588a' );
        $text_color = get_option( 'woocommerce_email_text_color', '#333333' );
        $bg_color = get_option( 'woocommerce_email_background_color', '#f7f7f7' );
        $body_bg_color = get_option( 'woocommerce_email_body_background_color', '#ffffff' );

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo esc_html( $site_name ); ?></title>
        </head>
        <body style="margin: 0; padding: 0; background-color: <?php echo esc_attr( $bg_color ); ?>; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;">
            <table role="presentation" style="width: 100%; border-collapse: collapse;">
                <tr>
                    <td align="center" style="padding: 40px 0;">
                        <table role="presentation" style="width: 600px; max-width: 100%; border-collapse: collapse; background-color: <?php echo esc_attr( $body_bg_color ); ?>; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                            <!-- Header -->
                            <tr>
                                <td style="background-color: <?php echo esc_attr( $base_color ); ?>; padding: 30px 40px; text-align: center;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                                        <?php echo esc_html( $site_name ); ?>
                                    </h1>
                                </td>
                            </tr>
                            
                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px; color: <?php echo esc_attr( $text_color ); ?>;">
                                    <h2 style="margin: 0 0 20px; font-size: 20px; color: <?php echo esc_attr( $text_color ); ?>;">
                                        <?php
                                        printf(
                                            /* translators: %s: user name */
                                            esc_html__( '¡Hola %s!', 'acf-woo-fasciculos' ),
                                            esc_html( $user_greeting )
                                        );
                                        ?>
                                    </h2>
                                    
                                    <p style="margin: 0 0 20px; font-size: 16px; line-height: 1.6;">
                                        <?php esc_html_e( 'Se ha creado una cuenta para ti en nuestra tienda. A continuación encontrarás tus datos de acceso:', 'acf-woo-fasciculos' ); ?>
                                    </p>
                                    
                                    <!-- Credentials Box -->
                                    <table role="presentation" style="width: 100%; border-collapse: collapse; background-color: <?php echo esc_attr( $bg_color ); ?>; border-radius: 6px; margin: 20px 0;">
                                        <tr>
                                            <td style="padding: 25px;">
                                                <p style="margin: 0 0 15px; font-size: 14px;">
                                                    <strong style="color: <?php echo esc_attr( $base_color ); ?>;"><?php esc_html_e( 'Usuario:', 'acf-woo-fasciculos' ); ?></strong><br>
                                                    <span style="font-size: 16px; font-family: monospace; background: #ffffff; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 5px;"><?php echo esc_html( $username ); ?></span>
                                                </p>
                                                <p style="margin: 0; font-size: 14px;">
                                                    <strong style="color: <?php echo esc_attr( $base_color ); ?>;"><?php esc_html_e( 'Contraseña:', 'acf-woo-fasciculos' ); ?></strong><br>
                                                    <span style="font-size: 16px; font-family: monospace; background: #ffffff; padding: 4px 8px; border-radius: 4px; display: inline-block; margin-top: 5px;"><?php echo esc_html( $password ); ?></span>
                                                </p>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <!-- Button -->
                                    <p style="text-align: center; margin: 30px 0;">
                                        <a href="<?php echo esc_url( $login_url ); ?>" style="display: inline-block; background-color: <?php echo esc_attr( $base_color ); ?>; color: #ffffff; text-decoration: none; padding: 15px 35px; border-radius: 6px; font-size: 16px; font-weight: 600;">
                                            <?php esc_html_e( 'Acceder a mi cuenta', 'acf-woo-fasciculos' ); ?>
                                        </a>
                                    </p>
                                    
                                    <p style="margin: 20px 0 0; font-size: 14px; color: #666666; line-height: 1.6;">
                                        <?php esc_html_e( 'Te recomendamos cambiar tu contraseña después de tu primer acceso por una que te resulte más fácil de recordar.', 'acf-woo-fasciculos' ); ?>
                                    </p>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="background-color: <?php echo esc_attr( $bg_color ); ?>; padding: 25px 40px; text-align: center; border-top: 1px solid #e0e0e0;">
                                    <p style="margin: 0; font-size: 13px; color: #999999;">
                                        <?php
                                        printf(
                                            /* translators: %s: site name */
                                            esc_html__( '© %s. Todos los derechos reservados.', 'acf-woo-fasciculos' ),
                                            esc_html( $site_name )
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        return ob_get_clean();
    }

    /**
     * Forzar la creación de cuenta durante el checkout de suscripciones
     *
     * @param bool $value Valor actual de la opción.
     * @return bool Valor modificado.
     */
    public function force_account_creation_for_fasciculos( $value ) {
        // Verificar si el carrito contiene productos con plan de fascículos
        if ( $this->cart_has_fasciculo_plan() ) {
            return true;
        }

        return $value;
    }

    /**
     * Modificar campos del checkout para requerir cuenta
     *
     * @param array $fields Campos del checkout.
     * @return array Campos modificados.
     */
    public function maybe_require_account_fields( $fields ) {
        // Si el carrito tiene plan de fascículos, ocultar campos de "crear cuenta"
        // ya que la crearemos automáticamente
        if ( $this->cart_has_fasciculo_plan() && ! is_user_logged_in() ) {
            // Ocultar el checkbox de crear cuenta
            if ( isset( $fields['account']['createaccount'] ) ) {
                unset( $fields['account']['createaccount'] );
            }
            // Ocultar los campos de contraseña
            if ( isset( $fields['account']['account_password'] ) ) {
                unset( $fields['account']['account_password'] );
            }
            if ( isset( $fields['account']['account_password-2'] ) ) {
                unset( $fields['account']['account_password-2'] );
            }
        }

        return $fields;
    }

    /**
     * Agregar aviso en el checkout sobre la creación automática de cuenta
     *
     * @return void
     */
    public function add_auto_account_notice() {
        // Solo mostrar si no está logueado y el carrito tiene plan de fascículos
        if ( is_user_logged_in() || ! $this->cart_has_fasciculo_plan() ) {
            return;
        }

        ?>
        <div class="woocommerce-info fasciculo-auto-account-notice">
            <?php esc_html_e( 'Se creará automáticamente una cuenta para gestionar tu suscripción. Recibirás tus datos de acceso por email.', 'acf-woo-fasciculos' ); ?>
        </div>
        <?php
    }
}
