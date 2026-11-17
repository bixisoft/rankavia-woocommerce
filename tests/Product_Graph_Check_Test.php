<?php
declare( strict_types=1 );

// Pure comparison tests; run with: php tests/Product_Graph_Check_Test.php

function __( $text, $domain = '' ) { return $text; }

require dirname( __DIR__ ) . '/src/Product_Module.php';
require dirname( __DIR__ ) . '/src/Product_Graph_Check.php';

use Bixisoft\RankaviaWooCommerce\Product_Graph_Check as Check;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

/** @param array<int,array<string,string>> $found */
function codes( array $found ): array {
	return array_map( static function ( $f ) { return $f['level'] . ':' . $f['code']; }, $found );
}

$facts = array(
	'type'         => 'simple',
	'has_price'    => true,
	'price'        => '2500',
	'low'          => null,
	'high'         => null,
	'decimals'     => 2,
	'currency'     => 'USD',
	'availability' => 'InStock',
	'rating_count' => 0,
	'review_count' => 0,
	'gtin_native'  => '4006381333931',
	'gtin_addon'   => '',
	'sku'          => 'HAT-1',
);

// Current WooCommerce shape: sale price first, list price second, price copied onto the offer.
$graph = array(
	'@type'  => 'Product',
	'sku'    => 'HAT-1',
	'gtin'   => '4006381333931',
	'brand'  => array( '@type' => 'Brand', 'name' => 'Acme' ),
	'offers' => array(
		array(
			'@type'              => 'Offer',
			'price'              => '2500.00',
			'priceSpecification' => array(
				array( '@type' => 'UnitPriceSpecification', 'price' => '2500.00', 'priceCurrency' => 'USD' ),
				array( '@type' => 'UnitPriceSpecification', 'price' => '3000.00', 'priceCurrency' => 'USD', 'priceType' => 'https://schema.org/ListPrice' ),
			),
			'priceCurrency'      => 'USD',
			'availability'       => 'https://schema.org/InStock',
		),
	),
);
check( array() === Check::evaluate( $graph, $facts ), 'Matching graph has findings: ' . implode( ',', codes( Check::evaluate( $graph, $facts ) ) ) );

// WooCommerce 8 shape: one priceSpecification object, http availability, no offer price/currency on top.
$old                     = $graph;
$old['offers'][0]        = array(
	'@type'              => 'Offer',
	'priceSpecification' => array( 'price' => '2500.00', 'priceCurrency' => 'USD' ),
	'availability'       => 'http://schema.org/InStock',
);
check( array() === Check::evaluate( $old, $facts ), 'Woo 8 shape not understood' );

// Price, currency and stock mismatches.
$bad                              = $graph;
$bad['offers'][0]['price']        = '2272.73';
$bad['offers'][0]['priceCurrency'] = 'EUR';
$bad['offers'][0]['availability'] = 'https://schema.org/OutOfStock';
$found                            = Check::evaluate( $bad, $facts );
check( array( 'problem:price', 'problem:currency', 'problem:availability' ) === codes( $found ), 'Mismatches: ' . implode( ',', codes( $found ) ) );
check( '2272.73' === $found[0]['graph'] && '2500.00' === $found[0]['shop'], 'Price values not reported' );
check( false !== strpos( Check::message( $found[0] ), '2272.73' ), 'Message lacks graph price' );

// Rounding at shop precision is not a mismatch.
$round                       = $graph;
$round['offers'][0]['price'] = '2500.004';
check( array() === Check::evaluate( $round, $facts ), 'Sub-cent difference flagged' );

// No graph: missing price is a gap to fill, a removed graph is a problem.
check( array( 'missing:no_price' ) === codes( Check::evaluate( null, array( 'has_price' => false ) + $facts ) ), 'No price not explained' );
check( array( 'problem:no_graph' ) === codes( Check::evaluate( null, $facts ) ), 'Removed graph not flagged' );
$no_offer = $graph;
unset( $no_offer['offers'] );
check( in_array( 'problem:no_offer', codes( Check::evaluate( $no_offer, $facts ) ), true ), 'Missing offer not flagged' );

