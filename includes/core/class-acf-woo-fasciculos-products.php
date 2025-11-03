<?php
/**
 * Manejador de productos para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar toda la funcionalidad relacionada con productos
 */
class ACF_Woo_Fasciculos_Products {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Renderizar la tabla del plan de fascículos en la página del producto
     *
     * @return void
     */
    public function render_plan_table() {
        global $product;

        // Verificar que tengamos un producto válido
        if ( ! $product || ! ACF_Woo_Fasciculos_Utils::is_subscription_product( $product ) ) {
            return;
        }

        // Obtener el plan de fascículos
        $plan = ACF_Woo_Fasciculos_Utils::get_plan_for_product( $product->get_id() );

        // Si no hay plan, no mostrar nada
        if ( empty( $plan ) ) {
            return;
        }
        
        // Renderizar el mensaje con la semana actual
        $this->output_plan_table( $plan );
    
    }

    /**
     * Generar el HTML de la tabla del plan de fascículos
     *
     * @param array $plan Plan de fascículos.
     * @return void
     */
    private function output_plan_table( $plan ) {
        // Abrir contenedor
        echo '<div class="acf-wcs-fasciculos">';

        // Título
        echo '<h3>' . esc_html__( 'Plan de fascículos (semanas)', 'acf-woo-fasciculos' ) . '</h3>';

        // Abrir tabla
        echo '<table class="shop_table shop_table_responsive">';
        
        // Encabezado
        echo '<thead><tr>';
        echo '<th>' . esc_html__( '#', 'acf-woo-fasciculos' ) . '</th>';
        echo '<th>' . esc_html__( 'Producto', 'acf-woo-fasciculos' ) . '</th>';
        echo '<th>' . esc_html__( 'Precio', 'acf-woo-fasciculos' ) . '</th>';
        echo '<th>' . esc_html__( 'Nota', 'acf-woo-fasciculos' ) . '</th>';
        echo '</tr></thead>';

        // Cuerpo de la tabla
        echo '<tbody>';
        foreach ( $plan as $i => $row ) {
            $this->output_plan_table_row( $i, $row );
        }
        echo '</tbody>';

        // Cerrar tabla
        echo '</table>';

        // Descripción
        echo '<p class="description">';
        esc_html_e( 'En la compra se cobra la Semana 1. En cada renovación se avanza a la siguiente semana. La suscripción se cancela automáticamente al completar todas las semanas.', 'acf-woo-fasciculos' );
        echo '</p>';

        // Cerrar contenedor
        echo '</div>';
    }

    /**
     * Generar el HTML de una fila de la tabla del plan
     *
     * @param int   $index Índice de la fila (0-based).
     * @param array $row   Datos de la fila del plan.
     * @return void
     */
    private function output_plan_table_row( $index, $row ) {
        // Obtener el nombre del producto de forma optimizada
        $product_name = ACF_Woo_Fasciculos_Utils::get_product_name( $row['product_id'] );
        
        // Número de semana (1-based)
        $week_number = $index + 1;

        echo '<tr>';
        echo '<td>' . esc_html( $week_number ) . '</td>';
        echo '<td>' . esc_html( $product_name ) . '</td>';
        echo '<td>' . ACF_Woo_Fasciculos_Utils::format_price( $row['price'] ) . '</td>';
        echo '<td>' . esc_html( $row['note'] ) . '</td>';
        echo '</tr>';
    }

    /**
     * Obtener el plan de fascículos para un producto específico
     *
     * @param int $product_id ID del producto.
     * @return array Plan de fascículos.
     */
    public function get_product_plan( $product_id ) {
        return ACF_Woo_Fasciculos_Utils::get_plan_for_product( $product_id );
    }

    /**
     * Verificar si un producto tiene plan de fascículos
     *
     * @param int $product_id ID del producto.
     * @return bool True si tiene plan de fascículos.
     */
    public function has_plan( $product_id ) {
        $plan = $this->get_product_plan( $product_id );
        return ! empty( $plan );
    }

    /**
     * Obtener el número total de semanas en el plan
     *
     * @param int $product_id ID del producto.
     * @return int Número de semanas.
     */
    public function get_plan_weeks_count( $product_id ) {
        $plan = $this->get_product_plan( $product_id );
        return count( $plan );
    }

