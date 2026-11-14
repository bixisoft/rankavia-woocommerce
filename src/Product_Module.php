<?php
/**
 * Optional WooCommerce product metadata extension.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/** Adds merchant-entered product metadata to WooCommerce. */
final class Product_Module {
	private const BRAND = '_rankavia_wc_brand';
	private const GTIN  = '_rankavia_wc_gtin';
	private const NONCE = 'rankavia_wc_product_meta';

	/** Register WooCommerce hooks. */
	public function boot(): void {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'extend_product_data' ), 20, 2 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ) );
	}

	/**
	 * Preserve every native value, adding only valid, merchant-entered values.
	 *
	 * @param mixed $data    Native product structured data.
	 * @param mixed $product WooCommerce product.
	 * @return mixed
	 */
	public function extend_product_data( $data, $product ) {
		if ( ! is_array( $data ) || ! $product instanceof \WC_Product ) {
			return $data;
		}

		$brand = trim( (string) $product->get_meta( self::BRAND, true ) );
		if ( '' !== $brand && ! array_key_exists( 'brand', $data ) ) {
			$data['brand'] = $brand;
		}

		$gtin = trim( (string) $product->get_meta( self::GTIN, true ) );
		if ( self::valid_gtin( $gtin ) ) {
			$key         = 'gtin' . strlen( $gtin );
			$native_gtin = false;
			foreach ( array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14' ) as $native_key ) {
				$native_gtin = $native_gtin || array_key_exists( $native_key, $data );
			}
			if ( ! $native_gtin ) {
				$data[ $key ] = $gtin;
			}
		}
		return $data;
	}

	/**
	 * Check the GS1 digit for GTIN-8, -12, -13 and -14.
	 *
	 * @param string $gtin GTIN to validate.
	 * @return bool
	 */
	public static function valid_gtin( string $gtin ): bool {
		$length = strlen( $gtin );
		if ( ! in_array( $length, array( 8, 12, 13, 14 ), true ) || ! ctype_digit( $gtin ) ) {
			return false;
		}
		$sum    = 0;
		$weight = 3;
		for ( $index = $length - 2; $index >= 0; --$index ) {
			$sum   += (int) $gtin[ $index ] * $weight;
			$weight = 3 === $weight ? 1 : 3;
		}
		return ( 10 - $sum % 10 ) % 10 === (int) $gtin[ $length - 1 ];
	}

	/** Render the merchant metadata fields. */
	public function render_fields(): void {
		global $product_object;
		if ( ! $product_object instanceof \WC_Product || ! current_user_can( 'edit_post', $product_object->get_id() ) ) {
			return;
		}
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		woocommerce_wp_text_input(
			array(
				'id'    => self::BRAND,
				'label' => __( 'Merchant brand', 'rankavia-woocommerce' ),
				'value' => $product_object->get_meta( self::BRAND, true ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => self::GTIN,
				'label' => __( 'GTIN (8, 12, 13 or 14 digits)', 'rankavia-woocommerce' ),
				'value' => $product_object->get_meta( self::GTIN, true ),
			)
		);
	}

	/**
	 * Save valid merchant metadata fields.
	 *
	 * @param int $product_id Product post ID.
	 * @return void
	 */
	public function save_fields( int $product_id ): void {
		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';
		if ( ! current_user_can( 'edit_post', $product_id ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		if ( isset( $_POST[ self::BRAND ] ) && is_string( $_POST[ self::BRAND ] ) ) {
			$brand = sanitize_text_field( wp_unslash( $_POST[ self::BRAND ] ) );
			if ( '' !== trim( $brand ) ) {
				$product->update_meta_data( self::BRAND, $brand );
			}
		}
		if ( isset( $_POST[ self::GTIN ] ) && is_string( $_POST[ self::GTIN ] ) ) {
			$gtin = trim( sanitize_text_field( wp_unslash( $_POST[ self::GTIN ] ) ) );
			if ( self::valid_gtin( $gtin ) ) {
				$product->update_meta_data( self::GTIN, $gtin );
			}
		}
		$product->save_meta_data();
	}
}
