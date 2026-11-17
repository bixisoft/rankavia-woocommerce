<?php
declare( strict_types=1 );

// Minimal WordPress stand-ins; run with: php tests/Metadata_Store_Test.php

final class WP_Term {
	public int $term_id = 0;
}

$GLOBALS['meta']    = array( 'post' => array(), 'term' => array() );
$GLOBALS['options'] = array();
$GLOBALS['can']     = true;
$GLOBALS['view']    = '';
$GLOBALS['tags']    = array( 'post' => 0, 'term' => 0 );

function get_post_type( $id ) { return in_array( $id, array( 7, 8 ), true ) ? 'product' : 'post'; }
function get_term( $id, $taxonomy ) { if ( 30 !== $id ) { return null; } $term = new WP_Term(); $term->term_id = 30; return $term; }
function current_user_can( $cap, $id = 0 ): bool { return $GLOBALS['can']; }
function metadata_exists( $type, $id, $key ): bool { return isset( $GLOBALS['meta'][ $type ][ $id ][ $key ] ); }
function get_metadata( $type, $id, $key, $single ) { return $GLOBALS['meta'][ $type ][ $id ][ $key ] ?? ''; }
function update_metadata( $type, $id, $key, $value ) { $GLOBALS['meta'][ $type ][ $id ][ $key ] = $value; return true; }
function delete_metadata( $type, $id, $key ) { unset( $GLOBALS['meta'][ $type ][ $id ][ $key ] ); return true; }
function wp_slash( $value ) { return $value; }
function sanitize_text_field( $value ): string { return trim( strip_tags( (string) $value ) ); }
function get_current_user_id(): int { return 1; }
function get_option( $key, $default = false ) { return $GLOBALS['options'][ $key ] ?? $default; }
function update_option( $key, $value, $autoload = null ): bool { $GLOBALS['options'][ $key ] = $value; return true; }
function is_product(): bool { return 'product' === $GLOBALS['view']; }
function is_product_category(): bool { return 'product_cat' === $GLOBALS['view']; }
function get_queried_object_id(): int { return 'product' === $GLOBALS['view'] ? 7 : 30; }
function esc_attr( $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function add_filter( ...$args ): void {}
function add_action( ...$args ): void {}

require dirname( __DIR__ ) . '/src/Metadata_Store.php';
require dirname( __DIR__ ) . '/src/Metadata_Output.php';
require dirname( __DIR__ ) . '/src/Seo_Owner.php';

use Bixisoft\RankaviaWooCommerce\Metadata_Output;
use Bixisoft\RankaviaWooCommerce\Metadata_Store;
use Bixisoft\RankaviaWooCommerce\Seo_Owner;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$store = new Metadata_Store();
$rev   = static function ( string $kind, int $id ) use ( $store ): string {
	return Metadata_Store::revision( $store->read( $kind, $id ) );
};

// Reading and cleaning.
check( array( 'title' => null, 'description' => null ) === $store->read( 'product', 7 ), 'Empty product not null' );
$GLOBALS['meta']['post'][7]['_rankavia_description'] = '   ';
check( null === $store->read( 'product', 7 )['description'], 'Blank value counts as a value' );
unset( $GLOBALS['meta']['post'][7]['_rankavia_description'] );
check( 'Red  hat' !== Metadata_Store::clean( "  Red \n hat <b>x</b> " ) && 'Red hat x' === Metadata_Store::clean( "  Red \n hat <b>x</b> " ), 'Clean did not flatten' );
check( null === Metadata_Store::clean( '   ' ), 'Blank not null' );
check( Metadata_Store::revision( array( 'title' => null ) ) !== Metadata_Store::revision( array( 'title' => '' ) ), 'Absent and empty share a revision' );

// Existence and permission.
check( ! $store->exists( 'product', 9 ), 'Nonproduct accepted' );
check( ! $store->exists( 'product_cat', 31 ), 'Missing term accepted' );
check( ! $store->exists( 'post', 7 ), 'Unknown kind accepted' );
$GLOBALS['can'] = false;
check( 'denied' === $store->apply( 'product', 7, array( 'title' => 'X' ), $rev( 'product', 7 ) )['status'], 'No capability allowed' );
check( array() === ( $GLOBALS['meta']['post'][7] ?? array() ), 'Denied save wrote' );
$GLOBALS['can'] = true;

// Save, unchanged, stale.
$saved = $store->apply( 'product', 7, array( 'title' => 'Red hat | Shop', 'description' => 'Warm wool hat.' ), $rev( 'product', 7 ) );
check( 'saved' === $saved['status'] && '' !== $saved['entry'], 'Save failed' );
check( 'Red hat | Shop' === $GLOBALS['meta']['post'][7]['_rankavia_title'], 'Title not stored in the Rankavia SEO key' );
check( 'unchanged' === $store->apply( 'product', 7, array( 'title' => 'Red hat | Shop' ), $rev( 'product', 7 ) )['status'], 'No-op journaled' );
$old = $rev( 'product', 7 );
$GLOBALS['meta']['post'][7]['_rankavia_title'] = 'Changed in the product editor';
check( 'stale' === $store->apply( 'product', 7, array( 'title' => 'Mine' ), $old )['status'], 'Stale form saved' );
check( 'Changed in the product editor' === $GLOBALS['meta']['post'][7]['_rankavia_title'], 'Stale form overwrote' );

// Undo conflicts while a field differs from what the change wrote, and touches nothing.
check( 'conflict' === $store->undo( $saved['entry'] ), 'Conflicting undo applied' );
check( 'Warm wool hat.' === $GLOBALS['meta']['post'][7]['_rankavia_description'], 'Conflicting undo changed a field' );
$GLOBALS['meta']['post'][7]['_rankavia_title'] = 'Red hat | Shop';
check( 'undone' === $store->undo( $saved['entry'] ), 'Undo failed' );
check( ! isset( $GLOBALS['meta']['post'][7]['_rankavia_title'] ) && ! isset( $GLOBALS['meta']['post'][7]['_rankavia_description'] ), 'Undo did not restore absent fields' );
check( 'already' === $store->undo( $saved['entry'] ), 'Undo ran twice' );
check( 'missing' === $store->undo( 'nope' ), 'Unknown entry not reported' );

// Undo restores only the changed field and its earlier value; an empty value removes it.
$GLOBALS['meta']['post'][8] = array( '_rankavia_title' => 'Old', '_rankavia_description' => 'Keep me' );
$change = $store->apply( 'product', 8, array( 'title' => 'New', 'description' => 'Keep me' ), $rev( 'product', 8 ) );
check( array( 'title' ) === array_keys( $store->journal()[0]['changes'] ), 'Unchanged field journaled' );
$GLOBALS['meta']['post'][8]['_rankavia_description'] = 'Edited elsewhere';
check( 'undone' === $store->undo( $change['entry'] ), 'Unrelated edit blocked undo' );
check( 'Old' === $GLOBALS['meta']['post'][8]['_rankavia_title'] && 'Edited elsewhere' === $GLOBALS['meta']['post'][8]['_rankavia_description'], 'Undo touched an unrelated field' );
$store->apply( 'product', 8, array( 'description' => null ), $rev( 'product', 8 ) );
check( ! isset( $GLOBALS['meta']['post'][8]['_rankavia_description'] ), 'Null did not remove the value' );

// Categories use term meta.
$cat = $store->apply( 'product_cat', 30, array( 'description' => 'All our hats.' ), $rev( 'product_cat', 30 ) );
check( 'saved' === $cat['status'] && 'All our hats.' === $GLOBALS['meta']['term'][30]['_rankavia_description'], 'Category not saved to term meta' );

// The journal is bounded and ignores malformed entries.
$GLOBALS['options']['rankavia_wc_meta_journal'][] = array( 'id' => 'x', 'kind' => 'post', 'object' => 1, 'changes' => array() );
check( 4 === count( $store->journal() ), 'Malformed entry kept' );
for ( $i = 0; $i < 120; $i++ ) {
	$store->apply( 'product', 7, array( 'title' => 'T' . $i ), $rev( 'product', 7 ) );
}
check( Metadata_Store::JOURNAL_MAX === count( $store->journal() ), 'Journal not bounded' );

// Storefront output prints plain values only on product and category views.
$output = new Metadata_Output( $store );
$GLOBALS['view'] = 'product';
check( 'T119' === $output->document_title( 'Core' ), 'Product title not used' );
$GLOBALS['meta']['post'][7]['_rankavia_title'] = 'Hat %sep% %sitename%';
check( 'Core' === $output->document_title( 'Core' ), 'Variables printed without Rankavia SEO' );
$GLOBALS['view'] = 'product_cat';
ob_start();
$output->print_description();
check( '<meta name="description" content="All our hats." />' . "\n" === ob_get_clean(), 'Category description not printed' );
$GLOBALS['view'] = '';
ob_start();
$output->print_description();
check( '' === ob_get_clean(), 'Printed outside products and categories' );

// Ownership.
check( Seo_Owner::SELF === Seo_Owner::detect()->kind(), 'No SEO plugin is not self' );
check( ( new Seo_Owner( Seo_Owner::RANKAVIA, 'Rankavia SEO', false ) )->renders( 'product' ), 'Rankavia products not rendered' );
check( ! ( new Seo_Owner( Seo_Owner::RANKAVIA, 'Rankavia SEO', false ) )->renders( 'product_cat' ), 'Old Rankavia said to render categories' );
check( ! ( new Seo_Owner( Seo_Owner::OTHER, 'Yoast SEO', false ) )->renders( 'product' ), 'Other owner rendered' );
define( 'RANKAVIA_VERSION', 'test' );
check( Seo_Owner::RANKAVIA === Seo_Owner::detect()->kind(), 'Rankavia SEO not detected' );
define( 'WPSEO_VERSION', 'test' );
$other = Seo_Owner::detect();
check( Seo_Owner::OTHER === $other->kind() && 'Yoast SEO' === $other->name(), 'Other SEO plugin does not win' );

echo "Metadata store checks passed\n";
