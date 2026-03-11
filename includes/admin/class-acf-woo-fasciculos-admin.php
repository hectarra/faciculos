<?php
/**
 * Manejador de administración para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar la funcionalidad de administración
 */
class ACF_Woo_Fasciculos_Admin {

    /**
     * Instancia del manejador de suscripciones
     *
     * @var ACF_Woo_Fasciculos_Subscriptions
     */
    private $subscriptions_handler;

    /**
     * Constructor
     * 
     * @param ACF_Woo_Fasciculos_Subscriptions $subscriptions_handler Manejador de suscripciones.
     */
    public function __construct( $subscriptions_handler = null ) {
        $this->subscriptions_handler = $subscriptions_handler;

        // Hooks
        add_action( 'add_meta_boxes', array( $this, 'add_cancellation_reason_meta_box' ) );
        add_action( 'add_meta_boxes', array( $this, 'add_retention_offers_meta_box' ) );
        add_action( 'woocommerce_process_shop_subscription_meta', array( $this, 'save_retention_offers_meta_box' ), 10, 2 );
    }

    /**
     * Añade un metabox para gestionar las ofertas de retención en los fascículos
     */
    public function add_retention_offers_meta_box() {
        add_meta_box(
            'fasciculos_retention_offers',
            __( 'Ofertas de Retención (Fascículos)', 'acf-woo-fasciculos' ),
            array( $this, 'render_retention_offers_meta_box' ),
            'shop_subscription',
            'side',
            'high'
        );
    }

    /**
     * Renderiza el contenido del metabox de ofertas de retención
     *
     * @param WP_Post $post
     */
    public function render_retention_offers_meta_box( $post ) {
        $subscription = wcs_get_subscription( $post->ID );

        if ( ! $subscription ) {
            return;
        }

        wp_nonce_field( 'save_retention_offers_' . $post->ID, 'fasciculos_retention_admin_nonce' );

        $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
        $current_type = $active_offer ? $active_offer['type'] : '';

        echo '<p>' . esc_html__( 'Selecciona una oferta para aplicarla manualmente o quitarla:', 'acf-woo-fasciculos' ) . '</p>';

        echo '<p>';
        echo '<label>';
        echo '<input type="radio" name="fasciculos_manual_offer" value="" ' . checked( $current_type, '', false ) . '> ';
        echo esc_html__( 'Ninguna (Eliminar ofertas activas)', 'acf-woo-fasciculos' );
        echo '</label><br><br>';

        echo '<label>';
        echo '<input type="radio" name="fasciculos_manual_offer" value="after_1st" ' . checked( $current_type, 'after_1st', false ) . '> ';
        echo esc_html__( 'Oferta 20% de descuento hasta el envío 13', 'acf-woo-fasciculos' );
        echo '</label><br><br>';

        echo '<label>';
        echo '<input type="radio" name="fasciculos_manual_offer" value="after_2nd" ' . checked( $current_type, 'after_2nd', false ) . '> ';
        echo esc_html__( 'Oferta 10% de descuento hasta el envío 5', 'acf-woo-fasciculos' );
        echo '</label>';
        echo '</p>';

        if ( $active_offer ) {
            echo '<hr>';
            echo '<p><strong>' . esc_html__( 'Estado Actual:', 'acf-woo-fasciculos' ) . '</strong></p>';
            echo '<p>' . sprintf( __( 'Descuento: %s%%', 'acf-woo-fasciculos' ), $active_offer['discount'] ) . '<br>';
            echo sprintf( __( 'Objetivo (Permanencia): Envío %s', 'acf-woo-fasciculos' ), $active_offer['target_delivery'] ) . '</p>';
        }
    }

