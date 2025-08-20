<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Digikala_Product_Importer {

    private $category_id;

    public function __construct( $category_id = 0 ) {
        $this->category_id = absint($category_id);
    }
    /**
     * ساخت یا بروزرسانی محصول از داده‌های دیجی‌کالا
     */
    public function import( $product_data, $gallery_limit = 3 ) {
        $title   = sanitize_text_field($product_data['title_fa'] ?? '');
        $desc    = wp_kses_post((string)($product_data['review'] ?? ''));
        $specs   = (array)($product_data['specifications'] ?? []);
        $colors  = (array)($product_data['colors'] ?? []);

        if ( ! $title ) {
            echo "<div class='notice notice-error'><p>عنوان محصول یافت نشد.</p></div>";
            return false;
        }

        // =========================
        // محصول متغیر
        // =========================
        $product = new WC_Product_Variable();
        $product->set_name($title);
        $product->set_description($desc);
        $product->set_status('publish');
        $product->set_catalog_visibility('visible');

        if ( $this->category_id > 0 ) {
            $product->set_category_ids([$this->category_id]);
        }

        // =========================
        // ویژگی‌ها
        // =========================
        $attr_objects = [];

        // --- مشخصات فنی ---
        foreach ($specs as $group) {
            $groupTitle = sanitize_text_field($group['title'] ?? '');
            $attributes = $group['attributes'] ?? [];

            foreach ($attributes as $attr) {
                $label  = $groupTitle . ' - ' . sanitize_text_field($attr['title'] ?? '');
                $values = array_map('sanitize_text_field', $attr['values'] ?? []);

                if ($label && !empty($values)) {
                    $a = new WC_Product_Attribute();
                    $a->set_name($label);
                    $a->set_options($values);
                    $a->set_visible(true);
                    $a->set_variation(false); // فقط نمایشی
                    $attr_objects[] = $a;
                }
            }
        }

        // --- رنگ‌ها (ویژگی متغیر) ---
        $color_values = [];
        foreach ($colors as $c) {
            $cn = sanitize_text_field($c['title'] ?? '');
            if ($cn && !in_array($cn, $color_values, true)) {
                $color_values[] = $cn;
            }
        }

        if ($color_values) {
            $color_attr = new WC_Product_Attribute();
            $color_attr->set_name('color'); // custom attribute
            $color_attr->set_options($color_values);
            $color_attr->set_visible(true);
            $color_attr->set_variation(true); // متغیر
            $attr_objects[] = $color_attr;
        }

        // ست کردن همه ویژگی‌ها
        if ($attr_objects) {
            $product->set_attributes($attr_objects);
        }

        // ذخیره محصول
        $product_id = $product->save();
        // ---------------- تصاویر ----------------
        $images = digikala_extract_images( $product_data, (int)$gallery_limit );

        // تصویر شاخص
        if ( ! empty( $images['main'] ) ) {
            $main_id = digikala_download_image( $images['main'], $product_id, 'dk-' . $sku . '-main', true );
            if ( $main_id ) $product->set_image_id( $main_id );
        }

        // گالری
        $gallery_ids = [];
        if ( ! empty( $images['gallery'] ) ) {
            $i = 1;
            foreach ( $images['gallery'] as $gurl ) {
                $aid = digikala_download_image( $gurl, $product_id, 'dk-' . $sku . '-g' . $i, true );
                if ( $aid ) $gallery_ids[] = $aid;
                $i++;
                if ( count($gallery_ids) >= (int)$gallery_limit ) break;
            }
            if ( ! empty( $gallery_ids ) ) {
                $product->set_gallery_image_ids( $gallery_ids );
            }
        }

        // ذخیره نهایی
        $product_id = $product->save();

        return $product_id;
    }

    /**
     * ساخت WC_Product_Attribute از specifications دیجی‌کالا
     */
    private function build_wc_attributes_from_specs( $specs ) {
        if ( empty( $specs ) || ! is_array( $specs ) ) return [];

        $attributes = [];
        $addedKeys  = [];

        foreach ( $specs as $group ) {
            if ( empty($group['attributes']) ) continue;

            foreach ( $group['attributes'] as $attr ) {
                $name = trim( (string) ( $attr['title'] ?? '' ) );
                if ( $name === '' ) continue;

                // گرفتن مقدار
                $values = [];
                if ( ! empty($attr['values']) ) {
                    foreach ( $attr['values'] as $v ) {
                        $val = trim( (string) ( $v['title'] ?? $v['value'] ?? '' ) );
                        if ( $val !== '' ) $values[] = $val;
                    }
                } elseif ( ! empty($attr['value']) ) {
                    $values[] = trim( (string) $attr['value'] );
                }

                if ( empty($values) ) continue;

                // کلید یکتا برای جلوگیری از تکرار
                $key = mb_strtolower( sanitize_title( $name ) );
                if ( isset($addedKeys[$key]) ) {
                    // اگر وجود دارد merge شود
                    $idx = $addedKeys[$key];
                    $merged = array_unique( array_merge( $attributes[$idx]->get_options(), $values ) );
                    $attributes[$idx]->set_options( $merged );
                    continue;
                }

                // ساخت Attribute جدید
                $wc_attr = new WC_Product_Attribute();
                $wc_attr->set_id( 0 ); // یعنی سفارشی (نه taxonomy)
                $wc_attr->set_name( $name );
                $wc_attr->set_options( $values );
                $wc_attr->set_visible( true ); // نمایش در صفحه محصول
                $wc_attr->set_variation( false ); // استفاده در متغیرها نه
                $wc_attr->set_position( count($attributes) );

                $attributes[] = $wc_attr;
                $addedKeys[$key] = count($attributes) - 1;
            }
        }

        return $attributes;
    }
}
