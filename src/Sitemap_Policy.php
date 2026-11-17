<?php
/**
 * Explicit product category and store page policy for WordPress' own XML sitemaps.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Applies only while WordPress' sitemaps are served (no SEO plugin, or Rankavia SEO, which shapes
 * the same sitemaps). Another SEO plugin's sitemap is left alone.
 */
final class Sitemap_Policy {
	public const OPTION = 'rankavia_wc_sitemap';

	/**
	 * Output owner.
	 *
	 * @var Seo_Owner
	 */
	private Seo_Owner $owner;

	/**
	 * Policy.
	 *
	 * @param Seo_Owner $owner Output owner.
	 */
	public function __construct( Seo_Owner $owner ) {
		$this->owner = $owner;
	}

	/**
	 * Whether this policy shapes the sitemap on this site.
	 *
	 * @return bool
	 */
	public function applies(): bool {
		return Seo_Owner::OTHER !== $this->owner->kind();
	}

	/** Register sitemap filters when WordPress' sitemaps are in use. */
	public function boot(): void {
		if ( ! $this->applies() ) {
			return;
		}
		add_filter( 'wp_sitemaps_taxonomies', array( $this, 'taxonomies' ), 20 );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( $this, 'term_args' ), 20, 2 );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'post_args' ), 20, 2 );
	}

	/**
	 * Saved settings with defaults.
	 *
	 * @return array{categories:string,exclude:array<int,int>,store_pages:bool}
	 */
	public static function settings(): array {
		$saved = get_option( self::OPTION );
		return self::clean( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * Settings from any input, with defaults: categories with products listed, store pages left out.
	 *
	 * @param array<string,mixed> $raw Input.
	 * @return array{categories:string,exclude:array<int,int>,store_pages:bool}
	 */
	public static function clean( array $raw ): array {
		$exclude = array();
		foreach ( is_array( $raw['exclude'] ?? null ) ? $raw['exclude'] : array() as $id ) {
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$exclude[] = (int) $id;
			}
		}
		$exclude = array_values( array_unique( $exclude ) );
		sort( $exclude );
		return array(
			'categories'  => in_array( $raw['categories'] ?? '', array( 'listed', 'none' ), true ) ? (string) $raw['categories'] : 'listed',
			'exclude'     => array_slice( $exclude, 0, 500 ),
			'store_pages' => ! array_key_exists( 'store_pages', $raw ) || ! empty( $raw['store_pages'] ),
		);
	}

	/**
	 * Remove product categories from the sitemap when the policy says none. With Rankavia SEO,
	 * its own sitemap settings decide which taxonomies are listed.
	 *
	 * @param mixed $taxonomies Taxonomy objects keyed by name.
	 * @return mixed
	 */
	public function taxonomies( $taxonomies ) {
		if ( is_array( $taxonomies ) && Seo_Owner::SELF === $this->owner->kind() && 'none' === self::settings()['categories'] ) {
			unset( $taxonomies['product_cat'] );
		}
		return $taxonomies;
	}

	/**
	 * Leave excluded product categories out.
	 *
	 * @param mixed  $args     Term query arguments.
	 * @param string $taxonomy Taxonomy.
	 * @return mixed
	 */
	public function term_args( $args, $taxonomy = '' ) {
		$exclude = self::settings()['exclude'];
		if ( ! is_array( $args ) || 'product_cat' !== $taxonomy || array() === $exclude ) {
			return $args;
		}
		$existing        = isset( $args['exclude'] ) ? wp_parse_id_list( $args['exclude'] ) : array();
		$args['exclude'] = array_values( array_unique( array_merge( $existing, $exclude ) ) );
		return $args;
	}

	/**
	 * Leave cart, checkout and account pages out of the pages sitemap. WooCommerce sets them to
	 * noindex, so listing them sends search engines mixed signals.
	 *
	 * @param mixed  $args      Post query arguments.
	 * @param string $post_type Post type.
	 * @return mixed
	 */
	public function post_args( $args, $post_type = '' ) {
		if ( ! is_array( $args ) || 'page' !== $post_type || ! self::settings()['store_pages'] ) {
			return $args;
		}
		$ids = self::store_page_ids();
		if ( array() !== $ids ) {
			$existing             = isset( $args['post__not_in'] ) ? wp_parse_id_list( $args['post__not_in'] ) : array();
			$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $ids ) ) );
		}
		return $args;
	}

	/**
	 * Cart, checkout and account page IDs.
	 *
	 * @return array<int,int>
	 */
	public static function store_page_ids(): array {
		$ids = array();
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $key ) {
			$id = (int) wc_get_page_id( $key );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}
}
