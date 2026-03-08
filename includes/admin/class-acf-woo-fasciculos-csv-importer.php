<?php
/**
 * Importador CSV para planes de fascículos
 *
 * @package ACF_Woo_Fasciculos
 * @since 3.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar la importación de planes de fascículos desde CSV
 */
class ACF_Woo_Fasciculos_CSV_Importer {

    /**
     * Clave del nonce para el formulario de importación
     */
    const NONCE_ACTION = 'acf_woo_fasciculos_csv_import';

    /**
     * Registrar hooks de WordPress
     *
     * @return void
     */
    public function register_hooks() {
        add_action( 'admin_menu', array( $this, 'register_admin_menu' ) );
        add_action( 'admin_post_acf_woo_fasciculos_import_csv', array( $this, 'handle_csv_upload' ) );
        add_action( 'admin_post_acf_woo_fasciculos_download_template', array( $this, 'download_csv_template' ) );
    }

    /**
     * Registrar la página de menú en WooCommerce
     *
     * @return void
     */
    public function register_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Importar Plan CSV — Fascículos', 'acf-woo-fasciculos' ),
            __( 'Importar Plan CSV', 'acf-woo-fasciculos' ),
            'manage_woocommerce',
            'acf-woo-fasciculos-csv-import',
            array( $this, 'render_import_page' )
        );
    }

    /**
     * Renderizar la página de importación CSV
     *
     * @return void
     */
    public function render_import_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'No tienes permisos para acceder a esta página.', 'acf-woo-fasciculos' ) );
        }

        $result = get_transient( 'acf_woo_fasciculos_import_result_' . get_current_user_id() );
        if ( $result ) {
            delete_transient( 'acf_woo_fasciculos_import_result_' . get_current_user_id() );
        }

        $subscription_products = $this->get_subscription_products();
        $template_url = admin_url( 'admin-post.php?action=acf_woo_fasciculos_download_template' );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( '📦 Importar Plan de Fascículos desde CSV', 'acf-woo-fasciculos' ); ?></h1>

            <?php $this->render_result_notices( $result ); ?>

            <div style="display:grid; grid-template-columns: 2fr 1fr; gap: 24px; margin-top: 20px;">

                <!-- Formulario principal -->
                <div>
                    <div class="card" style="padding: 24px;">
                        <h2 style="margin-top:0;"><?php esc_html_e( 'Subir CSV', 'acf-woo-fasciculos' ); ?></h2>

                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="acf_woo_fasciculos_import_csv">
                            <?php wp_nonce_field( self::NONCE_ACTION, '_wpnonce_csv_import' ); ?>

                            <table class="form-table" role="presentation">
                                <tr>
                                    <th scope="row">
                                        <label for="subscription_product_id">
                                            <?php esc_html_e( 'Producto de suscripción', 'acf-woo-fasciculos' ); ?>
                                            <span style="color:red;">*</span>
                                        </label>
                                    </th>
                                    <td>
                                        <?php if ( empty( $subscription_products ) ) : ?>
                                            <p class="description" style="color:#c0392b;">
                                                <?php esc_html_e( 'No se encontraron productos de suscripción publicados.', 'acf-woo-fasciculos' ); ?>
                                            </p>
                                        <?php else : ?>
                                            <select name="subscription_product_id" id="subscription_product_id" class="regular-text" required>
                                                <option value=""><?php esc_html_e( '— Selecciona un producto —', 'acf-woo-fasciculos' ); ?></option>
                                                <?php foreach ( $subscription_products as $product ) : ?>
                                                    <option value="<?php echo esc_attr( $product->get_id() ); ?>">
                                                        <?php echo esc_html( $product->get_name() ); ?> (ID: <?php echo esc_html( $product->get_id() ); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <p class="description">
                                                <?php esc_html_e( 'El plan CSV se guardará en este producto. Si ya tenía un plan, será reemplazado.', 'acf-woo-fasciculos' ); ?>
                                            </p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <label for="csv_file">
                                            <?php esc_html_e( 'Archivo CSV', 'acf-woo-fasciculos' ); ?>
                                            <span style="color:red;">*</span>
                                        </label>
                                    </th>
                                    <td>
                                        <input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required>
                                        <p class="description">
                                            <?php esc_html_e( 'Sube un archivo .csv con las columnas: order, product_id, price, note.', 'acf-woo-fasciculos' ); ?>
                                            <a href="<?php echo esc_url( $template_url ); ?>" target="_blank">
                                                <?php esc_html_e( '⬇️ Descargar plantilla de ejemplo', 'acf-woo-fasciculos' ); ?>
                                            </a>
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <th scope="row">
                                        <?php esc_html_e( 'Opciones', 'acf-woo-fasciculos' ); ?>
                                    </th>
                                    <td>
                                        <label>
                                            <input type="checkbox" name="skip_invalid_products" value="1" checked>
                                            <?php esc_html_e( 'Omitir productos no encontrados (en lugar de abortar la importación)', 'acf-woo-fasciculos' ); ?>
                                        </label>
                                    </td>
                                </tr>
                            </table>

                            <?php submit_button( __( '📥 Importar Plan', 'acf-woo-fasciculos' ), 'primary', 'submit', true, empty( $subscription_products ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
                        </form>
                    </div>
                </div>

                <!-- Panel de ayuda -->
                <div>
                    <div class="card" style="padding: 24px;">
                        <h2 style="margin-top:0;">📋 <?php esc_html_e( 'Formato del CSV', 'acf-woo-fasciculos' ); ?></h2>
                        <p><?php esc_html_e( 'El CSV debe tener cabecera y las siguientes columnas:', 'acf-woo-fasciculos' ); ?></p>

                        <table class="widefat striped" style="font-size:13px;">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Columna', 'acf-woo-fasciculos' ); ?></th>
                                    <th><?php esc_html_e( 'Descripción', 'acf-woo-fasciculos' ); ?></th>
                                    <th><?php esc_html_e( 'Ejemplo', 'acf-woo-fasciculos' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><code>order</code></td>
                                    <td><?php esc_html_e( 'Número de semana (ordena las filas)', 'acf-woo-fasciculos' ); ?></td>
                                    <td><code>1</code></td>
                                </tr>
                                <tr>
                                    <td><code>product_id</code></td>
                                    <td><?php esc_html_e( 'ID del producto individual', 'acf-woo-fasciculos' ); ?></td>
                                    <td><code>123</code></td>
                                </tr>
                                <tr>
                                    <td><code>price</code></td>
                                    <td><?php esc_html_e( 'Precio de ese producto (se sumará al total de la semana)', 'acf-woo-fasciculos' ); ?></td>
                                    <td><code>12.99</code></td>
                                </tr>
                                <tr>
                                    <td><code>note</code> <em><?php esc_html_e( '(opcional)', 'acf-woo-fasciculos' ); ?></em></td>
                                    <td><?php esc_html_e( 'Nota descriptiva de la semana', 'acf-woo-fasciculos' ); ?></td>
                                    <td><code><?php esc_html_e( 'Especial Navidad', 'acf-woo-fasciculos' ); ?></code></td>
                                </tr>
                            </tbody>
                        </table>

                        <p style="margin-top:16px;"><strong><?php esc_html_e( 'Ejemplo:', 'acf-woo-fasciculos' ); ?></strong></p>
                        <pre style="background:#f0f0f1;padding:12px;border-radius:4px;font-size:12px;overflow:auto;">order,product_id,price,note
1,123,10,jjj
1,124,15,hhh
2,456,14.50,Dos productos
2,789,5.00,
3,321,9.99,Edición especial
4,111,12.00,</pre>

                        <p class="description">
                            ⚠️ <?php esc_html_e( 'Al importar se reemplazará el plan completo del producto seleccionado.', 'acf-woo-fasciculos' ); ?>
                        </p>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }

    /**
     * Renderizar notificaciones de resultado
     *
     * @param array|false $result Resultado de la importación anterior.
     * @return void
     */
    private function render_result_notices( $result ) {
        if ( empty( $result ) ) {
            return;
        }

        $type    = isset( $result['type'] ) ? $result['type'] : 'info';
        $message = isset( $result['message'] ) ? $result['message'] : '';
        $details = isset( $result['details'] ) ? $result['details'] : array();

        $class_map = array(
            'success' => 'notice-success',
            'error'   => 'notice-error',
            'warning' => 'notice-warning',
            'info'    => 'notice-info',
        );
        $css_class = isset( $class_map[ $type ] ) ? $class_map[ $type ] : 'notice-info';

        echo '<div class="notice ' . esc_attr( $css_class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p>';

        if ( ! empty( $details ) ) {
            echo '<ul style="margin:.5em 0 .5em 1.5em;list-style:disc;">';
            foreach ( $details as $detail ) {
                echo '<li>' . esc_html( $detail ) . '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    /**
     * Manejar la subida y procesamiento del CSV
     *
     * @return void
     */
    public function handle_csv_upload() {
        // Verificar permisos
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'acf-woo-fasciculos' ) );
        }

        // Verificar nonce
        if ( ! isset( $_POST['_wpnonce_csv_import'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_csv_import'] ) ), self::NONCE_ACTION ) ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'Token de seguridad inválido. Por favor, intenta de nuevo.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        // Verificar producto
        $product_id = isset( $_POST['subscription_product_id'] ) ? intval( $_POST['subscription_product_id'] ) : 0;
        if ( ! $product_id ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'Debes seleccionar un producto de suscripción.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        $product = wc_get_product( $product_id );
        if ( ! $product ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'Producto no encontrado.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        // Verificar archivo CSV
        if ( ! isset( $_FILES['csv_file'] ) || ! isset( $_FILES['csv_file']['tmp_name'] ) || empty( $_FILES['csv_file']['tmp_name'] ) ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'No se recibió ningún archivo CSV.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $file      = $_FILES['csv_file'];
        $file_ext  = strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) );
        $mime_type = isset( $file['type'] ) ? $file['type'] : '';

        if ( 'csv' !== $file_ext && ! in_array( $mime_type, array( 'text/csv', 'application/csv', 'text/plain' ), true ) ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'El archivo debe ser de tipo CSV.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        $skip_invalid = isset( $_POST['skip_invalid_products'] ) && '1' === $_POST['skip_invalid_products'];

        // Parsear CSV
        $parse_result = $this->parse_csv( $file['tmp_name'], $skip_invalid );

        if ( is_wp_error( $parse_result ) ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => $parse_result->get_error_message(),
            ) );
            return;
        }

        $plan     = $parse_result['plan'];
        $warnings = $parse_result['warnings'];

        if ( empty( $plan ) ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'El CSV no contiene ninguna semana válida. Verifica el formato y los IDs de productos.', 'acf-woo-fasciculos' ),
                'details' => $warnings,
            ) );
            return;
        }

        // Verificar que ACF está disponible
        if ( ! function_exists( 'update_field' ) ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'ACF no está disponible. No se puede guardar el plan.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        // Guardar el plan en ACF
        $saved = update_field( ACF_Woo_Fasciculos::META_PLAN_KEY, $plan, $product_id );

        if ( false === $saved ) {
            $this->redirect_with_result( array(
                'type'    => 'error',
                'message' => __( 'Error al guardar el plan en ACF. Verifica que el campo "fasciculos_plan" existe para este producto.', 'acf-woo-fasciculos' ),
            ) );
            return;
        }

        // Éxito
        $total_weeks = count( $plan );
        /* translators: 1: product name, 2: product ID, 3: number of weeks */
        $success_message = sprintf(
            __( '✅ Plan importado correctamente para "%1$s" (ID: %2$d): %3$d semanas configuradas.', 'acf-woo-fasciculos' ),
            $product->get_name(),
            $product_id,
            $total_weeks
        );

        $details = array();
        foreach ( $warnings as $warning ) {
            $details[] = '⚠️ ' . $warning;
        }

        $this->redirect_with_result( array(
            'type'    => empty( $warnings ) ? 'success' : 'warning',
            'message' => $success_message,
            'details' => $details,
        ) );
    }

    /**
     * Parsear el archivo CSV y construir el array del plan para ACF
     *
     * El CSV puede tener la cabecera en cualquier orden. Las columnas requeridas son:
     * - order: número de semana (entero)
     * - product_id: ID del producto
     * - price: precio en formato numérico del producto (se suma al total de la semana)
     * - note: (opcional) texto descriptivo (se concatenarán las notas de la misma semana)
     *
     * El array resultante tiene el formato que ACF espera para el repeater:
     * [
     *   [
     *     'fasciculo_products' => [
     *       ['product' => <product_id>],
     *       ...
     *     ],
     *     'fasciculo_price' => <float>,
     *     'fasciculo_note'  => <string>,
     *   ],
     *   ...
     * ]
     *
     * @param string $file_path Ruta al archivo CSV temporal.
     * @param bool   $skip_invalid Si omitir productos inválidos en lugar de abortar.
     * @return array|WP_Error Array con 'plan' y 'warnings', o WP_Error si el CSV es inválido.
     */
    private function parse_csv( $file_path, $skip_invalid = true ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        $handle = fopen( $file_path, 'r' );
        if ( ! $handle ) {
            return new WP_Error( 'csv_open_failed', __( 'No se pudo abrir el archivo CSV.', 'acf-woo-fasciculos' ) );
        }

        // Leer la primera línea como cabecera
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgetcsv
        $header_raw = fgetcsv( $handle, 0, ',' );

        if ( ! $header_raw || ! is_array( $header_raw ) ) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
            fclose( $handle );
            return new WP_Error( 'csv_empty_header', __( 'El CSV está vacío o no tiene cabecera.', 'acf-woo-fasciculos' ) );
        }

        // Normalizar cabeceras (lowercase, sin espacios)
        $headers = array_map( function( $h ) {
            return strtolower( trim( $h ) );
        }, $header_raw );

        // Verificar columnas requeridas
        $required_columns = array( 'order', 'product_id', 'price' );
        foreach ( $required_columns as $col ) {
            if ( ! in_array( $col, $headers, true ) ) {
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
                fclose( $handle );
                return new WP_Error(
                    'csv_missing_column',
                    /* translators: %s: column name */
                    sprintf( __( 'Columna requerida no encontrada en el CSV: "%s". Asegúrate de que existe la cabecera.', 'acf-woo-fasciculos' ), $col )
                );
            }
        }

        $col_index = array_flip( $headers );

        $rows     = array();
        $warnings = array();
        $row_num  = 1; // Empieza en 1 porque la fila 0 es la cabecera

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fgetcsv
        while ( ( $data = fgetcsv( $handle, 0, ',' ) ) !== false ) {
            $row_num++;

            if ( count( $data ) < count( $required_columns ) ) {
                $warnings[] = sprintf(
                    /* translators: %d: row number */
                    __( 'Fila %d ignorada: número incorrecto de columnas.', 'acf-woo-fasciculos' ),
                    $row_num
                );
                continue;
            }

            // Extraer valores
            $order_val  = isset( $col_index['order'] ) && isset( $data[ $col_index['order'] ] )
                ? trim( $data[ $col_index['order'] ] )
                : '';
            $pid_raw    = isset( $col_index['product_id'] ) && isset( $data[ $col_index['product_id'] ] )
                ? trim( $data[ $col_index['product_id'] ] )
                : '';
            $price_raw  = isset( $col_index['price'] ) && isset( $data[ $col_index['price'] ] )
                ? trim( $data[ $col_index['price'] ] )
                : '';
            $note_raw   = isset( $col_index['note'] ) && isset( $data[ $col_index['note'] ] )
                ? trim( $data[ $col_index['note'] ] )
                : '';

            // Validar order
            if ( ! is_numeric( $order_val ) ) {
                $warnings[] = sprintf(
                    /* translators: 1: row number, 2: value */
                    __( 'Fila %1$d ignorada: "order" no es un número válido ("%2$s").', 'acf-woo-fasciculos' ),
                    $row_num,
                    $order_val
                );
                continue;
            }

            // Validar precio
            $price_val = str_replace( ',', '.', $price_raw );
            if ( ! is_numeric( $price_val ) ) {
                $warnings[] = sprintf(
                    /* translators: 1: row number, 2: value */
                    __( 'Fila %1$d ignorada: "price" no es un número válido ("%2$s").', 'acf-woo-fasciculos' ),
                    $row_num,
                    $price_raw
                );
                continue;
            }

            // Procesar ID de producto
            if ( ! is_numeric( $pid_raw ) ) {
                $warnings[] = sprintf(
                    /* translators: 1: row number, 2: value */
                    __( 'Fila %1$d ignorada: ID de producto no numérico ("%2$s").', 'acf-woo-fasciculos' ),
                    $row_num,
                    $pid_raw
                );
                continue;
            }

            $pid_int = intval( $pid_raw );
            $product = wc_get_product( $pid_int );

            if ( ! $product ) {
                $warnings[] = sprintf(
                    /* translators: 1: row number, 2: product ID */
                    __( 'Fila %1$d: Producto con ID %2$d no encontrado.', 'acf-woo-fasciculos' ),
                    $row_num,
                    $pid_int
                );

                if ( ! $skip_invalid ) {
                    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
                    fclose( $handle );
                    return new WP_Error(
                        'invalid_product',
                        sprintf(
                            /* translators: %d: row number */
                            __( 'Fila %d: Se encontró un producto inválido y la opción "omitir inválidos" está desactivada. Importación abortada.', 'acf-woo-fasciculos' ),
                            $row_num
                        )
                    );
                }
                continue;
            }

            // Agrupar por 'order' (semana)
            $order_int = intval( $order_val );
            if ( ! isset( $rows[ $order_int ] ) ) {
                $rows[ $order_int ] = array(
                    'order'    => $order_int,
                    'products' => array(),
                    'price'    => 0.0,
                    'notes'    => array(),
                );
            }

            $rows[ $order_int ]['products'][] = $pid_int;
            $rows[ $order_int ]['price']     += floatval( $price_val );

            $clean_note = sanitize_text_field( $note_raw );
            if ( ! empty( $clean_note ) ) {
                $rows[ $order_int ]['notes'][] = $clean_note;
            }
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
        fclose( $handle );

        if ( empty( $rows ) ) {
            return array(
                'plan'     => array(),
                'warnings' => $warnings,
            );
        }

        // Ordenar por la clave (que es la columna 'order')
        ksort( $rows );

        // Construir el plan en el formato de ACF repeater
        $plan = array();
        foreach ( $rows as $row ) {
            $fasciculo_products = array();
            foreach ( $row['products'] as $pid ) {
                $fasciculo_products[] = array(
                    'product' => $pid,
                );
            }

            $note_str = implode( ' | ', array_unique( $row['notes'] ) );

            $plan[] = array(
                'fasciculo_products' => $fasciculo_products,
                'fasciculo_price'    => round( $row['price'], 2 ),
                'fasciculo_note'     => $note_str,
            );
        }

        return array(
            'plan'     => $plan,
            'warnings' => $warnings,
        );
    }

    /**
     * Descargar template CSV de ejemplo
     *
     * @return void
     */
    public function download_csv_template() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Sin permisos.', 'acf-woo-fasciculos' ) );
        }

        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="plantilla-fasciculos.csv"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
        $out = fopen( 'php://output', 'w' );

        // BOM para compatibilidad Excel
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 'order', 'product_id', 'price', 'note' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 1, '123', '10.00', 'Producto 1 de semana 1' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 1, '124', '15.00', 'Producto 2 de semana 1' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 2, '456', '14.50', 'Dos productos esta semana' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 2, '789', '5.00', '' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 3, '321', '9.99', 'Edición especial' ) );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fputcsv
        fputcsv( $out, array( 4, '111', '12.00', '' ) );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
        fclose( $out );
        exit;
    }

    /**
     * Redirigir de vuelta a la página de importación con un resultado almacenado en transient
     *
     * @param array $result Datos del resultado.
     * @return void
     */
    private function redirect_with_result( $result ) {
        set_transient( 'acf_woo_fasciculos_import_result_' . get_current_user_id(), $result, 60 );
        wp_safe_redirect( admin_url( 'admin.php?page=acf-woo-fasciculos-csv-import' ) );
        exit;
    }

    /**
     * Obtener todos los productos de suscripción publicados
     *
     * @return WC_Product[] Array de productos de suscripción.
     */
    private function get_subscription_products() {
        $args = array(
            'post_type'   => 'product',
            'post_status' => 'publish',
            'numberposts' => -1,
            'tax_query'   => array(
                array(
                    'taxonomy' => 'product_type',
                    'field'    => 'slug',
                    'terms'    => array( 'subscription', 'variable-subscription' ),
                    'operator' => 'IN',
                ),
            ),
            'orderby' => 'title',
            'order'   => 'ASC',
        );

        $posts    = get_posts( $args );
        $products = array();
        foreach ( $posts as $post ) {
            $product = wc_get_product( $post->ID );
            if ( $product ) {
                $products[] = $product;
            }
        }

        return $products;
    }
}
