<?php
declare( strict_types=1 );

class WC_Product {
	private array $meta;
	public function __construct( array $meta = array() ) { $this->meta = $meta; }
	public function get_meta( string $key, bool $single = true ) { return $this->meta[ $key ] ?? ''; }
}

require dirname( __DIR__ ) . '/src/Product_Module.php';

use Bixisoft\RankaviaWooCommerce\Product_Module;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$module = new Product_Module();
$native = array( '@type' => 'Product', 'offers' => array( 'priceCurrency' => 'USD' ), 'review' => array( 'ratingValue' => 5 ) );
check( $native === $module->extend_product_data( $native, new WC_Product() ), 'Default changes native data' );
check( $native === $module->extend_product_data( $native, new stdClass() ), 'Nonproduct changes native data' );
check( 'bad' === $module->extend_product_data( 'bad', new WC_Product() ), 'Nonarray changed' );
$authored = new WC_Product( array( '_rankavia_wc_brand' => '<Acme>', '_rankavia_wc_gtin' => '4006381333931' ) );
$extended = $module->extend_product_data( $native, $authored );
check( '<Acme>' === $extended['brand'] && '4006381333931' === $extended['gtin13'], 'Authored fields missing' );
check( $native['offers'] === $extended['offers'] && $native['review'] === $extended['review'], 'Native claims changed' );
$existing = $native + array( 'brand' => 'Native', 'gtin12' => '123456789012' );
check( $existing === $module->extend_product_data( $existing, $authored ), 'Existing native fields overwritten or duplicated' );
check( $native === $module->extend_product_data( $native, new WC_Product( array( '_rankavia_wc_gtin' => '4006381333932' ) ) ), 'Invalid check digit accepted' );
check( ! Product_Module::valid_gtin( '400638133393X' ), 'Non-digit accepted' );
check( Product_Module::valid_gtin( '96385074' ), 'Valid GTIN-8 rejected' );
echo "Product module checks passed\n";
