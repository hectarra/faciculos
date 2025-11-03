<?php
/**
 * Plugin Name: ACF + Woo Subscriptions Fascículos
 * Plugin URI: https://tuequipo.com/plugins/acf-woo-fasciculos
 * Description: Sistema de suscripción por fascículos para WooCommerce con planes semanales de productos y precios variables
 * Version: 3.0.0
 * Author: Tu Equipo
 * Author URI: https://tuequipo.com
 * Text Domain: acf-woo-fasciculos
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.2
 * WC requires at least: 7.0
 * WC tested up to: 9.0
 *
 * @package ACF_Woo_Fasciculos
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'ACF_WOO_FASCICULOS_VERSION' ) ) {
	define( 'ACF_WOO_FASCICULOS_VERSION', '3.0.0' );
}

if ( ! defined( 'ACF_WOO_FASCICULOS_PLUGIN_DIR' ) ) {
	define( 'ACF_WOO_FASCICULOS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ACF_WOO_FASCICULOS_PLUGIN_URL' ) ) {
	define( 'ACF_WOO_FASCICULOS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'ACF_WOO_FASCICULOS_PLUGIN_BASENAME' ) ) {
	define( 'ACF_WOO_FASCICULOS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
}

if ( ! defined( 'ACF_WOO_FASCICULOS_PLUGIN_FILE' ) ) {
	define( 'ACF_WOO_FASCICULOS_PLUGIN_FILE', __FILE__ );
}

/**
 * Check plugin requirements.
 *
 * @return bool
 */
function acf_woo_fasciculos_check_requirements() {
	$errors = array();

	// PHP version.
	if ( version_compare( PHP_VERSION, '7.2', '<' ) ) {
		$errors[] = sprintf(
			/* translators: %s: required PHP version */
			__( 'ACF + Woo Subscriptions Fascículos requiere PHP versión %s o superior. Tu versión actual es %s.', 'acf-woo-fasciculos' ),
			'7.2',
			PHP_VERSION
		);
	}

	// WooCommerce.
	if ( ! class_exists( 'WooCommerce' ) ) {
		$errors[] = __( 'ACF + Woo Subscriptions Fascículos requiere WooCommerce.', 'acf-woo-fasciculos' );
	} elseif ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.0', '<' ) ) {
		$errors[] = sprintf(
			/* translators: %s: required WooCommerce version */
			__( 'ACF + Woo Subscriptions Fascículos requiere WooCommerce versión %s o superior.', 'acf-woo-fasciculos' ),
			'3.0'
		);
	}

	// WooCommerce Subscriptions.
	if ( ! class_exists( 'WC_Subscriptions' ) ) {
		$errors[] = __( 'ACF + Woo Subscriptions Fascículos requiere WooCommerce Subscriptions.', 'acf-woo-fasciculos' );
	}

	// ACF.
	if ( ! function_exists( 'get_field' ) ) {
		$errors[] = __( 'ACF + Woo Subscriptions Fascículos requiere Advanced Custom Fields (ACF).', 'acf-woo-fasciculos' );
	}

	// If there are errors, show admin notice.
	if ( ! empty( $errors ) ) {
		add_action(
			'admin_notices',
			function() use ( $errors ) {
				echo '<div class="notice notice-error"><p>';
				echo esc_html( implode( "\n", $errors ) );
				echo '</p></div>';
			}
		);
		return false;
	}

	return true;
}

/**
 * Load plugin files and register autoloader.
 *
 * @return void
 */
function acf_woo_fasciculos_load_files() {
	// Simple PSR-0/4 like autoloader for plugin classes prefixed with ACF_Woo_Fasciculos_
	spl_autoload_register(
		function( $class_name ) {
			if ( 0 !== strpos( $class_name, 'ACF_Woo_Fasciculos' ) ) {
				return;
			}

			$class_file = 'class-' . str_replace( array( '_', '\\' ), array( '-', '/' ), strtolower( $class_name ) ) . '.php';

			$directories = array(
				ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/core/',
				ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/admin/',
				ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/frontend/',
				ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/',
			);

			foreach ( $directories as $directory ) {
				$path = $directory . $class_file;
				if ( file_exists( $path ) ) {
					require_once $path;
					return;
				}
			}
		}
	);

	// Load main class if present.
	$main_file = ACF_WOO_FASCICULOS_PLUGIN_DIR . 'includes/class-acf-woo-fasciculos.php';
	if ( file_exists( $main_file ) ) {
		require_once $main_file;
	}
}

/**
 * Initialize the plugin.
 *
 * @return void
 */
function acf_woo_fasciculos_init() {
	// Check requirements before doing anything.
	if ( ! acf_woo_fasciculos_check_requirements() ) {
		return;
	}

	// Load files and classes.
	acf_woo_fasciculos_load_files();

	// Initialize main plugin class.
	if ( class_exists( 'ACF_Woo_Fasciculos' ) && method_exists( 'ACF_Woo_Fasciculos', 'get_instance' ) ) {
		ACF_Woo_Fasciculos::get_instance();
	}
}
add_action( 'plugins_loaded', 'acf_woo_fasciculos_init' );

/**
 * Activation hook.
 *
 * @return void
 */
function acf_woo_fasciculos_activate() {
	// Ensure requirements are met on activation.
	if ( ! acf_woo_fasciculos_check_requirements() ) {
		wp_die(
			__( 'El plugin no se puede activar porque no cumple con los requisitos necesarios.', 'acf-woo-fasciculos' ),
			__( 'Error de Activación', 'acf-woo-fasciculos' ),
			array( 'response' => 200, 'back_link' => true )
		);
	}

	// Place activation logic here (create DB tables, set default options, schedule cron, etc.)

	// Flush rewrite rules if necessary.
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'acf_woo_fasciculos_activate' );

/**
 * Deactivation hook.
 *
 * @return void
 */
function acf_woo_fasciculos_deactivate() {
	// Place deactivation logic here (clear scheduled jobs, temporary options, etc.)

	// Flush rewrite rules if necessary.
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'acf_woo_fasciculos_deactivate' );

/**
 * Load plugin textdomain for translations.
 *
 * @return void
 */
function acf_woo_fasciculos_load_textdomain() {
	load_plugin_textdomain(
		'acf-woo-fasciculos',
		false,
		dirname( ACF_WOO_FASCICULOS_PLUGIN_BASENAME ) . '/languages'
	);
}
add_action( 'init', 'acf_woo_fasciculos_load_textdomain' );

/**
 * Declarar compatibilidad con HPOS
 *
 * @return void
 */
function acf_woo_fasciculos_declare_hpos_compatibility() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
}
add_action( 'before_woocommerce_init', 'acf_woo_fasciculos_declare_hpos_compatibility' );