    /**
     * Obtener información de una semana específica del plan
     *
     * @param int $product_id ID del producto.
     * @param int $week_index Índice de la semana (0-based).
     * @return array|null Información de la semana o null si no existe.
     */
    public function get_week_info( $product_id, $week_index ) {
        $plan = $this->get_product_plan( $product_id );
        return ACF_Woo_Fasciculos_Utils::get_plan_row( $plan, $week_index );
    }

    /**
     * Obtener el producto de una semana específica
     *
     * @param int $product_id ID del producto principal.
     * @param int $week_index Índice de la semana (0-based).
     * @return WC_Product|null Producto de la semana o null.
     */
    public function get_week_product( $product_id, $week_index ) {
        $week_info = $this->get_week_info( $product_id, $week_index );
        
        if ( ! $week_info || ! isset( $week_info['product_id'] ) ) {
            return null;
        }

        return wc_get_product( $week_info['product_id'] );
    }

    /**
     * Obtener el precio de una semana específica
     *
     * @param int $product_id ID del producto principal.
     * @param int $week_index Índice de la semana (0-based).
     * @return float|null Precio de la semana o null.
     */
    public function get_week_price( $product_id, $week_index ) {
        $week_info = $this->get_week_info( $product_id, $week_index );
        
        if ( ! $week_info || ! isset( $week_info['price'] ) ) {
            return null;
        }

        return floatval( $week_info['price'] );
    }

    /**
     * Obtener la nota de una semana específica
     *
     * @param int $product_id ID del producto principal.
     * @param int $week_index Índice de la semana (0-based).
     * @return string Nota de la semana o string vacío.
     */
    public function get_week_note( $product_id, $week_index ) {
        $week_info = $this->get_week_info( $product_id, $week_index );
        
        if ( ! $week_info || ! isset( $week_info['note'] ) ) {
            return '';
        }

        return sanitize_text_field( $week_info['note'] );
    }

    /**
     * Verificar si una semana es la última del plan
     *
     * @param int $product_id ID del producto.
     * @param int $week_index Índice de la semana (0-based).
     * @return bool True si es la última semana.
     */
    public function is_last_week( $product_id, $week_index ) {
        $total_weeks = $this->get_plan_weeks_count( $product_id );
        return $week_index >= ( $total_weeks - 1 );
    }

    /**
     * Obtener la siguiente semana del plan
     *
     * @param int $product_id ID del producto.
     * @param int $current_week_index Índice de la semana actual (0-based).
     * @return array|null Información de la siguiente semana o null si no existe.
     */
    public function get_next_week( $product_id, $current_week_index ) {
        $next_index = intval( $current_week_index ) + 1;
        return $this->get_week_info( $product_id, $next_index );
    }

    /**
     * Verificar si existe una siguiente semana
     *
     * @param int $product_id ID del producto.
     * @param int $current_week_index Índice de la semana actual (0-based).
     * @return bool True si existe una siguiente semana.
     */
    public function has_next_week( $product_id, $current_week_index ) {
        return ! ! $this->get_next_week( $product_id, $current_week_index );
    }

    /**
     * Obtener resumen del plan para mostrar en emails o notificaciones
     *
     * @param int $product_id ID del producto.
     * @return string Resumen del plan.
     */
    public function get_plan_summary( $product_id ) {
        $total_weeks = $this->get_plan_weeks_count( $product_id );
        
        if ( 0 === $total_weeks ) {
            return __( 'Sin plan de fascículos', 'acf-woo-fasciculos' );
        }

        return sprintf(
            /* translators: %d: number of weeks */
            _n( 'Plan de %d semana', 'Plan de %d semanas', $total_weeks, 'acf-woo-fasciculos' ),
            $total_weeks
        );
    }

    /**
     * Obtener información detallada del plan para debugging
     *
     * @param int $product_id ID del producto.
     * @return array Información detallada del plan.
     */
    public function get_plan_debug_info( $product_id ) {
        $plan = $this->get_product_plan( $product_id );
        
        $debug_info = array(
            'product_id' => $product_id,
            'has_plan' => ! empty( $plan ),
            'total_weeks' => count( $plan ),
            'weeks' => array(),
        );

        foreach ( $plan as $index => $week ) {
            $debug_info['weeks'][] = array(
                'week_number' => $index + 1,
                'product_id' => $week['product_id'],
                'product_name' => ACF_Woo_Fasciculos_Utils::get_product_name( $week['product_id'] ),
                'price' => $week['price'],
                'note' => $week['note'],
            );
        }

        return $debug_info;
    }
}