<?php
/**
 * Manejador de ACF para el plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar la integración con Advanced Custom Fields
 */
class ACF_Woo_Fasciculos_ACF {

    /**
     * Constructor
     */
    public function __construct() {
        // Inicializar cualquier configuración necesaria
    }

    /**
     * Registrar los campos de ACF para productos de suscripción
     *
     * Este método registra el grupo de campos de ACF que permite configurar
     * el plan de fascículos en productos de suscripción.
     *
     * @return void
     */
    public function register_fields() {
        // Verificar que ACF esté activo
        if ( ! function_exists( 'acf_add_local_field_group' ) ) {
            error_log('ACF no está disponible para registrar campos');
            return;
        }

        error_log('Registrando campos ACF v2 para fascículos');

        // Eliminar el grupo antiguo si existe (forzar limpieza)
        if ( function_exists( 'acf_remove_local_field_group' ) ) {
            acf_remove_local_field_group( 'group_fasciculos_plan' );
        }

        // Registrar el grupo de campos NUEVO
        acf_add_local_field_group( array(
            'key' => 'group_fasciculos_plan_v2',
            'title' => __( 'Plan de Fascículos (Múltiples Productos)', 'acf-woo-fasciculos' ),
            'fields' => array(
                array(
                    'key' => 'field_fasciculos_plan_v2',
                    'label' => __( 'Fascículos (semanas)', 'acf-woo-fasciculos' ),
                    'name' => ACF_Woo_Fasciculos::META_PLAN_KEY,
                    'type' => 'repeater',
                    'instructions' => __( 'Define el plan semanal: producto a enviar y precio a cobrar en cada semana.', 'acf-woo-fasciculos' ),
                    'required' => 0,
                    'conditional_logic' => 0,
                    'wrapper' => array(
                        'width' => '',
                        'class' => '',
                        'id' => '',
                    ),
                    'collapsed' => '',
                    'min' => 0,
                    'max' => 0,
                    'layout' => 'row',
                    'button_label' => __( 'Añadir semana', 'acf-woo-fasciculos' ),
                    'sub_fields' => array(
                        array(
                            'key' => 'field_fasciculo_products_v2',
                            'label' => __( 'Productos de la semana', 'acf-woo-fasciculos' ),
                            'name' => 'fasciculo_products',
                            'type' => 'repeater',
                            'instructions' => __( 'Añade uno o más productos para esta semana.', 'acf-woo-fasciculos' ),
                            'required' => 1,
                            'conditional_logic' => 0,
                            'wrapper' => array(
                                'width' => '',
                                'class' => '',
                                'id' => '',
                            ),
                            'collapsed' => '',
                            'min' => 1,
                            'max' => 0,
                            'layout' => 'block',
                            'button_label' => __( 'Añadir producto', 'acf-woo-fasciculos' ),
                            'sub_fields' => array(
                                array(
                                    'key' => 'field_fasciculo_product_item_v2',
                                    'label' => __( 'Producto', 'acf-woo-fasciculos' ),
                                    'name' => 'product',
                                    'type' => 'post_object',
                                    'instructions' => '',
                                    'required' => 1,
                                    'conditional_logic' => 0,
                                    'wrapper' => array(
                                        'width' => '',
                                        'class' => '',
                                        'id' => '',
                                    ),
                                    'post_type' => array( 'product' ),
                                    'taxonomy' => '',
                                    'return_format' => 'object',
                                    'ui' => 1,
                                ),
                            ),
                        ),
                        array(
                            'key' => 'field_fasciculo_price_v2',
                            'label' => __( 'Precio total de la semana', 'acf-woo-fasciculos' ),
                            'name' => 'fasciculo_price',
                            'type' => 'number',
                            'instructions' => __( 'Precio total que se cobrará por todos los productos de esta semana.', 'acf-woo-fasciculos' ),
                            'required' => 1,
                            'conditional_logic' => 0,
                            'wrapper' => array(
                                'width' => '',
                                'class' => '',
                                'id' => '',
                            ),
                            'default_value' => '',
                            'placeholder' => '',
                            'prepend' => '',
                            'append' => '',
                            'min' => 0,
                            'max' => '',
                            'step' => '0.01',
                        ),
                        array(
                            'key' => 'field_fasciculo_note_v2',
                            'label' => __( 'Nota', 'acf-woo-fasciculos' ),
                            'name' => 'fasciculo_note',
                            'type' => 'text',
                            'instructions' => '',
                            'required' => 0,
                            'conditional_logic' => 0,
                            'wrapper' => array(
                                'width' => '',
                                'class' => '',
                                'id' => '',
                            ),
                            'default_value' => '',
                            'placeholder' => '',
                            'prepend' => '',
                            'append' => '',
                            'maxlength' => '',
                        ),
                    ),
                ),
            ),
            'location' => array(
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'product',
                    ),
                    array(
                        'param' => 'post_taxonomy',
                        'operator' => '==',
                        'value' => 'product_type:subscription',
                    ),
                ),
                array(
                    array(
                        'param' => 'post_type',
                        'operator' => '==',
                        'value' => 'product',
                    ),
                    array(
                        'param' => 'post_taxonomy',
                        'operator' => '==',
                        'value' => 'product_type:variable-subscription',
                    ),
                ),
            ),
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'seamless',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'hide_on_screen' => '',
            'active' => true,
            'description' => '',
            'show_in_rest' => 0,
        ) );
    }

    /**
     * Verificar si los campos de ACF están disponibles
     *
     * @return bool True si ACF está activo.
     */
    public function is_acf_available() {
        return function_exists( 'get_field' ) && function_exists( 'acf_add_local_field_group' );
    }

    /**
     * Obtener el valor de un campo ACF de forma segura
     *
     * @param string $field_name Nombre del campo.
     * @param mixed  $post_id ID del post (opcional).
     * @param bool   $format_value Si formatear el valor.
     * @return mixed Valor del campo o false si no existe.
     */
    public function get_field( $field_name, $post_id = false, $format_value = true ) {
        if ( ! $this->is_acf_available() ) {
            return false;
        }

        return get_field( $field_name, $post_id, $format_value );
    }

    /**
     * Actualizar el valor de un campo ACF de forma segura
     *
     * @param string $field_name Nombre del campo.
     * @param mixed  $value Valor a guardar.
     * @param mixed  $post_id ID del post (opcional).
     * @return bool True si se actualizó correctamente.
     */
    public function update_field( $field_name, $value, $post_id = false ) {
        if ( ! $this->is_acf_available() ) {
            return false;
        }

        return update_field( $field_name, $value, $post_id );
    }

    /**
     * Verificar si un campo ACF existe
     *
     * @param string $field_name Nombre del campo.
     * @param mixed  $post_id ID del post (opcional).
     * @return bool True si el campo existe.
     */
    public function field_exists( $field_name, $post_id = false ) {
        if ( ! $this->is_acf_available() ) {
            return false;
        }

        return ! ! get_field_object( $field_name, $post_id );
    }

    /**
     * Obtener información sobre un campo ACF
     *
     * @param string $field_name Nombre del campo.
     * @param mixed  $post_id ID del post (opcional).
     * @return array|false Información del campo o false si no existe.
     */
    public function get_field_info( $field_name, $post_id = false ) {
        if ( ! $this->is_acf_available() ) {
            return false;
        }

        return get_field_object( $field_name, $post_id );
    }

    /**
     * Obtener todos los campos del grupo de fascículos
     *
     * @return array Campos del grupo.
     */
    public function get_fasciculos_fields() {
        if ( ! $this->is_acf_available() ) {
            return array();
        }

        $field_group = acf_get_field_group( 'group_fasciculos_plan_v2' );
        if ( ! $field_group ) {
            return array();
        }

        return acf_get_fields( $field_group );
    }

    /**
     * Verificar si un producto tiene el plan de fascículos configurado
     *
     * @param int $product_id ID del producto.
     * @return bool True si tiene plan configurado.
     */
    public function has_fasciculos_plan( $product_id ) {
        if ( ! $this->is_acf_available() ) {
            return false;
        }

        $plan = $this->get_field( ACF_Woo_Fasciculos::META_PLAN_KEY, $product_id );
        return ! empty( $plan ) && is_array( $plan );
    }

    /**
     * Obtener el plan de fascículos desde ACF
     *
     * @param int $product_id ID del producto.
     * @return array Plan de fascículos.
     */
    public function get_fasciculos_plan( $product_id ) {
        if ( ! $this->is_acf_available() ) {
            return array();
        }

        return $this->get_field( ACF_Woo_Fasciculos::META_PLAN_KEY, $product_id );
    }

    /**
     * Actualizar el plan de fascículos en ACF
     *
     * @param int   $product_id ID del producto.
     * @param array $plan Plan de fascículos.
     * @return bool True si se actualizó correctamente.
     */
    public function update_fasciculos_plan( $product_id, $plan ) {
        if ( ! $this->is_acf_available() ) {
            return false;
        }

        return $this->update_field( ACF_Woo_Fasciculos::META_PLAN_KEY, $plan, $product_id );
    }

    /**
     * Obtener estadísticas de uso de ACF
     *
     * @return array Estadísticas.
     */
    public function get_acf_stats() {
        $stats = array(
            'is_available' => $this->is_acf_available(),
            'version' => defined( 'ACF_VERSION' ) ? ACF_VERSION : 'unknown',
            'field_groups' => 0,
            'fields' => 0,
        );

        if ( $this->is_acf_available() ) {
            $field_groups = acf_get_field_groups();
            $stats['field_groups'] = count( $field_groups );
            
            foreach ( $field_groups as $group ) {
                $fields = acf_get_fields( $group );
                $stats['fields'] += count( $fields );
            }
        }

        return $stats;
    }

    /**
     * Obtener información de debugging sobre ACF
     *
     * @return array Información de debugging.
     */
    public function get_debug_info() {
        return array(
            'is_available' => $this->is_acf_available(),
            'version' => defined( 'ACF_VERSION' ) ? ACF_VERSION : 'unknown',
            'functions_available' => array(
                'get_field' => function_exists( 'get_field' ),
                'update_field' => function_exists( 'update_field' ),
                'acf_add_local_field_group' => function_exists( 'acf_add_local_field_group' ),
                'acf_get_field_groups' => function_exists( 'acf_get_field_groups' ),
            ),
            'field_group_registered' => ! ! acf_get_field_group( 'group_fasciculos_plan_v2' ),
        );
    }
}