    /**
     * Guarda la configuración del metabox de ofertas de retención
     *
     * @param int $post_id
     * @param WP_Post $post
     */
    public function save_retention_offers_meta_box( $post_id, $post ) {
        if ( ! isset( $_POST['fasciculos_retention_admin_nonce'] ) || ! wp_verify_nonce( $_POST['fasciculos_retention_admin_nonce'], 'save_retention_offers_' . $post_id ) ) {
            return;
        }

        $subscription = wcs_get_subscription( $post_id );
        if ( ! $subscription ) {
            return;
        }

        $new_offer_val = isset( $_POST['fasciculos_manual_offer'] ) ? sanitize_text_field( $_POST['fasciculos_manual_offer'] ) : '';
        $active_offer = $subscription->get_meta( '_fasciculos_active_retention_offer' );
        $current_type = $active_offer ? $active_offer['type'] : '';

        // Si no hay cambio, no hacer nada
        if ( $current_type === $new_offer_val ) {
            return;
        }

        // Si cambiamos, primero limpiamos lo viejo
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

            $subscription->delete_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON );
        }

        $subscription->delete_meta_data( '_fasciculos_active_retention_offer' );

        // Si el admin seleccionó "Ninguna", ya hemos limpiado, así que paramos y guardamos.
        if ( empty( $new_offer_val ) ) {
            $subscription->calculate_totals();
            $subscription->save();
            return;
        }

        // Si seleccionó una oferta nueva, la construimos
        $active_index = intval( $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX ) );

        $discount_amount = 0;
        $target_delivery = 0;

        if ( $new_offer_val === 'after_1st' ) {
            $discount_amount = 20;
            $target_delivery = 13;
        } elseif ( $new_offer_val === 'after_2nd' ) {
            $discount_amount = 10;
            $target_delivery = 5;
        }

        if ( $discount_amount > 0 ) {
            $offer_data = array(
                'type' => $new_offer_val,
                'discount' => $discount_amount,
                'start_index' => $active_index,
                'target_delivery' => $target_delivery,
                'total_discounted' => 0
            );
            $subscription->update_meta_data( '_fasciculos_active_retention_offer', $offer_data );

            $coupon_code = 'retencion-' . $subscription->get_id() . '-manual-' . wp_generate_password( 4, false );
            $coupon = new WC_Coupon();
            $coupon->set_code( $coupon_code );
            $coupon->set_discount_type( 'percent' );
            $coupon->set_amount( $discount_amount );
            $coupon->save();

            $subscription->update_meta_data( ACF_Woo_Fasciculos::META_DISCOUNT_COUPON, $coupon_code );
            $subscription->apply_coupon( $coupon_code );
            $subscription->add_order_note( sprintf( __( '✅ Oferta de retención aplicada manualmente desde admin: %d%% de descuento. Permanencia hasta entrega %d.', 'acf-woo-fasciculos' ), $discount_amount, $target_delivery ) );
        }

        $subscription->calculate_totals();
        $subscription->save();
    }

    /**
     * Mostrar la semana activa en la interfaz de administración
     *
     * Este método se ejecuta en la columna de productos en el panel de administración.
     * Muestra la semana actual del plan de fascículos.
     *
     * @param WC_Product      $_product Producto (no utilizado directamente).
     * @param WC_Order_Item   $item Item del pedido.
     * @param int             $item_id ID del item.
     * @return void
     */
