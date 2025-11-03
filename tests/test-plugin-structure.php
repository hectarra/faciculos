<?php
/**
 * Tests para verificar la estructura del plugin ACF + Woo Subscriptions Fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase de pruebas para el plugin
 */
class ACF_Woo_Fasciculos_Tests {

    /**
     * Verificar que todos los archivos necesarios existen
     *
     * @return array Resultados de las pruebas
     */
    public static function check_file_structure() {
        $results = array();
        $base_dir = ACF_WOO_FASCICULOS_PLUGIN_DIR;

        // Archivos principales
        $required_files = array(
            'acf-woo-fasciculos.php',
            'README.md',
            'includes/class-acf-woo-fasciculos.php',
            'includes/core/class-acf-woo-fasciculos-utils.php',
            'includes/core/class-acf-woo-fasciculos-products.php',
            'includes/core/class-acf-woo-fasciculos-cart.php',
            'includes/core/class-acf-woo-fasciculos-subscriptions.php',
            'includes/core/class-acf-woo-fasciculos-orders.php',
            'includes/core/class-acf-woo-fasciculos-acf.php',
            'includes/admin/class-acf-woo-fasciculos-admin.php',
            'assets/css/acf-woo-fasciculos.css',
            'languages/acf-woo-fasciculos-es_ES.po',
        );

        foreach ( $required_files as $file ) {
            $file_path = $base_dir . $file;
            $exists = file_exists( $file_path );
            
            $results[] = array(
                'test' => 'Archivo: ' . $file,
                'result' => $exists ? 'PASADO' : 'FALLADO',
                'message' => $exists ? 'Archivo encontrado' : 'Archivo no encontrado: ' . $file_path,
            );
        }

        return $results;
    }

    /**
     * Verificar que las clases principales existen y se pueden instanciar
     *
     * @return array Resultados de las pruebas
     */
    public static function check_classes() {
        $results = array();

        // Verificar que la clase principal existe
        $results[] = array(
            'test' => 'Clase: ACF_Woo_Fasciculos',
            'result' => class_exists( 'ACF_Woo_Fasciculos' ) ? 'PASADO' : 'FALLADO',
            'message' => class_exists( 'ACF_Woo_Fasciculos' ) ? 'Clase encontrada' : 'Clase no encontrada',
        );

        // Verificar que se puede obtener la instancia
        try {
            $instance = ACF_Woo_Fasciculos::get_instance();
            $results[] = array(
                'test' => 'Instancia: ACF_Woo_Fasciculos',
                'result' => 'PASADO',
                'message' => 'Instancia creada exitosamente',
            );
        } catch ( Exception $e ) {
            $results[] = array(
                'test' => 'Instancia: ACF_Woo_Fasciculos',
                'result' => 'FALLADO',
                'message' => 'Error al crear instancia: ' . $e->getMessage(),
            );
        }

        // Verificar clases auxiliares
        $classes = array(
            'ACF_Woo_Fasciculos_Utils',
            'ACF_Woo_Fasciculos_Products',
            'ACF_Woo_Fasciculos_Cart',
            'ACF_Woo_Fasciculos_Subscriptions',
            'ACF_Woo_Fasciculos_Orders',
            'ACF_Woo_Fasciculos_ACF',
            'ACF_Woo_Fasciculos_Admin',
        );

        foreach ( $classes as $class ) {
            $exists = class_exists( $class );
            $results[] = array(
                'test' => 'Clase: ' . $class,
                'result' => $exists ? 'PASADO' : 'FALLADO',
                'message' => $exists ? 'Clase encontrada' : 'Clase no encontrada',
            );
        }

        return $results;
    }

    /**
     * Verificar constantes del plugin
     *
     * @return array Resultados de las pruebas
     */
    public static function check_constants() {
        $results = array();
        $constants = array(
            'ACF_WOO_FASCICULOS_VERSION',
            'ACF_WOO_FASCICULOS_PLUGIN_DIR',
            'ACF_WOO_FASCICULOS_PLUGIN_URL',
            'ACF_WOO_FASCICULOS_PLUGIN_BASENAME',
            'ACF_Woo_Fasciculos::META_PLAN_KEY',
            'ACF_Woo_Fasciculos::META_ACTIVE_INDEX',
            'ACF_Woo_Fasciculos::META_PLAN_CACHE',
            'ACF_Woo_Fasciculos::META_FIRST_UPDATE',
        );

        foreach ( $constants as $constant ) {
            $defined = defined( $constant );
            $results[] = array(
                'test' => 'Constante: ' . $constant,
                'result' => $defined ? 'PASADO' : 'FALLADO',
                'message' => $defined ? 'Constante definida' : 'Constante no definida',
            );
        }

        return $results;
    }

    /**
     * Ejecutar todas las pruebas
     *
     * @return array Todos los resultados
     */
    public static function run_all_tests() {
        $all_results = array();
        
        $all_results['structure'] = self::check_file_structure();
        $all_results['classes'] = self::check_classes();
        $all_results['constants'] = self::check_constants();

        return $all_results;
    }

    /**
     * Mostrar resultados de las pruebas
     *
     * @param array $results Resultados a mostrar
     * @return void
     */
    public static function display_results( $results ) {
        echo "<div style='font-family: monospace; margin: 20px;'>\n";
        echo "<h2>Pruebas del Plugin ACF + Woo Subscriptions Fascículos</h2>\n";
        
        foreach ( $results as $category => $category_results ) {
            echo "<h3>" . esc_html( ucfirst( $category ) ) . "</h3>\n";
            echo "<table style='border-collapse: collapse; width: 100%;'>\n";
            echo "<tr style='background: #f0f0f0;'>\n";
            echo "<th style='border: 1px solid #ccc; padding: 8px; text-align: left;'>Prueba</th>\n";
            echo "<th style='border: 1px solid #ccc; padding: 8px; text-align: left;'>Resultado</th>\n";
            echo "<th style='border: 1px solid #ccc; padding: 8px; text-align: left;'>Mensaje</th>\n";
            echo "</tr>\n";
            
            foreach ( $category_results as $result ) {
                $color = $result['result'] === 'PASADO' ? '#4CAF50' : '#f44336';
                echo "<tr>\n";
                echo "<td style='border: 1px solid #ccc; padding: 8px;'>" . esc_html( $result['test'] ) . "</td>\n";
                echo "<td style='border: 1px solid #ccc; padding: 8px; color: " . esc_attr( $color ) . "; font-weight: bold;'>" . esc_html( $result['result'] ) . "</td>\n";
                echo "<td style='border: 1px solid #ccc; padding: 8px;'>" . esc_html( $result['message'] ) . "</td>\n";
                echo "</tr>\n";
            }
            
            echo "</table>\n";
        }
        
        echo "</div>\n";
    }
}

// Ejecutar pruebas si se accede directamente
if ( defined( 'ABSPATH' ) && isset( $_GET['test_acf_fasciculos'] ) ) {
    $results = ACF_Woo_Fasciculos_Tests::run_all_tests();
    ACF_Woo_Fasciculos_Tests::display_results( $results );
    exit;
}