<?php
/**
 * Uninstall: remove the change history and sitemap settings. Titles, descriptions, brands and GTINs stay with the
 * products and categories, because Rankavia SEO and the storefront may still use them.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'rankavia_wc_meta_journal' );
delete_option( 'rankavia_wc_sitemap' );
