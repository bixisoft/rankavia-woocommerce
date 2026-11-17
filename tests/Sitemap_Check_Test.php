<?php
declare( strict_types=1 );

// Pure sitemap parsing, policy and comparison tests; run with: php tests/Sitemap_Check_Test.php

function __( $text, $domain = '' ) { return $text; }

require dirname( __DIR__ ) . '/src/Seo_Owner.php';
require dirname( __DIR__ ) . '/src/Index_Policy_Check.php';
require dirname( __DIR__ ) . '/src/Sitemap_Check.php';
require dirname( __DIR__ ) . '/src/Sitemap_Policy.php';

use Bixisoft\RankaviaWooCommerce\Index_Policy_Check;
use Bixisoft\RankaviaWooCommerce\Sitemap_Check as Check;
use Bixisoft\RankaviaWooCommerce\Sitemap_Policy as Policy;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

/** @param array<int,array<string,string>> $found */
function codes( array $found ): array {
	return array_map( static function ( $f ) { return $f['level'] . ':' . $f['code']; }, $found );
}

// Parsing sitemap indexes and URL sets, including namespaces, CDATA and entities.
$index = '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
	. '<sitemap><loc>https://shop.test/product-sitemap.xml</loc><lastmod>2026-11-01</lastmod></sitemap>'
	. "<sitemap>\n <loc> https://shop.test/post-sitemap.xml </loc></sitemap></sitemapindex>";
$p = Check::parse( $index );
check( array( 'https://shop.test/product-sitemap.xml', 'https://shop.test/post-sitemap.xml' ) === $p['sitemaps'] && array() === $p['urls'], 'Index not parsed' );
$set = '<urlset xmlns:s="http://www.sitemaps.org/schemas/sitemap/0.9"><s:url><s:loc><![CDATA[https://shop.test/product/a/]]></s:loc></s:url>'
	. '<url><loc>https://shop.test/?p=1&amp;x=2</loc><image:image><image:loc>https://shop.test/a.jpg</image:loc></image:image></url></urlset>';
$p = Check::parse( $set );
check( array( 'https://shop.test/product/a/', 'https://shop.test/?p=1&x=2' ) === $p['urls'], 'URL set not parsed: ' . json_encode( $p ) );
check( Check::wanted( 'https://shop.test/product-sitemap2.xml' ) && Check::wanted( 'https://shop.test/wp-sitemap-taxonomies-product_cat-1.xml' ) && Check::wanted( 'https://shop.test/page-sitemap.xml' ), 'Wanted sitemap skipped' );
check( ! Check::wanted( 'https://shop.test/post-sitemap.xml' ) && ! Check::wanted( 'https://shop.test/author-sitemap.xml' ), 'Unwanted sitemap followed' );
check( Index_Policy_Check::normalize( 'HTTPS://Shop.test/product/a' ) === Index_Policy_Check::normalize( 'https://shop.test/product/a/' ), 'Normalization' );

// Comparison.
$facts = array(
	'url'        => 'https://shop.test/product/a/',
	'canonical'  => '',
	'noindex'    => false,
	'stock'      => 'InStock',
	'visibility' => 'visible',
	'password'   => false,
	'empty'      => false,
);
check( array() === Check::evaluate( $facts, true ), 'Listed product flagged' );
check( array( 'problem:missing' ) === codes( Check::evaluate( $facts, false ) ), 'Missing product' );
check( array( 'problem:missing_out_of_stock' ) === codes( Check::evaluate( array( 'stock' => 'OutOfStock' ) + $facts, false ) ), 'Out of stock missing' );
check( array( 'note:missing_empty' ) === codes( Check::evaluate( array( 'empty' => true ) + $facts, false ) ), 'Empty category' );
check( array() === Check::evaluate( array( 'noindex' => true ) + $facts, false ), 'Noindex left out flagged' );
check( array( 'problem:listed_noindex' ) === codes( Check::evaluate( array( 'noindex' => true ) + $facts, true ) ), 'Noindex listed' );
check( array( 'note:listed_canonical' ) === codes( Check::evaluate( array( 'canonical' => 'https://shop.test/b/' ) + $facts, true ) ), 'Canonical listed' );
check( array() === Check::evaluate( array( 'canonical' => 'https://shop.test/b/' ) + $facts, false ), 'Canonical left out flagged' );
check( array( 'note:listed_password' ) === codes( Check::evaluate( array( 'password' => true ) + $facts, true ) ), 'Password listed' );
check( ! Check::expected( array( 'password' => true ) + $facts ) && Check::expected( array( 'noindex' => null ) + $facts ), 'Expected rule' );

// Policy settings.
$d = Policy::clean( array() );
check( 'listed' === $d['categories'] && array() === $d['exclude'] && true === $d['store_pages'], 'Defaults' );
$c = Policy::clean( array( 'categories' => 'none', 'exclude' => array( '7', 3, 'x', -1, 7 ), 'store_pages' => false ) );
check( 'none' === $c['categories'] && array( 3, 7 ) === $c['exclude'] && false === $c['store_pages'], 'Cleaning: ' . json_encode( $c ) );
check( 'listed' === Policy::clean( array( 'categories' => 'everything' ) )['categories'], 'Unknown mode kept' );

echo "Sitemap_Check_Test: all checks passed\n";
