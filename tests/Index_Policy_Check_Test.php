<?php
declare( strict_types=1 );

// Pure parsing and comparison tests; run with: php tests/Index_Policy_Check_Test.php

function __( $text, $domain = '' ) { return $text; }

require dirname( __DIR__ ) . '/src/Seo_Owner.php';
require dirname( __DIR__ ) . '/src/Index_Policy_Check.php';

use Bixisoft\RankaviaWooCommerce\Index_Policy_Check as Check;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

/** @param array<int,array<string,string>> $found */
function codes( array $found ): array {
	return array_map( static function ( $f ) { return $f['level'] . ':' . $f['code']; }, $found );
}

$url   = 'https://shop.test/product/red-hat/';
$facts = array(
	'url'        => $url,
	'canonical'  => '',
	'noindex'    => false,
	'stock'      => 'InStock',
	'visibility' => 'visible',
	'password'   => false,
	'empty'      => false,
);

// Settings.
check( array() === Check::evaluate( $facts ), 'Plain product flagged' );
check( array() === Check::evaluate( array( 'canonical' => 'HTTPS://Shop.test/product/red-hat' ) + $facts ), 'Same canonical flagged' );
check( array( 'note:canonical_elsewhere' ) === codes( Check::evaluate( array( 'canonical' => 'https://shop.test/product/blue-hat/' ) + $facts ) ), 'Canonical elsewhere' );
check( array( 'problem:canonical_other_site' ) === codes( Check::evaluate( array( 'canonical' => 'https://other.test/red-hat/' ) + $facts ) ), 'Canonical other site' );
check( array( 'problem:canonical_invalid' ) === codes( Check::evaluate( array( 'canonical' => '/relative' ) + $facts ) ), 'Invalid canonical' );
check( array( 'note:noindex' ) === codes( Check::evaluate( array( 'noindex' => true ) + $facts ) ), 'Noindex note' );
check( array( 'note:noindex_out_of_stock' ) === codes( Check::evaluate( array( 'noindex' => true, 'stock' => 'OutOfStock' ) + $facts ) ), 'Out of stock noindex' );
check( array() === Check::evaluate( array( 'stock' => 'OutOfStock' ) + $facts ), 'Out of stock alone flagged' );
check( array( 'note:canonical_elsewhere', 'problem:noindex_canonical', 'note:noindex' ) === codes( Check::evaluate( array( 'noindex' => true, 'canonical' => 'https://shop.test/x/' ) + $facts ) ), 'Mixed signals' );
check( array( 'note:hidden_indexable' ) === codes( Check::evaluate( array( 'visibility' => 'hidden' ) + $facts ) ), 'Hidden but indexable' );
check( array( 'note:noindex' ) === codes( Check::evaluate( array( 'visibility' => 'hidden', 'noindex' => true ) + $facts ) ), 'Hidden and noindex' );
check( array( 'note:password' ) === codes( Check::evaluate( array( 'password' => true ) + $facts ) ), 'Password' );
check( array( 'note:empty_category' ) === codes( Check::evaluate( array( 'empty' => true ) + $facts ) ), 'Empty category' );
check( array() === Check::evaluate( array( 'noindex' => null ) + $facts ), 'Unknown noindex flagged' );

// Page signals.
$html = '<head><META name="Robots" content="noindex, follow"><meta name="googlebot" content=\'nosnippet\'>'
	. '<meta property="og:title" content="noindex"><link rel="canonical" href="https://shop.test/product/red-hat/?a=1&amp;b=2" />'
	. '<link rel="alternate canonical" href=https://shop.test/x/></head>';
$d = Check::directives( $html, array() );
check( $d['noindex'] && ! $d['nofollow'] && 2 === count( $d['found'] ), 'Meta robots not read' );
check( ! Check::directives( '<meta name="robots" content="index, follow">', array() )['noindex'], 'Index read as noindex' );
check( Check::directives( '', array( 'none' ) )['nofollow'], 'X-Robots-Tag none' );
check( Check::directives( '', array( "googlebot: noindex\nbingbot: nofollow" ) )['noindex'], 'Googlebot header' );
check( ! Check::directives( '', array( 'bingbot: noindex' ) )['noindex'], 'Other bot header applied' );
check( ! Check::directives( '', array( 'unavailable_after: 25 Jun 2030 15:00:00 PST' ) )['noindex'], 'unavailable_after misread' );
check( array( 'https://shop.test/product/red-hat/?a=1&b=2', 'https://shop.test/x/' ) === Check::canonicals( $html ), 'Canonicals not read' );