public function show_active_week( $_product, $item, $item_id ) {
      // Solo mostrar en el panel de administración
    if ( ! is_admin() ) {
        return;
    }
    
    // Verificar si el item tiene marca de producto incluido (para evitar duplicados)
    $is_product_item = $item->get_meta( '_product_item' );
    if ( $is_product_item === 'yes' ) {
        return; // No mostrar barra en productos individuales
    }
    
    // Obtener el plan del item
    $plan_json = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
    if ( empty( $plan_json ) ) {
        return;
    }
    
    $plan = json_decode( $plan_json, true );
    if ( empty( $plan ) || ! is_array( $plan ) ) {
        return;
    }
    
    // Obtener el índice activo del item
    $active = $item->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
    
    // Si no hay índice activo, no mostrar nada
    if ( '' === $active || $active === null ) {
        return;
    }
    
    $active = intval( $active );
    $total_weeks = count( $plan );
    $current_week = $active + 1;
    
    // Calcular porcentaje de progreso
    $progress_percentage = $total_weeks > 0 ? round( ( $current_week / $total_weeks ) * 100 ) : 0;
    
    // Determinar el color de la barra según el progreso
    if ( $progress_percentage >= 100 ) {
        $bar_color = '#4caf50'; // Verde - completado
    } elseif ( $progress_percentage >= 50 ) {
        $bar_color = '#2196f3'; // Azul - más de mitad
    } else {
        $bar_color = '#ff9800'; // Naranja - inicio
    }
    
    // Mostrar la barra de progreso
    echo '<div class="fasciculo-progress-container" style="margin-top:8px;padding:8px;background:#f8f9fa;border-radius:6px;border:1px solid #e0e0e0;">';
    
    // Título
    echo '<div style="font-size:11px;color:#666;margin-bottom:4px;font-weight:600;">📚 Progreso del Plan</div>';
    
    // Barra de progreso
    echo '<div style="background:#e0e0e0;border-radius:4px;height:12px;overflow:hidden;margin-bottom:4px;">';
    printf(
        '<div style="background:%s;height:100%%;width:%d%%;transition:width 0.3s ease;border-radius:4px;"></div>',
        esc_attr( $bar_color ),
        $progress_percentage
    );
    echo '</div>';
    
    // Texto de estado
    printf(
        '<div style="display:flex;justify-content:space-between;font-size:11px;color:#444;">'
        . '<span><strong>Semana %d</strong> de %d</span>'
        . '<span style="font-weight:600;color:%s;">%d%%</span>'
        . '</div>',
        $current_week,
        $total_weeks,
        esc_attr( $bar_color ),
        $progress_percentage
    );
    
    echo '</div>';
}

    /**
     * Ocultar metadatos internos en la interfaz de administración
     *
     * @param array $hidden_meta Metadatos que deben estar ocultos.
     * @return array Metadatos modificados.
     */
    public function hide_internal_meta( $hidden_meta ) {
        // Agregar nuestros metadatos a la lista de ocultos
        $hidden_meta[] = ACF_Woo_Fasciculos::META_PLAN_CACHE;
        $hidden_meta[] = ACF_Woo_Fasciculos::META_ACTIVE_INDEX;
        $hidden_meta[] = ACF_Woo_Fasciculos::META_FIRST_UPDATE;
        $hidden_meta[] = '_fasciculo_included';
        $hidden_meta[] = '_product_item';
        $hidden_meta[] = '_fasciculos_cancellation_reason';
        $hidden_meta[] = '_fasciculos_active_retention_offer';

        return $hidden_meta;
    }

    /**
     * Añade un metabox para mostrar el motivo de cancelación de los fascículos
     */
    public function add_cancellation_reason_meta_box() {
        add_meta_box(
            'fasciculos_cancellation_reason',
            __( 'Motivo de Cancelación', 'acf-woo-fasciculos' ),
            array( $this, 'render_cancellation_reason_meta_box' ),
            'shop_subscription',
            'side',
            'high'
        );
    }

    /**
     * Renderiza el contenido del metabox de motivo de cancelación
     *
     * @param WP_Post $post
     */
    public function render_cancellation_reason_meta_box( $post ) {
        $subscription = wcs_get_subscription( $post->ID );

        if ( ! $subscription ) {
            return;
        }

        $reason = $subscription->get_meta( '_fasciculos_cancellation_reason' );

        if ( ! empty( $reason ) ) {
            echo '<p><strong>' . esc_html__( 'Motivo proporcionado por el usuario:', 'acf-woo-fasciculos' ) . '</strong></p>';
            echo '<p><em>' . esc_html( $reason ) . '</em></p>';
        } else {
            echo '<p>' . esc_html__( 'No hay motivo de cancelación registrado.', 'acf-woo-fasciculos' ) . '</p>';
        }
    }

    

    /**
     * Agregar columnas personalizadas a la lista de pedidos
     *
     * @param array $columns Columnas existentes.
     * @return array Columnas modificadas.
     */
    public function add_order_columns( $columns ) {
        // Agregar columna de semana actual después de la columna 'order_total'
        $new_columns = array();
        
        foreach ( $columns as $key => $column ) {
            $new_columns[ $key ] = $column;
            
            if ( 'order_total' === $key ) {
                $new_columns['fasciculos_week'] = __( 'Semana Actual', 'acf-woo-fasciculos' );
            }
        }

        return $new_columns;
    }

    /**
     * Mostrar datos en las columnas personalizadas
     *
     * @param string $column Nombre de la columna.
     * @param WC_Order $order Objeto del pedido.
     * @return void
     */
    public function render_order_column( $column, $order ) {
        if ( 'fasciculos_week' !== $column ) {
            return;
        }

        // Obtener información de fascículos del pedido
        $fasciculos_info = $this->get_order_fasciculos_info( $order );
        
        if ( ! $fasciculos_info['has_fasciculos'] ) {
            echo '&ndash;';
            return;
        }

        // Mostrar la semana actual del primer item con fascículos
        $first_item = reset( $fasciculos_info['items'] );
        printf(
            '%d/%d',
            $first_item['active_index'] + 1,
            count( $first_item['plan'] )
        );
    }

    /**
     * Agregar información del plugin a la pantalla "Sistema de Estado" de WooCommerce
     *
     * @param array $debug_data Datos de debugging.
     * @return array Datos modificados.
     */
    public function add_system_status_info( $debug_data ) {
        $debug_data['acf_woo_fasciculos'] = array(
            'name' => __( 'ACF + Woo Subscriptions Fascículos', 'acf-woo-fasciculos' ),
            'info' => $this->get_system_status_info(),
        );

        return $debug_data;
    }

    /**
     * Obtener información para la pantalla de estado del sistema
     *
     * @return string Información formateada.
     */
    private function get_system_status_info() {
        $info = array();
        
        // Versión del plugin
        $info[] = sprintf(
            /* translators: %s: plugin version */
            __( 'Versión: %s', 'acf-woo-fasciculos' ),
            ACF_WOO_FASCICULOS_VERSION
        );

        // Estado de ACF
        $acf_handler = new ACF_Woo_Fasciculos_ACF();
        if ( $acf_handler->is_acf_available() ) {
            $info[] = __( 'ACF: Activo', 'acf-woo-fasciculos' );
        } else {
            $info[] = __( 'ACF: No detectado', 'acf-woo-fasciculos' );
        }

        // Número de productos con plan
        $products_with_plan = $this->count_products_with_plan();
        $info[] = sprintf(
            /* translators: %d: number of products */
            _n( '%d producto con plan', '%d productos con plan', $products_with_plan, 'acf-woo-fasciculos' ),
            $products_with_plan
        );

        return implode( ' | ', $info );
    }

    /**
     * Contar productos que tienen plan de fascículos
     *
     * @return int Número de productos.
     */
    private function count_products_with_plan() {
        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => ACF_Woo_Fasciculos::META_PLAN_KEY,
                    'compare' => 'EXISTS',
                ),
            ),
        );

        $query = new WP_Query( $args );
        return $query->found_posts;
    }

    /**
     * Agregar enlaces de acción en la página de plugins
     *
     * @param array  $links Enlaces existentes.
     * @param string $file Archivo del plugin.
     * @return array Enlaces modificados.
     */
    public function add_plugin_action_links( $links, $file ) {
        if ( ACF_WOO_FASCICULOS_PLUGIN_BASENAME !== $file ) {
            return $links;
        }

        // Agregar enlace de configuración (si existe página de configuración)
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wc-settings&tab=products' ),
            __( 'Configuración', 'acf-woo-fasciculos' )
        );
        array_unshift( $links, $settings_link );

        // Agregar enlace de documentación
        $docs_link = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://tuequipo.com/docs/acf-woo-fasciculos',
            __( 'Documentación', 'acf-woo-fasciculos' )
        );
        array_push( $links, $docs_link );

        return $links;
    }

    /**
     * Agregar enlaces de metadatos en la página de plugins
     *
     * @param array  $links Enlaces existentes.
     * @param string $file Archivo del plugin.
     * @return array Enlaces modificados.
     */
    public function add_plugin_row_meta( $links, $file ) {
        if ( ACF_WOO_FASCICULOS_PLUGIN_BASENAME !== $file ) {
            return $links;
        }

        // Agregar enlace de soporte
        $support_link = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://tuequipo.com/soporte',
            __( 'Soporte', 'acf-woo-fasciculos' )
        );
        $links[] = $support_link;

        // Agregar enlace de valoración
        $rate_link = sprintf(
            '<a href="%s" target="_blank">%s</a>',
            'https://wordpress.org/support/plugin/acf-woo-fasciculos/reviews/',
            __( 'Valorar', 'acf-woo-fasciculos' )
        );
        $links[] = $rate_link;

        return $links;
    }

    /**
     * Obtener información de fascículos de un pedido
     *
     * @param WC_Order $order Pedido.
     * @return array Información de fascículos.
     */
    private function get_order_fasciculos_info( $order ) {
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