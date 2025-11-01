<?php
/**
 * Clase principal del plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase principal del plugin
 */
class ACF_Woo_Fasciculos {

    /**
     * Única instancia de la clase
     *
     * @var ACF_Woo_Fasciculos
     */
    private static $instance = null;

    /**
     * Constantes para metadatos
     */
    const META_PLAN_KEY = 'fasciculos_plan';
    const META_ACTIVE_INDEX = '_fasciculo_active_index';
    const META_PLAN_CACHE = '_fasciculos_plan_cache';
    const META_FIRST_UPDATE = '_fasciculo_first_update_done';
    const META_PLAN_COMPLETED = '_fasciculo_plan_completed';

    /**
     * Instancias de las clases handler
     *
     * @var array
     */
    private $handlers = array();

    /**
     * Obtener la única instancia de la clase
     *
     * @return ACF_Woo_Fasciculos
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_handlers();
        $this->setup_hooks();
        $this->setup_hpos_compatibility();
    }

    /**
     * Cargar archivos de dependencias
     *
     * @return void
     */
    private function load_dependencies() {
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-utils.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-products.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-cart.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-subscriptions.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-orders.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-acf.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/admin/class-acf-woo-fasciculos-admin.php';
    }

    /**
     * Inicializar clases handler
     *
     * @return void
     */
    private function init_handlers() {
        $this->handlers['utils'] = new ACF_Woo_Fasciculos_Utils();
        $this->handlers['products'] = new ACF_Woo_Fasciculos_Products();
        $this->handlers['cart'] = new ACF_Woo_Fasciculos_Cart();
        $this->handlers['subscriptions'] = new ACF_Woo_Fasciculos_Subscriptions();
        $this->handlers['orders'] = new ACF_Woo_Fasciculos_Orders();
        $this->handlers['acf'] = new ACF_Woo_Fasciculos_ACF();
        $this->handlers['admin'] = new ACF_Woo_Fasciculos_Admin();
    }

    /**
     * Configurar hooks de WordPress
     *
     * @return void
     */
    private function setup_hooks() {
        // Enqueue scripts y estilos
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Ocultar metadatos internos
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_internal_meta' ) );

        // Manejo de cancelación de suscripciones
        add_action( 'acf_woo_fasciculos_cancel_subscription', array( $this, 'cancel_subscription' ), 10, 1 );

        // HPOS compatibility hooks
        add_action( 'woocommerce_init', array( $this, 'setup_hpos_hooks' ) );
    }

