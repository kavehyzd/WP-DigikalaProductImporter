<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Digikala_Api {
    private $base_url = "https://api.digikala.com/v2/product/";

    public function get_product( $product_id ) {
        $url = $this->base_url . intval( $product_id )."/";
        $response = wp_remote_get( $url, [ 'timeout' => 20 ] );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return $data['data']['product'] ?? false;
    }
}
