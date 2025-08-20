<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Digikala_Job_Runner {
    private $api;
    private $importer;

    public function __construct() {
        $this->api = new Digikala_Api();
        $this->importer = new Digikala_Product_Importer();
    }

    public function run( $ids, $gallery_limit = 3 ) {
        foreach ( $ids as $id ) {
            $product_data = $this->api->get_product( $id );
            if ( $product_data ) {
                $product_id = $this->importer->import( $product_data, $gallery_limit );
                if ( $product_id ) {
                    echo "<div style='margin:10px 0;padding:10px;background:#d7ffd7;border:1px solid #0c0;'>محصول با شناسه {$id} وارد شد (ID: {$product_id})</div>";
                } else {
                    echo "<div style='margin:10px 0;padding:10px;background:#ffd7d7;border:1px solid #c00;'>خطا در وارد کردن محصول {$id}</div>";
                }
            } else {
                echo "<div style='margin:10px 0;padding:10px;background:#ffd7d7;border:1px solid #c00;'>اطلاعات محصول {$id} یافت نشد</div>";
            }
        }
    }
}
