<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Digikala_Api {

    private string $base_url = 'https://api.digikala.com/v2/product/';

    /**
     * Get product data from Digikala API.
     *
     * @param int|string $product_id
     * @return array|false Product data array or false on failure
     */
    public function get_product( int|string $product_id ): array|false {
        $product_id = intval( $product_id );
        $url = $this->base_url . $product_id . '/';

        $response = wp_remote_get( $url, [
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if ( is_wp_error( $response ) ) {
            error_log( sprintf( 'Digikala API error: %s', $response->get_error_message() ) );
            return false;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            error_log( sprintf(
                'Digikala API returned status %d: %s',
                $status_code,
                wp_remote_retrieve_response_message( $response )
            ));
            return false;
        }

        $body = wp_remote_retrieve_body( $response );
        if ( empty( $body ) ) {
            error_log( 'Digikala API returned empty body.' );
            return false;
        }

        $data = json_decode( $body, true );
        if ( json_last_error() !== JSON_ERROR_NONE ) {
            error_log( sprintf( 'JSON decode error: %s', json_last_error_msg() ) );
            return false;
        }

        return $data['data']['product'] ?? false;
    }
}