// Live comparison.
$live = array(
	'status'     => 'ok',
	'location'   => '',
	'noindex'    => false,
	'found'      => array(),
	'canonicals' => array( $url ),
	'robots'     => array( 'status' => 'ok', 'allowed' => true, 'rule' => '' ),
);
check( array() === Check::evaluate_live( $live, $facts ), 'Clean page flagged' );
check( array( 'problem:live_canonicals' ) === codes( Check::evaluate_live( array( 'canonicals' => array( $url, 'https://shop.test/a/' ) ) + $live, $facts ) ), 'Two canonicals' );
check( array() === Check::evaluate_live( array( 'canonicals' => array( $url, 'https://shop.test/product/red-hat' ) ) + $live, $facts ), 'Repeated canonical counted twice' );
check( array( 'problem:live_canonical_differs' ) === codes( Check::evaluate_live( array( 'canonicals' => array( 'https://shop.test/a/' ) ) + $live, $facts ) ), 'Different canonical' );
check( array( 'note:live_no_canonical' ) === codes( Check::evaluate_live( array( 'canonicals' => array() ) + $live, $facts ) ), 'No canonical' );
check( array( 'problem:live_noindex_out_of_stock' ) === codes( Check::evaluate_live( array( 'noindex' => true, 'found' => array( 'meta robots: noindex' ) ) + $live, array( 'stock' => 'OutOfStock' ) + $facts ) ), 'Out-of-stock noindex rule' );
check( array( 'note:live_noindex_seen' ) === codes( Check::evaluate_live( array( 'noindex' => true ) + $live, array( 'noindex' => null ) + $facts ) ), 'Unknown setting' );
check( array( 'note:live_indexable' ) === codes( Check::evaluate_live( $live, array( 'noindex' => true ) + $facts ) ), 'Setting not printed' );
check( array( 'problem:robots_blocked' ) === codes( Check::evaluate_live( array( 'robots' => array( 'status' => 'ok', 'allowed' => false, 'rule' => 'Disallow: /product/' ) ) + $live, $facts ) ), 'Robots block' );
check( array( 'note:redirected' ) === codes( Check::evaluate_live( array( 'status' => 'redirect', 'location' => 'https://shop.test/' ) + $live, $facts ) ), 'Redirect' );

check( array( 'note:redirected' ) === codes( Check::evaluate_live( array( 'location' => 'https://shop.test/product/red-hat/?ref=old' ) + $live, $facts ) ), 'Followed redirect not noted' );

// Store pages.
check( array( 'note:redirected' ) === codes( Check::evaluate_store_live( 'checkout', array( 'noindex' => true, 'location' => 'https://shop.test/cart/' ) + $live ) ), 'Checkout to cart' );
check( array( 'problem:store_live_indexable' ) === codes( Check::evaluate_store_live( 'cart', $live ) ), 'Indexable cart' );
check( array() === Check::evaluate_store_live( 'checkout', array( 'noindex' => true ) + $live ), 'Noindexed checkout flagged' );
check( array( 'note:redirected' ) === codes( Check::evaluate_store_live( 'checkout', array( 'status' => 'redirect', 'location' => '/cart/' ) + $live ) ), 'Checkout redirect' );
check( array( 'problem:shop_live_noindex' ) === codes( Check::evaluate_store_live( 'shop', array( 'noindex' => true ) + $live ) ), 'Shop noindex' );
check( array() === Check::evaluate_store_live( 'shop', $live ), 'Clean shop flagged' );

// Robots.txt.
$txt = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /*?add-to-cart=\nDisallow: /cart\$\n\n# Google\nUser-agent: Bingbot\nDisallow: /\n";
check( Check::robots_rule( $txt, '/product/red-hat/' )['allowed'], 'Product blocked' );
check( ! Check::robots_rule( $txt, '/wp-admin/options.php' )['allowed'], 'Admin allowed' );
check( Check::robots_rule( $txt, '/wp-admin/admin-ajax.php' )['allowed'], 'Longer Allow lost' );
check( 'Disallow: /*?add-to-cart=' === Check::robots_rule( $txt, '/shop/?add-to-cart=5' )['rule'], 'Wildcard' );
check( ! Check::robots_rule( $txt, '/cart' )['allowed'] && Check::robots_rule( $txt, '/cart/x' )['allowed'], 'End anchor' );
$google = "User-agent: *\nDisallow: /\n\nUser-agent: Googlebot\nUser-agent: Other\nDisallow: /private/\n";
check( Check::robots_rule( $google, '/product/a/' )['allowed'], 'Googlebot group not preferred' );
check( ! Check::robots_rule( $google, '/private/a' )['allowed'], 'Googlebot group rule' );
check( Check::robots_rule( "User-agent: *\nDisallow:\n", '/x' )['allowed'], 'Empty disallow blocks' );
check( Check::robots_rule( '', '/x' )['allowed'], 'Empty file blocks' );
check( ! Check::robots_rule( "User-agent: *\r\nDisallow: /x # private\r\n", '/x/y' )['allowed'], 'CRLF or comment not handled' );

// Messages.
check( false !== strpos( Check::message( array( 'level' => 'problem', 'code' => 'robots_blocked', 'value' => 'Disallow: /x' ) ), 'Disallow: /x' ), 'Message value' );

echo "Index_Policy_Check_Test: all checks passed\n";
