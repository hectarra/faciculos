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
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
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
     * Mostrar información limpia del plan en la interfaz de administración
     *
     * Este método se ejecuta después de mostrar los metadatos de un item.
     * Muestra información formateada sobre el plan de fascículos.
     *
     * @param int           $item_id ID del item.
     * @param WC_Order_Item $item Item del pedido.
     * @param WC_Product    $product Producto.
     * @return void
     */
    public function display_plan_info( $item_id, $item, $product ) {
        // Solo mostrar en el panel de administración
        if ( ! is_admin() ) {
            return;
        }

        // Obtener el plan del item
        $plan_json = $item->get_meta( ACF_Woo_Fasciculos::META_PLAN_CACHE );
        
        if ( ! $plan_json ) {
            return;
        }

        // Obtener el índice activo
        $active_index = $item->get_meta( ACF_Woo_Fasciculos::META_ACTIVE_INDEX );
        
        // Decodificar el plan
        $plan = json_decode( $plan_json, true );
        if ( empty( $plan ) || ! is_array( $plan ) ) {
            return;
        }

        // Calcular la semana actual
        $current_index = ( '' !== $active_index && $active_index !== null ) ? intval( $active_index ) : 0;
        $current_row = ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $current_index );

        // Generar el HTML de la información del plan
        $this->output_plan_info_html( $current_index, $current_row, $plan );
    }

    /**
     * Generar el HTML de la información del plan
     *
     * @param int   $current_index Índice actual.
     * @param array $current_row Fila actual del plan.
     * @param array $plan Plan completo.
     * @return void
     */
    private function output_plan_info_html( $current_index, $current_row, $plan ) {
        // Abrir contenedor
        echo '<div class="fasciculos-info" style="margin-top:10px; padding:10px; background:#f9f9f9; border-left:3px solid #2271b1;">';

        // Título
        echo '<strong style="color:#2271b1;">📋 ' . esc_html__( 'Plan de Fascículos', 'acf-woo-fasciculos' ) . '</strong><br>';

        // Información general
        printf(
            '<span style="font-size:12px; color:#666;">' . esc_html__( 'Semana actual: %1$s de %2$s', 'acf-woo-fasciculos' ) . '</span><br>',
            '<strong>' . ( $current_index + 1 ) . '</strong>',
            '<strong>' . count( $plan ) . '</strong>'
        );

        // Información específica de la semana actual
        if ( $current_row ) {
            $product_name = ACF_Woo_Fasciculos_Utils::get_product_name( $current_row['product_id'] );
            
            echo '<span style="font-size:12px; color:#666;">' . esc_html__( 'Producto:', 'acf-woo-fasciculos' ) . ' <strong>' . esc_html( $product_name ) . '</strong></span><br>';
            
            printf(
                '<span style="font-size:12px; color:#666;">' . esc_html__( 'Precio: %s', 'acf-woo-fasciculos' ) . '</span>',
                '<strong>' . ACF_Woo_Fasciculos_Utils::format_price( $current_row['price'] ) . '</strong>'
            );

            // Nota si existe
            if ( ! empty( $current_row['note'] ) ) {
                echo '<br><span style="font-size:11px; color:#999;">' . esc_html__( 'Nota:', 'acf-woo-fasciculos' ) . ' ' . esc_html( $current_row['note'] ) . '</span>';
            }
        }

        // Cerrar contenedor
        echo '</div>';
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