<?php
/**
 * Plugin Name: Digikala Importer for WooCommerce
 * Description: افزونه حرفه‌ای برای وارد کردن محصولات از دیجیکالا به ووکامرس (با API).
 * Version: 1.0.0
 * Author: KAVEH & ChatGPT5
 * Text Domain: digikala-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Define constants
 */
define( 'DIGIKALA_IMPORTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGIKALA_IMPORTER_URL', plugin_dir_url( __FILE__ ) );
define( 'DIGIKALA_IMPORTER_VERSION', '1.0.0' );

/**
 * Autoload classes (simple version)
 */
spl_autoload_register( function( $class ) {
    if ( strpos( $class, 'Digikala_' ) !== false ) {
        $file = DIGIKALA_IMPORTER_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
});

/**
 * Include helpers
 */
require_once DIGIKALA_IMPORTER_PATH . 'includes/helpers.php';

/**
 * Init Plugin
 */
class Digikala_Importer_Plugin {

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'init' ] );
    }

    public function init() {
        // چک کنیم که ووکامرس فعال باشه
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="error"><p>برای استفاده از افزونه <strong>Digikala Importer</strong> باید ووکامرس فعال باشد.</p></div>';
            });
            return;
        }

        // اضافه کردن منو به ادمین
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );

        // رجیستر کردن job runner
        new Digikala_Job_Runner();
    }

    public function add_admin_menu() {
        add_menu_page(
            'Digikala Importer',
            'Digikala Importer',
            'manage_options',
            'digikala-importer',
            [ $this, 'admin_page' ],
            'dashicons-download',
            58
        );
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>وارد کردن محصولات از دیجیکالا</h1>
            <p> لطفا یک لیست از شناسه محصولات را در فیلد زیر وارد نمایید</p>
            <ul>
                <li>در هر سطر یک شناسه محصول را وارد تمایید</li>
                <li>فقط شناسه عدیی محصول را وارد نمایید (مثال : 1254585)</li>
            </ul>
            <form method="post" action="">
                <?php wp_nonce_field( 'digikala_import_nonce', 'digikala_import_nonce_field' ); ?>
                <textarea name="product_ids" rows="6" style="width:100%;" placeholder="شناسه محصولات را خط به خط وارد کنید..."></textarea>
                <p>
                    <label>تعداد تصاویر گالری (غیر از تصویر اصلی): </label>
                    <input type="number" name="gallery_limit" value="3" min="0" max="10" />
                </p>
                <p>
                    <input type="submit" class="button button-primary" value="شروع وارد کردن" />
                </p>
            </form>
        </div>
        <?php

        // وقتی فرم ارسال شد
        if ( isset( $_POST['product_ids'] ) && check_admin_referer( 'digikala_import_nonce', 'digikala_import_nonce_field' ) ) {
            $ids = array_filter( array_map( 'trim', explode( "\n", sanitize_textarea_field( $_POST['product_ids'] ) ) ) );
            $limit = isset($_POST['gallery_limit']) ? intval($_POST['gallery_limit']) : 3;

            if ( ! empty( $ids ) ) {
                $runner = new Digikala_Job_Runner();
                $runner->run( $ids, $limit );
            }
        }
    }
}

new Digikala_Importer_Plugin();
