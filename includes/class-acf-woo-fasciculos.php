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
     * Única instancia del plugin (patrón Singleton)
     *
     * @var ACF_Woo_Fasciculos
     */
    private static $instance = null;

    /**
     * Instancia del manejador de productos
     *
     * @var ACF_Woo_Fasciculos_Products
     */
    private $products_handler;

    /**
     * Instancia del manejador de suscripciones
     *
     * @var ACF_Woo_Fasciculos_Subscriptions
     */
    private $subscriptions_handler;

    /**
     * Instancia del manejador del carrito
     *
     * @var ACF_Woo_Fasciculos_Cart
     */
    private $cart_handler;

    /**
     * Instancia del manejador de pedidos
     *
     * @var ACF_Woo_Fasciculos_Orders
     */
    private $orders_handler;

    /**
     * Instancia del manejador de ACF
     *
     * @var ACF_Woo_Fasciculos_ACF
     */
    private $acf_handler;

    /**
     * Instancia del manejador de administración
     *
     * @var ACF_Woo_Fasciculos_Admin
     */
    private $admin_handler;

    /**
     * Instancia del manejador del checkout
     *
     * @var ACF_Woo_Fasciculos_Checkout
     */
    private $checkout_handler;

    /**
     * Constantes para metadatos
     */
    const META_PLAN_KEY = 'fasciculos_plan';
    const META_ACTIVE_INDEX = '_fasciculo_active_index';
    const META_PLAN_CACHE = '_fasciculos_plan_cache';
    const META_FIRST_UPDATE = '_fasciculo_first_update_done';
    const META_RENEWAL_DAYS = '_fasciculo_custom_renewal_days';

    /**
     * Obtener la única instancia del plugin
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
     * Constructor privado (patrón Singleton)
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_handlers();
        $this->setup_hooks();
    }

    /**
     * Cargar dependencias del plugin
     *
     * @return void
     */
    private function load_dependencies() {
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-products.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-subscriptions.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-cart.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-orders.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-acf.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/admin/class-acf-woo-fasciculos-admin.php';
        require_once ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/class-acf-woo-fasciculos-checkout.php';
    }

    /**
     * Inicializar manejadores
     *
     * @return void
     */
    private function init_handlers() {
        $this->products_handler = new ACF_Woo_Fasciculos_Products();
        $this->subscriptions_handler = new ACF_Woo_Fasciculos_Subscriptions();
        $this->cart_handler = new ACF_Woo_Fasciculos_Cart();
        $this->orders_handler = new ACF_Woo_Fasciculos_Orders();
        $this->acf_handler = new ACF_Woo_Fasciculos_ACF();
        // Pasar el manejador de suscripciones al admin
        $this->admin_handler = new ACF_Woo_Fasciculos_Admin( $this->subscriptions_handler );
        $this->checkout_handler = new ACF_Woo_Fasciculos_Checkout();
    }

    /**
     * Configurar hooks del plugin
     *
     * @return void
     */
    private function setup_hooks() {
        // Hooks de productos
        add_action( 'woocommerce_single_product_summary', array( $this->products_handler, 'render_plan_table' ), 25 );

        // Hooks del carrito
        add_filter( 'woocommerce_add_cart_item_data', array( $this->cart_handler, 'attach_plan_to_cart_item' ), 10, 3 );
        add_filter( 'woocommerce_get_item_data', array( $this->cart_handler, 'display_plan_in_cart' ), 10, 2 );
        add_action( 'woocommerce_before_calculate_totals', array( $this->cart_handler, 'override_cart_prices' ), 20 );

        // Hooks para permitir pago de pedidos fallidos con productos de fascículos
        add_filter( 'woocommerce_is_purchasable', array( $this->cart_handler, 'allow_fasciculo_products_purchasable' ), 99, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', array( $this->cart_handler, 'validate_fasciculo_add_to_cart' ), 99, 6 );

        // Hooks de pedidos
        add_action( 'woocommerce_checkout_create_order_line_item', array( $this->orders_handler, 'save_plan_to_order_item' ), 10, 4 );
        add_action( 'woocommerce_checkout_subscription_created', array( $this->orders_handler, 'copy_plan_to_subscription' ), 10, 4 );
        add_action( 'woocommerce_checkout_subscription_created', array( $this->orders_handler, 'apply_custom_renewal_days' ), 15, 3 );
        add_filter( 'wcs_renewal_order_created', array( $this->orders_handler, 'on_renewal_order_created' ), 10, 2 );
        add_action( 'woocommerce_order_status_changed', array( $this->orders_handler, 'on_order_status_progresses_renewal' ), 10, 4 );

        // Hook para reducir stock de productos fasciculo cuando se paga
        add_action( 'woocommerce_payment_complete', array( $this->orders_handler, 'reduce_fasciculo_stock_on_payment' ), 10, 1 );
        
        // Prevenir reducción automática de stock para pedidos con productos de fascículos
        add_filter( 'woocommerce_can_reduce_order_stock', array( $this->orders_handler, 'prevent_automatic_stock_reduction' ), 10, 2 );

        // Hooks de suscripciones
        add_action( 'woocommerce_subscription_activated', array( $this->subscriptions_handler, 'on_subscription_activated' ), 10, 1 );
        add_action( 'woocommerce_subscription_status_active', array( $this->subscriptions_handler, 'on_subscription_activated' ), 10, 1 );
        add_action( 'woocommerce_payment_complete', array( $this->subscriptions_handler, 'on_payment_complete_check_subscription' ), 10, 1 );
        add_action( 'woocommerce_scheduled_subscription_payment', array( $this->subscriptions_handler, 'check_if_plan_completed' ), 5, 1 );
        add_filter( 'wcs_renewal_order_items', array( $this->subscriptions_handler, 'modify_renewal_items_before_copy' ), 10, 3 );

        // Hooks de ACF - usar 'init' en lugar de 'acf/init' para asegurar que se ejecute
        add_action( 'init', array( $this->acf_handler, 'register_fields' ), 20 );

        // Hooks de administración
        add_action( 'woocommerce_admin_order_item_values', array( $this->admin_handler, 'show_active_week' ), 10, 3 );
        add_filter( 'woocommerce_hidden_order_itemmeta', array( $this->admin_handler, 'hide_internal_meta' ) );

        // Agregar este hook después de los hooks existentes de suscripciones
        add_action( 'woocommerce_order_status_changed', array( $this->subscriptions_handler, 'process_pending_cancellation' ), 15, 1 );

        // Deshabilitar renovación y reactivación desde el área de usuario para suscripciones de fascículos
        // Usamos prioridad alta (999) para asegurar que nuestros filtros se ejecuten después de otros plugins
        add_filter( 'wcs_view_subscription_actions', array( $this->subscriptions_handler, 'disable_user_renewal_reactivate_actions' ), 999, 2 );
        add_filter( 'wcs_can_user_reactivate_subscription', array( $this->subscriptions_handler, 'disable_user_reactivation' ), 999, 2 );
        add_filter( 'wcs_can_user_resubscribe_to_subscription', array( $this->subscriptions_handler, 'disable_user_reactivation' ), 999, 2 );
        
        // Filtros para bloquear renovación anticipada (multiple hooks para diferentes versiones de WCS)
        add_filter( 'wcs_can_user_renew_early_subscription', array( $this->subscriptions_handler, 'disable_early_renewal' ), 999, 2 );
        add_filter( 'wcs_subscription_can_be_renewed_early', array( $this->subscriptions_handler, 'disable_early_renewal' ), 999, 2 );

        // Hooks para creación automática de usuarios en checkout
        add_action( 'woocommerce_checkout_order_created', array( $this->checkout_handler, 'process_new_user_after_order' ), 5, 3 );
        add_filter( 'woocommerce_checkout_fields', array( $this->checkout_handler, 'maybe_require_account_fields' ), 10, 1 );
        add_action( 'woocommerce_before_checkout_billing_form', array( $this->checkout_handler, 'add_auto_account_notice' ), 10 );
        add_filter( 'woocommerce_checkout_registration_required', array( $this->checkout_handler, 'force_account_creation_for_fasciculos' ), 10, 1 );
    }

    /**
     * Obtener el manejador de productos
     *
     * @return ACF_Woo_Fasciculos_Products
     */
    public function get_products_handler() {
        return $this->products_handler;
    }

    /**
     * Obtener el manejador de suscripciones
     *
     * @return ACF_Woo_Fasciculos_Subscriptions
     */
    public function get_subscriptions_handler() {
        return $this->subscriptions_handler;
    }

    /**
     * Obtener el manejador del carrito
     *
     * @return ACF_Woo_Fasciculos_Cart
     */
    public function get_cart_handler() {
        return $this->cart_handler;
    }

    /**
     * Obtener el manejador de pedidos
     *
     * @return ACF_Woo_Fasciculos_Orders
     */
    public function get_orders_handler() {
        return $this->orders_handler;
    }

    /**
     * Obtener el manejador de ACF
     *
     * @return ACF_Woo_Fasciculos_ACF
     */
    public function get_acf_handler() {
        return $this->acf_handler;
    }

    /**
     * Obtener el manejador de administración
     *
     * @return ACF_Woo_Fasciculos_Admin
     */
    public function get_admin_handler() {
        return $this->admin_handler;
    }

    /**
     * Obtener el manejador del checkout
     *
     * @return ACF_Woo_Fasciculos_Checkout
     */
    public function get_checkout_handler() {
        return $this->checkout_handler;
    }
}