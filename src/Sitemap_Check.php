<?php
/**
 * Read-only audit of which products, product categories and store pages the XML sitemap lists.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * With WordPress' own sitemaps (no SEO plugin, or Rankavia SEO), the URL list comes straight from
 * WordPress' sitemap providers, so it is exact and needs no request. With another SEO plugin, its
 * sitemap files are read from this site on request.
 */
final class Sitemap_Check {
	public const PROBLEM   = 'problem';
	public const NOTE      = 'note';
	private const PAGES    = 50;
	private const FETCHES  = 30;
	private const MAX_BODY = 10485760;

	/**
	 * Sitemap index path per SEO owner.
	 *
	 * @var array<string,string>
	 */
	private const INDEX = array(
		'self'     => '/wp-sitemap.xml',
		'rankavia' => '/wp-sitemap.xml',
		'yoast'    => '/sitemap_index.xml',
		'rankmath' => '/sitemap_index.xml',
		'aioseo'   => '/sitemap.xml',
		'seopress' => '/sitemaps.xml',
		'tsf'      => '/sitemap.xml',
	);

	/**
	 * Output owner.
	 *
	 * @var Seo_Owner
	 */
	private Seo_Owner $owner;

	/**
	 * Check.
	 *
	 * @param Seo_Owner $owner Output owner.
	 */
	public function __construct( Seo_Owner $owner ) {
		$this->owner = $owner;
	}

	/**
	 * Whether the URL list can be read from WordPress without a request.
	 *
	 * @return bool
	 */
	public function local(): bool {
		return Seo_Owner::OTHER !== $this->owner->kind();
	}

	/**
	 * Sitemap index address on this site.
	 *
	 * @return string
	 */
	public function index_url(): string {
		return home_url( self::INDEX[ $this->owner->id() ] ?? '/sitemap.xml' );
	}

