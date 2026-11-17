<?php
declare( strict_types=1 );

// Minimal WordPress/WooCommerce stand-ins; run with: php tests/Product_Module_Test.php

/** Product without WooCommerce's own GTIN field (before WooCommerce 9.2). */
class WC_Product {
	public array $meta;
	public int $saves = 0;
	public function __construct( array $meta = array() ) { $this->meta = $meta; }
	public function get_id(): int { return 7; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function save_meta_data(): void { ++$this->saves; }
	public function save(): int { ++$this->saves; return 7; }
}

/** Product with WooCommerce's own GTIN field (WooCommerce 9.2+). */
class Native_GTIN_Product extends WC_Product {
	public string $native = '';
	/** @var array<int,string> GTINs other products already use. */
	public static array $taken = array();
	public function get_global_unique_id(): string { return $this->native; }
	public function set_global_unique_id( string $value ): void {
		if ( in_array( $value, self::$taken, true ) ) {
			throw new Exception( 'Invalid or duplicated GTIN, UPC, EAN or ISBN.' );
		}
		$this->native = $value;
	}
}

$GLOBALS['test_product']    = null;
$GLOBALS['test_transients'] = array();
function current_user_can( $cap, $id = 0 ): bool { return true; }
function wp_verify_nonce( $nonce, $action ): bool { return 'good' === $nonce; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( $value ) { return $value; }
function wc_get_product( $id ) { return $GLOBALS['test_product']; }
function get_current_user_id(): int { return 1; }
function set_transient( $key, $value, $ttl = 0 ): bool { $GLOBALS['test_transients'][ $key ] = $value; return true; }

require dirname( __DIR__ ) . '/src/Product_Module.php';

use Bixisoft\RankaviaWooCommerce\Product_Module;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

/**
 * Submit the product form.
 *
 * @param WC_Product           $product Product as stored.
 * @param array<string,string> $fields  Posted fields besides nonce and revision.
 * @param string|null          $rev     Revision the form was opened with; null = current.
 * @return string|null Notice code.
 */
function submit( WC_Product $product, array $fields, ?string $rev = null ): ?string {
	$GLOBALS['test_product']    = $product;
	$GLOBALS['test_transients'] = array();
	$_POST = $fields + array(
		'rankavia_wc_product_meta_nonce' => 'good',
		'rankavia_wc_rev'                => $rev ?? Product_Module::revision( (string) $product->get_meta( '_rankavia_wc_brand' ), (string) $product->get_meta( '_rankavia_wc_gtin' ) ),
	);
	( new Product_Module() )->save_fields( 7 );
	return $GLOBALS['test_transients']['rankavia_wc_notice_1'] ?? null;
}

$module = new Product_Module();
$native = array( '@type' => 'Product', 'offers' => array( 'priceCurrency' => 'USD' ), 'review' => array( 'ratingValue' => 5 ) );

// Output.
check( $native === $module->extend_product_data( $native, new WC_Product() ), 'Default changes native data' );
check( $native === $module->extend_product_data( $native, new stdClass() ), 'Nonproduct changes native data' );
check( 'bad' === $module->extend_product_data( 'bad', new WC_Product() ), 'Nonarray changed' );
$authored = new WC_Product( array( '_rankavia_wc_brand' => 'Acme', '_rankavia_wc_gtin' => '4006381333931' ) );
$extended = $module->extend_product_data( $native, $authored );
check( array( '@type' => 'Brand', 'name' => 'Acme' ) === $extended['brand'], 'Brand is a schema.org Brand like WooCommerce Brands' );
check( '4006381333931' === $extended['gtin13'], 'Authored GTIN missing' );
check( $native['offers'] === $extended['offers'] && $native['review'] === $extended['review'], 'Native claims changed' );
$existing = $native + array( 'brand' => array( '@type' => 'Brand', 'name' => 'Native' ), 'gtin' => '123456789012' );
check( $existing === $module->extend_product_data( $existing, $authored ), 'Existing native brand or GTIN overwritten or duplicated' );
check( $native === $module->extend_product_data( $native, new WC_Product( array( '_rankavia_wc_gtin' => '4006381333932' ) ) ), 'Invalid check digit accepted' );
$with_native       = new Native_GTIN_Product( array( '_rankavia_wc_gtin' => '4006381333931' ) );
$with_native->native = '96385074';
check( ! isset( $module->extend_product_data( $native, $with_native )['gtin13'] ), "WooCommerce's own GTIN wins over the saved one" );
check( '4006381333931' === Product_Module::normalize_gtin( ' 400-6381 333931 ' ), 'Spaces and hyphens not removed' );
check( ! Product_Module::valid_gtin( '400638133393X' ), 'Non-digit accepted' );
check( Product_Module::valid_gtin( '96385074' ), 'Valid GTIN-8 rejected' );

// Saving without WooCommerce's GTIN field.
$p = new WC_Product();
check( null === submit( $p, array( '_rankavia_wc_brand' => 'Acme', '_rankavia_wc_gtin' => '4006-3813-33931' ) ), 'Clean save flagged' );
check( 'Acme' === $p->meta['_rankavia_wc_brand'] && '4006381333931' === $p->meta['_rankavia_wc_gtin'], 'Brand or normalized GTIN not saved' );
check( null === submit( $p, array( '_rankavia_wc_brand' => '', '_rankavia_wc_gtin' => '' ) ), 'Blank save flagged' );
check( 'Acme' === $p->meta['_rankavia_wc_brand'] && '4006381333931' === $p->meta['_rankavia_wc_gtin'], 'Blank fields must keep saved values' );
submit( $p, array( '_rankavia_wc_brand_clear' => 'yes', '_rankavia_wc_gtin_clear' => 'yes' ) );
check( ! isset( $p->meta['_rankavia_wc_brand'] ) && ! isset( $p->meta['_rankavia_wc_gtin'] ), 'Clear did not remove the values' );
check( 'gtin_invalid' === submit( $p, array( '_rankavia_wc_gtin' => '4006381333932' ) ), 'Invalid GTIN not reported' );
check( ! isset( $p->meta['_rankavia_wc_gtin'] ), 'Invalid GTIN saved' );

// A value changed by someone else while the form was open is kept.
$p = new WC_Product( array( '_rankavia_wc_brand' => 'Theirs' ) );
check( 'stale' === submit( $p, array( '_rankavia_wc_brand' => 'Mine' ), Product_Module::revision( 'Old', '' ) ), 'Stale form not detected' );
check( 'Theirs' === $p->meta['_rankavia_wc_brand'], 'Stale form overwrote a newer value' );

// Moving a saved GTIN into WooCommerce's own field.
$p = new Native_GTIN_Product( array( '_rankavia_wc_gtin' => '4006381333931' ) );
check( 'moved' === submit( $p, array( '_rankavia_wc_gtin_move' => 'yes' ) ), 'Move not reported' );
check( '4006381333931' === $p->native && ! isset( $p->meta['_rankavia_wc_gtin'] ), 'GTIN not moved' );
$p = new Native_GTIN_Product( array( '_rankavia_wc_gtin' => '96385074' ) );
Native_GTIN_Product::$taken = array( '96385074' );
check( 'gtin_taken' === submit( $p, array( '_rankavia_wc_gtin_move' => 'yes' ) ), 'Duplicate GTIN refusal not reported' );
check( '' === $p->native && '96385074' === $p->meta['_rankavia_wc_gtin'], 'Refused GTIN lost' );
$p = new Native_GTIN_Product( array( '_rankavia_wc_gtin' => '4006381333931' ) );
check( null === submit( $p, array( '_rankavia_wc_gtin' => '96385074' ) ), 'Typed GTIN with a native field flagged' );
check( '4006381333931' === $p->meta['_rankavia_wc_gtin'] && '' === $p->native, 'With a native field, typed values must not create a second GTIN' );

// Bad nonce changes nothing.
$p      = new WC_Product( array( '_rankavia_wc_brand' => 'Acme' ) );
$_POST  = array( 'rankavia_wc_product_meta_nonce' => 'forged', '_rankavia_wc_brand_clear' => 'yes' );
$GLOBALS['test_product'] = $p;
( new Product_Module() )->save_fields( 7 );
check( 'Acme' === $p->meta['_rankavia_wc_brand'], 'Forged request changed data' );

echo "Product module checks passed\n";
