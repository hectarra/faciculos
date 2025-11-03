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
    
    // Obtener el índice activo del item
    $active = $item->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
    
    // Si no hay índice activo, no mostrar nada
    if ( '' === $active || $active === null ) {
        return;
    }
    
    // Verificar si el item pertenece a una suscripción y obtener el índice actualizado
    $order_id = $item->get_order_id();
    if ( $order_id && function_exists( 'wcs_get_subscriptions_for_order' ) ) {
        $subscriptions = wcs_get_subscriptions_for_order( $order_id, array( 'order_type' => 'any' ) );
        
        foreach ( $subscriptions as $subscription ) {
            if ( ACF_Woo_Fasciculos_Utils::is_valid_subscription( $subscription ) ) {
                $subscription_active = $subscription->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
                if ( '' !== $subscription_active && $subscription_active !== null ) {
                    $active = $subscription_active;
                    break;
                }
            }
        }
    }
    
    // Mostrar la semana actual
    echo '<div style="font-size:12px;color:#444;">';
    printf(
        /* translators: %d: week number */
        esc_html__( 'Semana actual fascículos: %d', 'acf-woo-fasciculos' ),
        intval( $active ) + 1
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

        return $hidden_meta;
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