// Identifiers.
$ids = $graph;
unset( $ids['gtin'], $ids['brand'] );
check( array( 'missing:gtin', 'missing:brand' ) === codes( Check::evaluate( $ids, array( 'gtin_native' => '' ) + $facts ) ), 'Missing identifiers' );
check( array( 'missing:sku' ) === codes( Check::evaluate( $graph, array( 'sku' => '' ) + $facts ) ), 'Missing SKU' );
check( array( 'problem:gtin_rejected', 'missing:gtin' ) === codes( Check::evaluate( $ids + array( 'brand' => 'Acme' ), array( 'gtin_native' => '12345' ) + $facts ) ), 'Short native GTIN' );
$digit         = $graph;
$digit['gtin'] = '4006381333932';
check( array( 'problem:gtin_check_digit' ) === codes( Check::evaluate( $digit, array( 'gtin_native' => '4006381333932' ) + $facts ) ), 'Bad check digit' );
$mismatch          = $ids + array( 'brand' => 'Acme', 'gtin13' => '4006381333931' );
check( array( 'problem:gtin_mismatch' ) === codes( Check::evaluate( $mismatch, array( 'gtin_native' => '', 'gtin_addon' => '96385074' ) + $facts ) ), 'Add-on GTIN mismatch' );
check( array() === Check::evaluate( $ids + array( 'brand' => 'Acme', 'gtin8' => '96385074' ), array( 'gtin_native' => '', 'gtin_addon' => '96385074' ) + $facts ), 'Add-on GTIN not recognised' );

// Reviews must be real.
$rated                    = $graph;
$rated['aggregateRating'] = array( '@type' => 'AggregateRating', 'ratingValue' => '5', 'reviewCount' => 12 );
check( array( 'problem:rating_without_reviews' ) === codes( Check::evaluate( $rated, $facts ) ), 'Invented rating not flagged' );
check( array( 'problem:review_count' ) === codes( Check::evaluate( $rated, array( 'rating_count' => 3, 'review_count' => 3 ) + $facts ) ), 'Review count mismatch' );
check( array() === Check::evaluate( $rated, array( 'rating_count' => 12, 'review_count' => 12 ) + $facts ), 'Real reviews flagged' );

// Variable products: aggregate range and single-price offer.
$variable = array( 'price' => null, 'low' => '10', 'high' => '20', 'type' => 'variable' ) + $facts;
$range    = $graph;
$range['offers'][0] = array( '@type' => 'AggregateOffer', 'lowPrice' => '10.00', 'highPrice' => '20.00', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock' );
check( array() === Check::evaluate( $range, $variable ), 'Range not matched' );
$range['offers'][0]['highPrice'] = '25.00';
check( array( 'problem:price_range' ) === codes( Check::evaluate( $range, $variable ) ), 'Range mismatch' );
$same = array( 'low' => '15', 'high' => '15' ) + $variable;
$one  = $graph;
$one['offers'][0]['price'] = '15.00';
check( array() === Check::evaluate( $one, $same ), 'Single variable price not matched' );

// Finding the Product node in page JSON-LD.
$html = '<script type="application/ld+json">{"@context":"https://schema.org","@graph":[{"@type":"BreadcrumbList"},{"@type":"Product","name":"Red &amp; Hat"}]}</script>'
	. "<script type='application/ld+json'>[{\"@type\":\"Organization\"}]</script>";
$page = Check::from_html( $html );
check( 1 === $page['count'] && 'Red &amp; Hat' === $page['graph']['name'], 'Product node not found in @graph' );
$page = Check::from_html( $html . '<script type="application/ld+json">{"@type":["Product","Thing"]}</script>' );
check( 2 === $page['count'], 'Second Product graph not counted' );
check( 0 === Check::from_html( '<p>no data</p>' )['count'], 'Phantom graph' );

echo "Product_Graph_Check_Test: all checks passed\n";