    /**
     * Setup HPOS (High-Performance Order Storage) compatibility
     *
     * @return void
     */
    private function setup_hpos_compatibility() {
        // Ensure compatibility is declared early
        add_action( 'before_woocommerce_init', function() {
            if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            }
        } );
    }

    /**
     * Setup HPOS-specific hooks
     *
     * @return void
     */
    public function setup_hpos_hooks() {
        // HPOS compatibility for order meta
        if ( $this->is_hpos_enabled() ) {
            // Use modern order methods when HPOS is enabled
            add_filter( 'woocommerce_order_get_meta', array( $this, 'handle_hpos_order_meta' ), 10, 3 );
            add_filter( 'woocommerce_order_add_meta_data', array( $this, 'handle_hpos_order_add_meta' ), 10, 3 );
            add_filter( 'woocommerce_order_update_meta_data', array( $this, 'handle_hpos_order_update_meta' ), 10, 4 );
        }
    }

    /**
     * Check if HPOS is enabled
     *
     * @return bool
     */
    public function is_hpos_enabled() {
        if ( ! class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }

    /**
     * Handle HPOS order meta retrieval
     *
     * @param mixed $value Meta value.
     * @param WC_Order $order Order object.
     * @param string $meta_key Meta key.
     * @return mixed
     */
    public function handle_hpos_order_meta( $value, $order, $meta_key ) {
        // Ensure our custom meta works with HPOS
        if ( in_array( $meta_key, array( self::META_PLAN_KEY, self::META_ACTIVE_INDEX, self::META_PLAN_CACHE ) ) ) {
            return $order->get_meta( $meta_key, true );
        }
        return $value;
    }

    /**
     * Handle HPOS order meta addition
     *
     * @param string $meta_key Meta key.
     * @param mixed $meta_value Meta value.
     * @param WC_Order $order Order object.
     * @return void
     */
    public function handle_hpos_order_add_meta( $meta_key, $meta_value, $order ) {
        // Ensure our custom meta works with HPOS
        if ( in_array( $meta_key, array( self::META_PLAN_KEY, self::META_ACTIVE_INDEX, self::META_PLAN_CACHE ) ) ) {
            $order->add_meta_data( $meta_key, $meta_value, true );
        }
    }

    /**
     * Handle HPOS order meta update
     *
     * @param string $meta_key Meta key.
     * @param mixed $meta_value Meta value.
     * @param int $meta_id Meta ID.
     * @param WC_Order $order Order object.
     * @return void
     */
    public function handle_hpos_order_update_meta( $meta_key, $meta_value, $meta_id, $order ) {
        // Ensure our custom meta works with HPOS
        if ( in_array( $meta_key, array( self::META_PLAN_KEY, self::META_ACTIVE_INDEX, self::META_PLAN_CACHE ) ) ) {
            $order->update_meta_data( $meta_key, $meta_value );
        }
    }

    /**
     * Enqueue scripts y estilos del frontend
     *
     * @return void
     */
    public function enqueue_scripts() {
        if ( is_product() ) {
            wp_enqueue_style(
                'acf-woo-fasciculos',
                ACF_WOO_FASCICULOS_PLUGIN_URL . 'assets/css/acf-woo-fasciculos.css',
                array(),
                ACF_WOO_FASCICULOS_VERSION
            );
        }
    }

    /**
     * Enqueue scripts y estilos del admin
     *
     * @return void
     */
    public function enqueue_admin_scripts() {
        wp_enqueue_style(
            'acf-woo-fasciculos-admin',
            ACF_WOO_FASCICULOS_PLUGIN_URL . 'assets/css/acf-woo-fasciculos.css',
            array(),
            ACF_WOO_FASCICULOS_VERSION
        );
    }

    /**
     * Ocultar metadatos internos del pedido
     *
     * @param array $meta_keys Claves de metadatos a ocultar.
     * @return array
     */
    public function hide_internal_meta( $meta_keys ) {
        $meta_keys[] = '_fasciculos_plan';
        $meta_keys[] = '_fasciculo_active_index';
        $meta_keys[] = '_fasciculos_plan_cache';
        $meta_keys[] = '_fasciculo_first_update_done';
        $meta_keys[] = '_fasciculo_plan_completed';

        return $meta_keys;
    }

    /**
     * Cancelar suscripción
     *
     * @param int $subscription_id ID de la suscripción.
     * @return void
     */
    public function cancel_subscription( $subscription_id ) {
        $subscription = wcs_get_subscription( $subscription_id );

        if ( ! $subscription ) {
            return;
        }

        // Verificar que el plan esté completado
        if ( 'yes' !== $subscription->get_meta( '_fasciculo_plan_completed' ) ) {
            return;
        }

        // Cancelar la suscripción
        $subscription->update_status( 'cancelled', __( 'Plan de fascículos completado. Todas las semanas han sido enviadas.', 'acf-woo-fasciculos' ) );
    }

    /**
     * Obtener handler de utilidades
     *
     * @return ACF_Woo_Fasciculos_Utils
     */
    public function utils() {
        return $this->handlers['utils'];
    }

    /**
     * Obtener handler de productos
     *
     * @return ACF_Woo_Fasciculos_Products
     */
    public function products() {
        return $this->handlers['products'];
    }

    /**
     * Obtener handler del carrito
     *
     * @return ACF_Woo_Fasciculos_Cart
     */
    public function cart() {
        return $this->handlers['cart'];
    }

    /**
     * Obtener handler de suscripciones
     *
     * @return ACF_Woo_Fasciculos_Subscriptions
     */
    public function subscriptions() {
        return $this->handlers['subscriptions'];
    }

    /**
     * Obtener handler de pedidos
     *
     * @return ACF_Woo_Fasciculos_Orders
     */
    public function orders() {
        return $this->handlers['orders'];
    }

    /**
     * Obtener handler de ACF
     *
     * @return ACF_Woo_Fasciculos_ACF
     */
    public function acf() {
        return $this->handlers['acf'];
    }

    /**
     * Obtener handler de administración
     *
     * @return ACF_Woo_Fasciculos_Admin
     */
    public function admin() {
        return $this->handlers['admin'];
    }
}