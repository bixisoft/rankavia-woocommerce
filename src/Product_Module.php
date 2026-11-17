<?php
/**
 * Optional WooCommerce product metadata extension.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Adds a merchant-entered brand and GTIN to WooCommerce product structured data.
 *
 * WooCommerce's own fields come first: its GTIN field (WooCommerce 9.2+) and its Brands
 * (WooCommerce 9.6+). This add-on only fills a gap they leave, and helps move its own saved GTIN
 * into WooCommerce's field.
 */
final class Product_Module {
	private const BRAND  = '_rankavia_wc_brand';
	public const GTIN    = '_rankavia_wc_gtin';
	private const NONCE  = 'rankavia_wc_product_meta';
	private const REV    = 'rankavia_wc_rev';
	private const NOTICE = 'rankavia_wc_notice_';

	/** Register WooCommerce hooks. */
	public function boot(): void {
		add_filter( 'woocommerce_structured_data_product', array( $this, 'extend_product_data' ), 20, 2 );
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_fields' ) );
		// After WooCommerce saves its own fields (priority 10), so a moved GTIN lands in a saved product.
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_fields' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
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
			$data['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}

		$gtin = self::normalize_gtin( (string) $product->get_meta( self::GTIN, true ) );
		if ( self::valid_gtin( $gtin ) && ! self::has_gtin( $data ) && '' === self::native_gtin( $product ) ) {
			$data[ 'gtin' . strlen( $gtin ) ] = $gtin;
		}
		return $data;
	}

	/**
	 * Remove the spaces and hyphens people type in printed GTINs.
	 *
	 * @param string $gtin Entered GTIN.
	 * @return string
	 */
	public static function normalize_gtin( string $gtin ): string {
		return str_replace( array( ' ', '-' ), '', trim( $gtin ) );
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

	/**
	 * Whether this WooCommerce has its own GTIN field (9.2+).
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public static function has_native_gtin_field( \WC_Product $product ): bool {
		return method_exists( $product, 'get_global_unique_id' ) && method_exists( $product, 'set_global_unique_id' );
	}

	/**
	 * WooCommerce's own GTIN for a product, '' when none or unsupported.
	 *
	 * @param \WC_Product $product Product.
	 * @return string
	 */
	public static function native_gtin( \WC_Product $product ): string {
		return self::has_native_gtin_field( $product ) ? trim( (string) $product->get_global_unique_id() ) : '';
	}

	/** Render the merchant metadata fields. */
	public function render_fields(): void {
		global $product_object;
		if ( ! $product_object instanceof \WC_Product || ! current_user_can( 'edit_post', $product_object->get_id() ) ) {
			return;
		}
		$brand = (string) $product_object->get_meta( self::BRAND, true );
		$gtin  = (string) $product_object->get_meta( self::GTIN, true );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<input type="hidden" name="' . esc_attr( self::REV ) . '" value="' . esc_attr( self::revision( $brand, $gtin ) ) . '">';
		echo '<div class="options_group rankavia-wc-identity">';

		woocommerce_wp_text_input(
			array(
				'id'          => self::BRAND,
				'label'       => __( 'Merchant brand', 'rankavia-woocommerce' ),
				'value'       => $brand,
				'desc_tip'    => true,
				'description' => taxonomy_exists( 'product_brand' )
					? __( 'WooCommerce Brands is active: a brand assigned there is used first. This text is used only for products without a WooCommerce brand.', 'rankavia-woocommerce' )
					: __( 'Added to the product structured data when WooCommerce does not already provide a brand.', 'rankavia-woocommerce' ),
			)
		);
		if ( '' !== trim( $brand ) ) {
			$this->checkbox( self::BRAND . '_clear', __( 'Clear the merchant brand', 'rankavia-woocommerce' ) );
		}

		if ( ! self::has_native_gtin_field( $product_object ) ) {
			woocommerce_wp_text_input(
				array(
					'id'    => self::GTIN,
					'label' => __( 'GTIN (8, 12, 13 or 14 digits)', 'rankavia-woocommerce' ),
					'value' => $gtin,
				)
			);
			if ( '' !== trim( $gtin ) ) {
				$this->checkbox( self::GTIN . '_clear', __( 'Clear the GTIN', 'rankavia-woocommerce' ) );
			}
		} elseif ( '' !== trim( $gtin ) ) {
			$native = self::native_gtin( $product_object );
			echo '<p class="form-field"><label>' . esc_html__( 'GTIN saved by Rankavia', 'rankavia-woocommerce' ) . '</label><code>' . esc_html( $gtin ) . '</code> ';
			if ( '' === $native ) {
				echo esc_html__( 'WooCommerce now has its own GTIN field (Inventory tab). Move this value there so every tool reads the same number.', 'rankavia-woocommerce' ) . '</p>';
				$this->checkbox( self::GTIN . '_move', __( "Move it to WooCommerce's GTIN field", 'rankavia-woocommerce' ) );
			} else {
				/* translators: %s: GTIN stored in WooCommerce's own field. */
				echo esc_html( sprintf( __( "WooCommerce's own GTIN (%s) is used; this value is ignored.", 'rankavia-woocommerce' ), $native ) ) . '</p>';
			}
			$this->checkbox( self::GTIN . '_clear', __( 'Clear the GTIN saved by Rankavia', 'rankavia-woocommerce' ) );
		}
		echo '</div>';
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
		$brand = (string) $product->get_meta( self::BRAND, true );
		$gtin  = (string) $product->get_meta( self::GTIN, true );
		$seen  = isset( $_POST[ self::REV ] ) && is_string( $_POST[ self::REV ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::REV ] ) ) : '';
		if ( ! hash_equals( self::revision( $brand, $gtin ), $seen ) ) {
			// Someone changed these values after this page was opened; keep theirs.
			$this->flag( 'stale' );
			return;
		}

		if ( $this->checked( self::BRAND . '_clear' ) ) {
			$product->delete_meta_data( self::BRAND );
		} elseif ( isset( $_POST[ self::BRAND ] ) && is_string( $_POST[ self::BRAND ] ) ) {
			$new_brand = sanitize_text_field( wp_unslash( $_POST[ self::BRAND ] ) );
			if ( '' !== trim( $new_brand ) ) {
				$product->update_meta_data( self::BRAND, $new_brand );
			}
		}

		$native_changed = false;
		if ( $this->checked( self::GTIN . '_clear' ) ) {
			$product->delete_meta_data( self::GTIN );
		} elseif ( self::has_native_gtin_field( $product ) ) {
			$saved = self::normalize_gtin( $gtin );
			if ( $this->checked( self::GTIN . '_move' ) && self::valid_gtin( $saved ) && '' === self::native_gtin( $product ) ) {
				try {
					$product->set_global_unique_id( $saved );
					$product->delete_meta_data( self::GTIN );
					$native_changed = true;
					$this->flag( 'moved' );
				} catch ( \Exception $taken ) {
					// WooCommerce refuses a GTIN another product already uses.
					$this->flag( 'gtin_taken' );
				}
			}
		} elseif ( isset( $_POST[ self::GTIN ] ) && is_string( $_POST[ self::GTIN ] ) ) {
			$new_gtin = self::normalize_gtin( sanitize_text_field( wp_unslash( $_POST[ self::GTIN ] ) ) );
			if ( self::valid_gtin( $new_gtin ) ) {
				$product->update_meta_data( self::GTIN, $new_gtin );
			} elseif ( '' !== $new_gtin ) {
				$this->flag( 'gtin_invalid' );
			}
		}

		if ( $native_changed ) {
			$product->save();
		} else {
			$product->save_meta_data();
		}
	}

	/**
	 * Show the outcome of the last save once.
	 *
	 * @return void
	 */
	public function notice(): void {
		$key  = self::NOTICE . get_current_user_id();
		$code = get_transient( $key );
		if ( ! is_string( $code ) ) {
			return;
		}
		delete_transient( $key );
		$messages = array(
			'stale'        => array( 'warning', __( 'The brand or GTIN was changed by someone else while you edited this product, so your values were not saved. Check them and save again.', 'rankavia-woocommerce' ) ),
			'moved'        => array( 'success', __( "The GTIN was moved to WooCommerce's own GTIN field.", 'rankavia-woocommerce' ) ),
			'gtin_taken'   => array( 'error', __( 'WooCommerce did not accept the GTIN because another product already uses it. It was kept where it was.', 'rankavia-woocommerce' ) ),
			'gtin_invalid' => array( 'error', __( 'The GTIN was not saved: it must be 8, 12, 13 or 14 digits with a valid check digit.', 'rankavia-woocommerce' ) ),
		);
		if ( ! isset( $messages[ $code ] ) ) {
			return;
		}
		echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
	}

	/**
	 * Identity of the stored values, to notice changes made while the form was open.
	 *
	 * @param string $brand Stored brand.
	 * @param string $gtin  Stored GTIN.
	 * @return string
	 */
	public static function revision( string $brand, string $gtin ): string {
		return hash( 'sha256', $brand . "\0" . $gtin );
	}

	/**
	 * Whether structured data already carries any GTIN.
	 *
	 * @param array<string,mixed> $data Structured data.
	 * @return bool
	 */
	private static function has_gtin( array $data ): bool {
		foreach ( array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14' ) as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A checkbox field.
	 *
	 * @param string $id    Field id and name.
	 * @param string $label Label.
	 * @return void
	 */
	private function checkbox( string $id, string $label ): void {
		woocommerce_wp_checkbox(
			array(
				'id'          => $id,
				'label'       => '',
				'cbvalue'     => 'yes',
				'value'       => 'no',
				'description' => $label,
			)
		);
	}

	/**
	 * Whether a checkbox was ticked.
	 *
	 * @param string $id Field name.
	 * @return bool
	 */
	private function checked( string $id ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called from save_fields() after the nonce check.
		return isset( $_POST[ $id ] ) && 'yes' === $_POST[ $id ];
	}

	/**
	 * Remember a message for the next admin page.
	 *
	 * @param string $code Message code.
	 * @return void
	 */
	private function flag( string $code ): void {
		set_transient( self::NOTICE . get_current_user_id(), $code, 300 );
	}
}