	/**
	 * Child sitemaps and page URLs in a sitemap or sitemap index.
	 *
	 * @param string $xml Sitemap XML.
	 * @return array{sitemaps:array<int,string>,urls:array<int,string>}
	 */
	public static function parse( string $xml ): array {
		$found = array(
			'sitemaps' => array(),
			'urls'     => array(),
		);
		foreach ( array(
			'sitemaps' => 'sitemap',
			'urls'     => 'url',
		) as $key => $tag ) {
			if ( preg_match_all( '#<(?:[a-z0-9]+:)?' . $tag . '\b[^>]*>(.*?)</(?:[a-z0-9]+:)?' . $tag . '>#is', $xml, $blocks ) ) {
				foreach ( $blocks[1] as $block ) {
					if ( preg_match( '#<(?:[a-z0-9]+:)?loc\b[^>]*>\s*(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?\s*</(?:[a-z0-9]+:)?loc>#is', $block, $loc ) ) {
						$found[ $key ][] = html_entity_decode( trim( $loc[1] ), ENT_QUOTES | ENT_XML1, 'UTF-8' );
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Whether a child sitemap may list products, product categories or pages.
	 *
	 * @param string $loc Child sitemap URL.
	 * @return bool
	 */
	public static function wanted( string $loc ): bool {
		$path = strtolower( (string) parse_url( $loc, PHP_URL_PATH ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, tested without WordPress.
		return 1 === preg_match( '#(product|page)#', $path );
	}

	/**
	 * Findings for one product or category against the sitemap.
	 *
	 * @param array{url:string,canonical:string,noindex:bool|null,stock:string,visibility:string,password:bool,empty:bool} $facts  Index facts.
	 * @param bool                                                                                                         $listed Whether the sitemap lists its URL.
	 * @return array<int,array{level:string,code:string}>
	 */
	public static function evaluate( array $facts, bool $listed ): array {
		$found = array();
		if ( $listed ) {
			if ( true === $facts['noindex'] ) {
				$found[] = self::finding( self::PROBLEM, 'listed_noindex' );
			}
			if ( '' !== $facts['canonical'] ) {
				$found[] = self::finding( self::NOTE, 'listed_canonical' );
			}
			if ( $facts['password'] ) {
				$found[] = self::finding( self::NOTE, 'listed_password' );
			}
			return $found;
		}
		if ( self::expected( $facts ) ) {
			if ( 'OutOfStock' === $facts['stock'] ) {
				$found[] = self::finding( self::PROBLEM, 'missing_out_of_stock' );
			} elseif ( $facts['empty'] ) {
				$found[] = self::finding( self::NOTE, 'missing_empty' );
			} else {
				$found[] = self::finding( self::PROBLEM, 'missing' );
			}
		}
		return $found;
	}

	/**
	 * Whether a page should be in the sitemap: indexable, its own canonical and not password protected.
	 *
	 * @param array{url:string,canonical:string,noindex:bool|null,stock:string,visibility:string,password:bool,empty:bool} $facts Index facts.
	 * @return bool
	 */
	public static function expected( array $facts ): bool {
		return true !== $facts['noindex'] && '' === $facts['canonical'] && ! $facts['password'];
	}

	/**
	 * Plain-language text for a finding.
	 *
	 * @param array{level:string,code:string} $finding Finding.
	 * @return string
	 */
	public static function message( array $finding ): string {
		$messages = array(
			'missing'              => __( 'Not in the sitemap, although search engines may index it. Search engines can still find it through links, but the sitemap helps them notice new and changed products.', 'rankavia-woocommerce' ),
			'missing_out_of_stock' => __( 'Not in the sitemap and out of stock. Something leaves out-of-stock products out; products that come back in stock are found again more slowly.', 'rankavia-woocommerce' ),
			'missing_empty'        => __( 'Not in the sitemap because the category has no products yet.', 'rankavia-woocommerce' ),
			'listed_noindex'       => __( 'In the sitemap but set to noindex. Search engines get mixed signals; leave noindex pages out of the sitemap.', 'rankavia-woocommerce' ),
			'listed_canonical'     => __( 'In the sitemap, but its canonical URL points to another page. List only the page it points to.', 'rankavia-woocommerce' ),
			'listed_password'      => __( 'In the sitemap but password protected, so search engines only see the password form.', 'rankavia-woocommerce' ),
		);
		return $messages[ $finding['code'] ] ?? $finding['code'];
	}

	/**
	 * Sitemap URLs that may be products, product categories or pages.
	 *
	 * @param bool $fetch Whether to read another SEO plugin's sitemap files now.
	 * @return array{status:string,source:string,urls:array<string,bool>,products:bool|null,categories:bool|null,files:int}
	 */
	public function collect( bool $fetch ): array {
		$result = array(
			'status'     => 'unchecked',
			'source'     => $this->local() ? 'wordpress' : 'live',
			'urls'       => array(),
			'products'   => null,
			'categories' => null,
			'files'      => 0,
		);
		if ( $this->local() ) {
			$server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;
			if ( ! $server instanceof \WP_Sitemaps || ! $server->sitemaps_enabled() ) {
				$result['status'] = 'disabled';
				return $result;
			}
			$posts = $server->registry->get_provider( 'posts' );
			$terms = $server->registry->get_provider( 'taxonomies' );
			foreach ( array(
				array( $posts, 'product', 'products' ),
				array( $terms, 'product_cat', 'categories' ),
				array( $posts, 'page', '' ),
			) as $source ) {
				list( $provider, $subtype, $flag ) = $source;
				$listed                            = $provider instanceof \WP_Sitemaps_Provider && array_key_exists( $subtype, $provider->get_object_subtypes() );
				if ( '' !== $flag ) {
					$result[ $flag ] = $listed;
				}
				if ( ! $listed ) {
					continue;
				}
				$pages = min( self::PAGES, max( 1, (int) $provider->get_max_num_pages( $subtype ) ) );
				for ( $page = 1; $page <= $pages; ++$page ) {
					foreach ( $provider->get_url_list( $page, $subtype ) as $entry ) {
						if ( isset( $entry['loc'] ) ) {
							$result['urls'][ Index_Policy_Check::normalize( (string) $entry['loc'] ) ] = true;
						}
					}
				}
			}
			$result['status'] = 'ok';
			return $result;
		}
		if ( ! $fetch ) {
			return $result;
		}

		$queue = array( $this->index_url() );
		$seen  = array();
		while ( array() !== $queue && $result['files'] < self::FETCHES ) {
			$url          = (string) array_shift( $queue );
			$seen[ $url ] = true;
			$body         = $this->fetch( $url );
			++$result['files'];
			if ( null === $body ) {
				if ( 1 === $result['files'] ) {
					$result['status'] = 'unreachable';
					return $result;
				}
				continue;
			}
			$parsed = self::parse( $body );
			foreach ( $parsed['sitemaps'] as $child ) {
				if ( ! isset( $seen[ $child ] ) && self::wanted( $child ) && self::same_host( $child ) ) {
					$queue[]        = $child;
					$seen[ $child ] = true;
				}
			}
			foreach ( $parsed['urls'] as $loc ) {
				$result['urls'][ Index_Policy_Check::normalize( $loc ) ] = true;
			}
		}
		$result['status'] = array() === $queue ? 'ok' : 'partial';
		return $result;
	}

	/**
	 * GET a sitemap file on this site.
	 *
	 * @param string $url URL.
	 * @return string|null Body, or null when it could not be read.
	 */
	private function fetch( string $url ): ?string {
		if ( ! self::same_host( $url ) ) {
			return null;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'             => 20,
				'redirection'         => 3,
				'limit_response_size' => self::MAX_BODY,
				'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'headers'             => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return (string) wp_remote_retrieve_body( $response );
	}

	/**
	 * Whether a URL is on this site's host.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function same_host( string $url ): bool {
		return strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Finding.
	 *
	 * @param string $level PROBLEM or NOTE.
	 * @param string $code  Code.
	 * @return array{level:string,code:string}
	 */
	private static function finding( string $level, string $code ): array {
		return array(
			'level' => $level,
			'code'  => $code,
		);
	}
}
