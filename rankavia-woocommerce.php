<?php
/**
 * Plugin Name:          Rankavia for WooCommerce
 * Plugin URI:           https://rankavia.com/products/rankavia-woocommerce/
 * Description:          Product and category SEO titles and descriptions with undo, plus optional brand and GTIN additions to WooCommerce structured data.
 * Version:              0.2.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Author:               Bixisoft
 * Author URI:           https://bixisoft.com/
 * License:              GPLv2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          rankavia-woocommerce
 * WC requires at least: 8.0
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'Bixisoft\\RankaviaWooCommerce\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		( new \Bixisoft\RankaviaWooCommerce\Product_Module() )->boot();

		$store = new \Bixisoft\RankaviaWooCommerce\Metadata_Store();
		$owner = \Bixisoft\RankaviaWooCommerce\Seo_Owner::detect();
		( new \Bixisoft\RankaviaWooCommerce\Product_Faq( $owner ) )->boot();
		$policy = new \Bixisoft\RankaviaWooCommerce\Sitemap_Policy( $owner );
		$policy->boot();
		if ( \Bixisoft\RankaviaWooCommerce\Seo_Owner::SELF === $owner->kind() ) {
			( new \Bixisoft\RankaviaWooCommerce\Metadata_Output( $store ) )->boot();
		}
		if ( is_admin() ) {
			( new \Bixisoft\RankaviaWooCommerce\Metadata_Page( $store, $owner, new \Bixisoft\RankaviaWooCommerce\Product_Graph_Check(), new \Bixisoft\RankaviaWooCommerce\Index_Policy_Check( $owner ), new \Bixisoft\RankaviaWooCommerce\Image_Alt_Check(), new \Bixisoft\RankaviaWooCommerce\Sitemap_Check( $owner ), $policy ) )->boot();
		}
	}
);
