<?php
/**
 * Read-only canonical and indexing inspection for products, product categories and store pages.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Reads the indexing settings the active SEO owner keeps for each item and, on request, what a
 * page actually prints. It never changes a setting and never noindexes anything, including
 * products that are out of stock.
 */
final class Index_Policy_Check {
	public const PROBLEM = 'problem';
	public const NOTE    = 'note';

	/**
	 * Store pages that should stay out of search results.
	 *
	 * @var array<int,string>
	 */
	public const EXCLUDED = array( 'cart', 'checkout', 'myaccount', 'search' );

	/**
	 * Output owner.
	 *
	 * @var Seo_Owner
	 */
	private Seo_Owner $owner;

	/**
	 * Robots.txt fetched during this request, keyed by URL.
	 *
	 * @var array<string,array{status:string,body:string}>
	 */
	private array $robots_txt = array();

	/**
	 * Check.
	 *
	 * @param Seo_Owner $owner Output owner.
	 */
	public function __construct( Seo_Owner $owner ) {
		$this->owner = $owner;
	}

	/**
	 * Findings for a product or category from its configured settings.
	 *
	 * @param array{url:string,canonical:string,noindex:bool|null,stock:string,visibility:string,password:bool,empty:bool} $facts Facts.
	 * @return array<int,array{level:string,code:string,value:string}>
	 */
	public static function evaluate( array $facts ): array {
		$found     = array();
		$canonical = $facts['canonical'];
		$elsewhere = false;
		if ( '' !== $canonical ) {
			if ( ! self::is_url( $canonical ) ) {
				$found[] = self::finding( self::PROBLEM, 'canonical_invalid', $canonical );
			} elseif ( self::host( $canonical ) !== self::host( $facts['url'] ) ) {
				$found[]   = self::finding( self::PROBLEM, 'canonical_other_site', $canonical );
				$elsewhere = true;
			} elseif ( ! self::same_url( $canonical, $facts['url'] ) ) {
				$found[]   = self::finding( self::NOTE, 'canonical_elsewhere', $canonical );
				$elsewhere = true;
			}
		}
		if ( true === $facts['noindex'] ) {
			if ( $elsewhere ) {
				$found[] = self::finding( self::PROBLEM, 'noindex_canonical', $canonical );
			}
			$found[] = self::finding( self::NOTE, 'OutOfStock' === $facts['stock'] ? 'noindex_out_of_stock' : 'noindex', '' );
		}
		if ( 'hidden' === $facts['visibility'] && true !== $facts['noindex'] ) {
			$found[] = self::finding( self::NOTE, 'hidden_indexable', '' );
		}
		if ( $facts['password'] ) {
			$found[] = self::finding( self::NOTE, 'password', '' );
		}
		if ( $facts['empty'] && true !== $facts['noindex'] ) {
			$found[] = self::finding( self::NOTE, 'empty_category', '' );
		}
		return $found;
	}

	/**
	 * Findings for what a fetched page printed, compared with its settings.
	 *
	 * @param array<string,mixed>                                                                                          $live  Result of page().
	 * @param array{url:string,canonical:string,noindex:bool|null,stock:string,visibility:string,password:bool,empty:bool} $facts Facts.
	 * @return array<int,array{level:string,code:string,value:string}>
	 */
	public static function evaluate_live( array $live, array $facts ): array {
		$found = array();
		if ( 'redirect' === $live['status'] ) {
			return array( self::finding( self::NOTE, 'redirected', (string) $live['location'] ) );
		}
		if ( '' !== (string) $live['location'] ) {
			$found[] = self::finding( self::NOTE, 'redirected', (string) $live['location'] );
		}
		$expected = '' !== $facts['canonical'] && self::is_url( $facts['canonical'] ) ? $facts['canonical'] : $facts['url'];
		$links    = array();
		foreach ( (array) $live['canonicals'] as $link ) {
			$known = false;
			foreach ( $links as $seen ) {
				$known = $known || self::same_url( $seen, (string) $link );
			}
			if ( ! $known ) {
				$links[] = (string) $link;
			}
		}
		if ( count( $links ) > 1 ) {
			$found[] = self::finding( self::PROBLEM, 'live_canonicals', implode( ' | ', $links ) );
		} elseif ( 1 === count( $links ) && ! self::same_url( $links[0], $expected ) ) {
			$found[] = self::finding( self::PROBLEM, 'live_canonical_differs', $links[0] );
		} elseif ( array() === $links ) {
			$found[] = self::finding( self::NOTE, 'live_no_canonical', '' );
		}
		$seen = implode( '; ', (array) $live['found'] );
		if ( ! empty( $live['noindex'] ) && false === $facts['noindex'] ) {
			$found[] = self::finding( self::PROBLEM, 'OutOfStock' === $facts['stock'] ? 'live_noindex_out_of_stock' : 'live_noindex', $seen );
		} elseif ( ! empty( $live['noindex'] ) && null === $facts['noindex'] ) {
			$found[] = self::finding( self::NOTE, 'live_noindex_seen', $seen );
		} elseif ( empty( $live['noindex'] ) && true === $facts['noindex'] ) {
			$found[] = self::finding( self::NOTE, 'live_indexable', '' );
		}
		if ( isset( $live['robots']['allowed'] ) && false === $live['robots']['allowed'] ) {
			$found[] = self::finding( self::PROBLEM, 'robots_blocked', (string) $live['robots']['rule'] );
		}
		return $found;
	}

	/**
	 * Findings for what a fetched store page printed.
	 *
	 * @param string              $key  Store page key.
	 * @param array<string,mixed> $live Result of page().
	 * @return array<int,array{level:string,code:string,value:string}>
	 */
	public static function evaluate_store_live( string $key, array $live ): array {
		if ( 'ok' !== $live['status'] ) {
			return 'redirect' === $live['status'] ? array( self::finding( self::NOTE, 'redirected', (string) $live['location'] ) ) : array();
		}
		$found = array();
		if ( '' !== (string) $live['location'] ) {
			$found[] = self::finding( self::NOTE, 'redirected', (string) $live['location'] );
		}
		if ( in_array( $key, self::EXCLUDED, true ) ) {
			if ( empty( $live['noindex'] ) ) {
				$found[] = self::finding( self::PROBLEM, 'store_live_indexable', '' );
			}
			return $found;
		}
		if ( ! empty( $live['noindex'] ) ) {
			$found[] = self::finding( self::PROBLEM, 'shop_live_noindex', '' );
		}
		if ( isset( $live['robots']['allowed'] ) && false === $live['robots']['allowed'] ) {
			$found[] = self::finding( self::PROBLEM, 'robots_blocked', (string) $live['robots']['rule'] );
		}
		return $found;
	}

	/**
	 * Robots directives in a page's meta tags and X-Robots-Tag headers, for all crawlers or Googlebot.
	 *
	 * @param string                  $html    Page HTML.
	 * @param array<int|string,mixed> $headers X-Robots-Tag header values.
	 * @return array{noindex:bool,nofollow:bool,found:array<int,string>}
	 */
	public static function directives( string $html, array $headers ): array {
		$sources = array();
		if ( preg_match_all( '#<meta\b[^>]*>#i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$attrs = self::attributes( $tag );
				$name  = strtolower( $attrs['name'] ?? '' );
				if ( in_array( $name, array( 'robots', 'googlebot' ), true ) && isset( $attrs['content'] ) ) {
					$sources[] = array( 'meta ' . $name, $attrs['content'] );
				}
			}
		}
		foreach ( $headers as $header ) {
			foreach ( explode( "\n", (string) $header ) as $value ) {
				$value = trim( $value );
				if ( preg_match( '#^([a-z0-9_-]+)\s*:\s*(.*)$#i', $value, $agent ) && ! preg_match( '#^(no|none|index|follow|all|max-|unavailable)#i', $value ) ) {
					if ( 'googlebot' !== strtolower( $agent[1] ) ) {
						continue;
					}
					$value = $agent[2];
				}
				if ( '' !== $value ) {
					$sources[] = array( 'X-Robots-Tag', $value );
				}
			}
		}
		$result = array(
			'noindex'  => false,
			'nofollow' => false,
			'found'    => array(),
		);
		foreach ( $sources as $source ) {
			$tokens = array_map( 'trim', explode( ',', strtolower( $source[1] ) ) );
			if ( in_array( 'noindex', $tokens, true ) || in_array( 'none', $tokens, true ) ) {
				$result['noindex'] = true;
			}
			if ( in_array( 'nofollow', $tokens, true ) || in_array( 'none', $tokens, true ) ) {
				$result['nofollow'] = true;
			}
			$result['found'][] = $source[0] . ': ' . trim( $source[1] );
		}
		return $result;
	}

	/**
	 * Canonical link targets in a page.
	 *
	 * @param string $html Page HTML.
	 * @return array<int,string>
	 */
	public static function canonicals( string $html ): array {
		$links = array();
		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$attrs = self::attributes( $tag );
				$rel   = preg_split( '#\s+#', strtolower( trim( $attrs['rel'] ?? '' ) ) );
				if ( is_array( $rel ) && in_array( 'canonical', $rel, true ) && isset( $attrs['href'] ) ) {
					$links[] = html_entity_decode( trim( $attrs['href'] ), ENT_QUOTES );
				}
			}
		}
		return $links;
	}

	/**
	 * Whether robots.txt lets Googlebot crawl a path: the Googlebot group, else the * group,
	 * longest matching rule wins and Allow wins a tie.
	 *
	 * @param string $txt  Robots.txt body.
	 * @param string $path Path with query string.
	 * @return array{allowed:bool,rule:string}
	 */
	public static function robots_rule( string $txt, string $path ): array {
		$groups = array();
		$agents = array();
		$rules  = array();
		$open   = false;
		$lines  = preg_split( '#\r\n|\r|\n#', $txt );
		foreach ( is_array( $lines ) ? $lines : array() as $line ) {
			$line = trim( (string) preg_replace( '/#.*$/', '', $line ) );
			if ( false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $field, $value ) = array_map( 'trim', explode( ':', $line, 2 ) );
			$field                 = strtolower( $field );
			if ( 'user-agent' === $field ) {
				if ( $open ) {
					foreach ( $agents as $agent ) {
						$groups[ $agent ] = array_merge( $groups[ $agent ] ?? array(), $rules );
					}
					$agents = array();
					$rules  = array();
					$open   = false;
				}
				$agents[] = strtolower( $value );
			} elseif ( 'allow' === $field || 'disallow' === $field ) {
				$open    = true;
				$rules[] = array( $field, $value );
			}
		}
		foreach ( $agents as $agent ) {
			$groups[ $agent ] = array_merge( $groups[ $agent ] ?? array(), $rules );
		}
		$chosen = $groups['googlebot'] ?? ( $groups['*'] ?? array() );
		$best   = array(
			'allowed' => true,
			'rule'    => '',
		);
		$length = -1;
		foreach ( $chosen as $rule ) {
			if ( '' === $rule[1] ) {
				continue;
			}
			$pattern = preg_quote( $rule[1], '#' );
			$pattern = str_replace( '\*', '.*', $pattern );
			if ( '\$' === substr( $pattern, -2 ) ) {
				$pattern = substr( $pattern, 0, -2 ) . '$';
			}
			if ( ! preg_match( '#^' . $pattern . '#', $path ) ) {
				continue;
			}
			$size = strlen( $rule[1] );
			if ( $size > $length || ( $size === $length && 'allow' === $rule[0] ) ) {
				$length = $size;
				$best   = array(
					'allowed' => 'allow' === $rule[0],
					'rule'    => ( 'allow' === $rule[0] ? 'Allow: ' : 'Disallow: ' ) . $rule[1],
				);
			}
		}
		return $best;
	}

	/**
	 * Whether two URLs name the same page: scheme and host case, default ports and a trailing slash are ignored.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 * @return bool
	 */
	public static function same_url( string $a, string $b ): bool {
		return self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * Plain-language text for a finding.
	 *
	 * @param array{level:string,code:string,value:string} $finding Finding.
	 * @return string
	 */
	public static function message( array $finding ): string {
		$messages = array(
			/* translators: %s: URL set as canonical. */
			'canonical_invalid'         => __( 'The canonical URL is not a valid address: %s', 'rankavia-woocommerce' ),
			/* translators: %s: URL set as canonical. */
			'canonical_other_site'      => __( 'The canonical URL points to another site, so search engines may show that site instead of this page: %s', 'rankavia-woocommerce' ),
			/* translators: %s: URL set as canonical. */
			'canonical_elsewhere'       => __( 'The canonical URL points to another page, so search engines will usually show that page instead. Keep it only if this page duplicates it: %s', 'rankavia-woocommerce' ),
			/* translators: %s: URL set as canonical. */
			'noindex_canonical'         => __( 'Set to noindex and also to a canonical URL elsewhere. These signals conflict; choose one: %s', 'rankavia-woocommerce' ),
			'noindex'                   => __( 'Set to noindex, so search engines will drop this page.', 'rankavia-woocommerce' ),
			'noindex_out_of_stock'      => __( 'Out of stock and set to noindex. If it will come back in stock, keeping it indexed usually keeps its search traffic; this check never noindexes out-of-stock products.', 'rankavia-woocommerce' ),
			'hidden_indexable'          => __( 'Hidden from the shop and search results on your site, but search engines can still index its page.', 'rankavia-woocommerce' ),
			'password'                  => __( 'Password protected: search engines can only see the password form.', 'rankavia-woocommerce' ),
			'empty_category'            => __( 'No products in this category, so its page has little to show. Add products, or consider noindex until it has some.', 'rankavia-woocommerce' ),
			/* translators: %s: redirect target. */
			'redirected'                => __( 'The page redirects to: %s', 'rankavia-woocommerce' ),
			/* translators: %s: canonical URLs found. */
			'live_canonicals'           => __( 'The page prints more than one canonical URL. Search engines may ignore them all; keep one plugin in charge: %s', 'rankavia-woocommerce' ),
			/* translators: %s: canonical URL found. */
			'live_canonical_differs'    => __( 'The page prints a different canonical URL from its settings: %s', 'rankavia-woocommerce' ),
			'live_no_canonical'         => __( 'The page prints no canonical URL.', 'rankavia-woocommerce' ),
			/* translators: %s: robots directives found. */
			'live_noindex'              => __( 'The page tells search engines not to index it, but its settings do not. A theme, plugin or server rule adds it: %s', 'rankavia-woocommerce' ),
			/* translators: %s: robots directives found. */
			'live_noindex_out_of_stock' => __( 'The page is noindexed because something treats out-of-stock products that way, not its own settings. Products that come back in stock lose search traffic this way: %s', 'rankavia-woocommerce' ),
			/* translators: %s: robots directives found. */
			'live_noindex_seen'         => __( 'The page tells search engines not to index it: %s', 'rankavia-woocommerce' ),
			'live_indexable'            => __( 'Its settings say noindex, but the page does not print it.', 'rankavia-woocommerce' ),
			/* translators: %s: robots.txt rule. */
			'robots_blocked'            => __( 'Robots.txt stops search engines from crawling this page: %s', 'rankavia-woocommerce' ),
			'store_indexable'           => __( 'This page is not set to noindex. Cart, checkout, account and search pages usually stay out of search results.', 'rankavia-woocommerce' ),
			'store_live_indexable'      => __( 'The page does not tell search engines to stay away. Cart, checkout, account and search pages usually stay out of search results.', 'rankavia-woocommerce' ),
			'shop_noindex'              => __( 'The shop page is set to noindex, so search engines drop your main product listing.', 'rankavia-woocommerce' ),
			'shop_live_noindex'         => __( 'The shop page tells search engines not to index it.', 'rankavia-woocommerce' ),
		);
		$text     = $messages[ $finding['code'] ] ?? $finding['code'];
		return false === strpos( $text, '%s' ) ? $text : sprintf( $text, $finding['value'] );
	}

	/**
	 * Facts for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{url:string,canonical:string,noindex:bool|null,stock:string,visibility:string,password:bool,empty:bool}
	 */
	public function product_facts( \WC_Product $product ): array {
		$id     = $product->get_id();
		$url    = (string) get_permalink( $id );
		$stocks = array(
			'instock'     => 'InStock',
			'outofstock'  => 'OutOfStock',
			'onbackorder' => 'BackOrder',
		);
		return array(
			'url'        => $url,
			'canonical'  => $this->canonical( 'post', $id, $url ),
			'noindex'    => $this->noindex( 'post', $id, false ),
			'stock'      => $stocks[ $product->get_stock_status() ] ?? $product->get_stock_status(),
			'visibility' => $product->get_catalog_visibility(),
			'password'   => '' !== (string) get_post_field( 'post_password', $id ),
			'empty'      => false,
		);
	}

	/**
	 * Facts for a product category.
	 *
	 * @param \WP_Term $term Category.
	 * @return array{url:string,canonical:string,noindex:bool|null,stock:string,visibility:string,password:bool,empty:bool}
	 */
	public function term_facts( \WP_Term $term ): array {
		$link  = get_term_link( $term );
		$url   = is_string( $link ) ? $link : '';
		$empty = 0 === (int) $term->count;
		return array(
			'url'        => $url,
			'canonical'  => $this->canonical( 'term', (int) $term->term_id, $url ),
			'noindex'    => $this->noindex( 'term', (int) $term->term_id, $empty ),
			'stock'      => '',
			'visibility' => '',
			'password'   => false,
			'empty'      => $empty,
		);
	}

	/**
	 * Store pages: the shop page, which should be indexed, and cart, checkout, account and product search, which should not.
	 *
	 * @return array<int,array{key:string,id:int,status:string,url:string,noindex:bool|null,findings:array<int,array{level:string,code:string,value:string}>}>
	 */
	public function store_pages(): array {
		$pages = array();
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'search' ) as $key ) {
			$excluded = in_array( $key, self::EXCLUDED, true );
			if ( 'search' === $key ) {
				$id     = 0;
				$status = 'publish';
				$url    = add_query_arg(
					array(
						's'         => 'rankavia-index-check',
						'post_type' => 'product',
					),
					home_url( '/' )
				);
			} else {
				$id     = (int) wc_get_page_id( $key );
				$status = $id > 0 ? (string) get_post_status( $id ) : '';
				$url    = $id > 0 ? (string) get_permalink( $id ) : '';
			}
			$noindex = $this->store_noindex( $key, $id );
			$found   = array();
			if ( $excluded && false === $noindex && 'publish' === $status ) {
				$found[] = self::finding( self::PROBLEM, 'store_indexable', '' );
			} elseif ( ! $excluded && true === $noindex ) {
				$found[] = self::finding( self::PROBLEM, 'shop_noindex', '' );
			}
			$pages[] = array(
				'key'      => $key,
				'id'       => $id,
				'status'   => $status,
				'url'      => $url,
				'noindex'  => $noindex,
				'findings' => $found,
			);
		}
		return $pages;
	}

	/**
	 * Whether WordPress asks search engines not to index the whole site.
	 *
	 * @return bool
	 */
	public function site_hidden(): bool {
		return '0' === (string) get_option( 'blog_public' );
	}

	/**
	 * Fetch one page of this site as a signed-out visitor and read its indexing signals.
	 *
	 * @param string $url Page URL on this site.
	 * @return array{status:string,url:string,location:string,noindex:bool,nofollow:bool,found:array<int,string>,canonicals:array<int,string>,robots:array{status:string,allowed:bool|null,rule:string}}
	 */
	public function page( string $url ): array {
		$result = array(
			'status'     => 'unavailable',
			'url'        => $url,
			'location'   => '',
			'noindex'    => false,
			'nofollow'   => false,
			'found'      => array(),
			'canonicals' => array(),
			'robots'     => array(
				'status'  => 'unchecked',
				'allowed' => null,
				'rule'    => '',
			),
		);
		if ( '' === $url || self::host( $url ) !== self::host( home_url() ) ) {
			return $result;
		}
		// Follow up to three redirects on this site, such as http to https or an added slash.
		$current = $url;
		for ( $hop = 0; ; ++$hop ) {
			$response = $this->get( $current );
			if ( is_wp_error( $response ) ) {
				$result['status'] = 'unreachable';
				return $result;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 300 || $code >= 400 ) {
				break;
			}
			$location = (string) wp_remote_retrieve_header( $response, 'location' );
			$next     = '' === $location ? '' : \WP_Http::make_absolute_url( $location, $current );
			if ( '' === $next || $hop >= 3 || $next === $current || self::host( $next ) !== self::host( $url ) ) {
				$result['status']   = 'redirect';
				$result['location'] = '' === $next ? $location : $next;
				return $result;
			}
			$current = $next;
		}
		if ( ! self::same_url( $current, $url ) ) {
			$result['location'] = $current;
		}
		if ( 200 !== $code ) {
			$result['status'] = 'unreachable';
			return $result;
		}
		$body       = (string) wp_remote_retrieve_body( $response );
		$header     = wp_remote_retrieve_header( $response, 'x-robots-tag' );
		$directives = self::directives( $body, is_array( $header ) ? $header : array( $header ) );

		$result['status']     = 'ok';
		$result['noindex']    = $directives['noindex'];
		$result['nofollow']   = $directives['nofollow'];
		$result['found']      = $directives['found'];
		$result['canonicals'] = self::canonicals( $body );
		$result['robots']     = $this->robots_for( $url );
		return $result;
	}

	/**
	 * Configured canonical for a post or term, or empty when it is the page's own address.
	 *
	 * @param string $type post or term.
	 * @param int    $id   Object ID.
	 * @param string $url  Page URL.
	 * @return string
	 */
	private function canonical( string $type, int $id, string $url ): string {
		$keys  = array(
			'rankavia' => '_rankavia_canonical',
			'yoast'    => '_yoast_wpseo_canonical',
			'rankmath' => 'rank_math_canonical_url',
			'seopress' => '_seopress_robots_canonical',
			'tsf'      => '_genesis_canonical_uri',
		);
		$id_of = $this->owner->id();
		$value = '';
		if ( 'post' === $type ) {
			if ( isset( $keys[ $id_of ] ) ) {
				$value = (string) get_post_meta( $id, $keys[ $id_of ], true );
			}
			if ( '' === $value && in_array( $id_of, array( 'self', 'rankavia' ), true ) ) {
				$core  = wp_get_canonical_url( $id );
				$value = is_string( $core ) ? $core : '';
			}
		} elseif ( 'yoast' === $id_of ) {
			$value = (string) ( $this->yoast_term( $id )['wpseo_canonical'] ?? '' );
		} elseif ( 'tsf' === $id_of ) {
			$settings = get_term_meta( $id, 'autodescription-term-settings', true );
			$value    = is_array( $settings ) ? (string) ( $settings['canonical'] ?? '' ) : '';
		} elseif ( in_array( $id_of, array( 'rankmath', 'seopress' ), true ) ) {
			$value = (string) get_term_meta( $id, $keys[ $id_of ], true );
		}
		$value = trim( $value );
		return '' !== $value && self::same_url( $value, $url ) ? '' : $value;
	}

	/**
	 * Configured noindex for a post or term: true, false, or null when the owner keeps it where this check cannot read it.
	 *
	 * @param string $type  post or term.
	 * @param int    $id    Object ID.
	 * @param bool   $no_items Whether a term has no products.
	 * @return bool|null
	 */
	private function noindex( string $type, int $id, bool $no_items ): ?bool {
		if ( $this->site_hidden() ) {
			return true;
		}
		$post = 'post' === $type;
		switch ( $this->owner->id() ) {
			case 'self':
				return false;
			case 'rankavia':
				return $post && '1' === (string) get_post_meta( $id, '_rankavia_noindex', true );
			case 'yoast':
				$titles = get_option( 'wpseo_titles' );
				$titles = is_array( $titles ) ? $titles : array();
				$value  = $post ? (string) get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true ) : (string) ( $this->yoast_term( $id )['wpseo_noindex'] ?? '' );
				if ( in_array( $value, array( '1', 'noindex' ), true ) ) {
					return true;
				}
				if ( in_array( $value, array( '2', 'index' ), true ) ) {
					return false;
				}
				return ! empty( $titles[ $post ? 'noindex-product' : 'noindex-tax-product_cat' ] );
			case 'rankmath':
				$robots = $post ? get_post_meta( $id, 'rank_math_robots', true ) : get_term_meta( $id, 'rank_math_robots', true );
				if ( ! is_array( $robots ) || array() === $robots ) {
					$titles = get_option( 'rank-math-options-titles' );
					$titles = is_array( $titles ) ? $titles : array();
					$prefix = $post ? 'pt_product_' : 'tax_product_cat_';
					$robots = ! empty( $titles[ $prefix . 'custom_robots' ] ) && 'off' !== $titles[ $prefix . 'custom_robots' ] ? ( $titles[ $prefix . 'robots' ] ?? array() ) : ( $titles['robots_global'] ?? array() );
					if ( ! $post && $no_items && ! empty( $titles['noindex_empty_taxonomies'] ) && 'off' !== $titles['noindex_empty_taxonomies'] ) {
						return true;
					}
				}
				return is_array( $robots ) && in_array( 'noindex', $robots, true );
			case 'seopress':
				$value = $post ? get_post_meta( $id, '_seopress_robots_index', true ) : get_term_meta( $id, '_seopress_robots_index', true );
				return 'yes' === $value;
			case 'tsf':
				if ( $post ) {
					$value = (string) get_post_meta( $id, '_genesis_noindex', true );
				} else {
					$settings = get_term_meta( $id, 'autodescription-term-settings', true );
					$value    = is_array( $settings ) ? (string) ( $settings['noindex'] ?? '' ) : '';
				}
				if ( '' !== $value && '0' !== $value ) {
					return '1' === $value;
				}
				return null;
			default:
				return null;
		}
	}

	/**
	 * Configured noindex for a store page.
	 *
	 * @param string $key Store page key.
	 * @param int    $id  Page ID, 0 for search.
	 * @return bool|null
	 */
	private function store_noindex( string $key, int $id ): ?bool {
		if ( $this->site_hidden() ) {
			return true;
		}
		$wp_robots = in_array( $this->owner->id(), array( 'self', 'rankavia' ), true );
		if ( 'search' === $key ) {
			return $wp_robots ? false !== has_filter( 'wp_robots', 'wp_robots_noindex_search' ) : null;
		}
		if ( $id <= 0 ) {
			return null;
		}
		$own = $this->noindex( 'post', $id, false );
		if ( true === $own || 'shop' === $key ) {
			return $own;
		}
		// WooCommerce adds noindex to cart, checkout and account pages through wp_robots.
		return $wp_robots ? false !== has_filter( 'wp_robots', 'wc_page_no_robots' ) : null;
	}

	/**
	 * Yoast SEO settings of one product category.
	 *
	 * @param int $id Term ID.
	 * @return array<string,mixed>
	 */
	private function yoast_term( int $id ): array {
		$meta = get_option( 'wpseo_taxonomy_meta' );
		return is_array( $meta ) && isset( $meta['product_cat'][ $id ] ) && is_array( $meta['product_cat'][ $id ] ) ? $meta['product_cat'][ $id ] : array();
	}

	/**
	 * Robots.txt verdict for a URL, fetching robots.txt once per request.
	 *
	 * @param string $url Page URL.
	 * @return array{status:string,allowed:bool|null,rule:string}
	 */
	private function robots_for( string $url ): array {
		$parts  = wp_parse_url( $url );
		$origin = ( $parts['scheme'] ?? 'https' ) . '://' . ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$txt    = $origin . '/robots.txt';
		if ( ! isset( $this->robots_txt[ $txt ] ) ) {
			$response = $this->get( $txt );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			if ( 200 === $code ) {
				$this->robots_txt[ $txt ] = array(
					'status' => 'ok',
					'body'   => (string) wp_remote_retrieve_body( $response ),
				);
			} else {
				$this->robots_txt[ $txt ] = array(
					'status' => $code >= 400 && $code < 500 ? 'none' : 'unreachable',
					'body'   => '',
				);
			}
		}
		$fetched = $this->robots_txt[ $txt ];
		if ( 'ok' !== $fetched['status'] ) {
			return array(
				'status'  => $fetched['status'],
				'allowed' => 'none' === $fetched['status'] ? true : null,
				'rule'    => '',
			);
		}
		$path = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$rule = self::robots_rule( $fetched['body'], $path );
		return array(
			'status'  => 'ok',
			'allowed' => $rule['allowed'],
			'rule'    => $rule['rule'],
		);
	}

	/**
	 * GET a URL on this site without following redirects.
	 *
	 * @param string $url URL.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function get( string $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
	}

	/**
	 * Finding.
	 *
	 * @param string $level PROBLEM or NOTE.
	 * @param string $code  Code.
	 * @param string $value Value shown in the message.
	 * @return array{level:string,code:string,value:string}
	 */
	private static function finding( string $level, string $code, string $value ): array {
		return array(
			'level' => $level,
			'code'  => $code,
			'value' => $value,
		);
	}

	/**
	 * Attributes of one HTML tag, lower-case names.
	 *
	 * @param string $tag Tag.
	 * @return array<string,string>
	 */
	private static function attributes( string $tag ): array {
		$attrs = array();
		if ( preg_match_all( '#([a-zA-Z_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))#', $tag, $pairs, PREG_SET_ORDER ) ) {
			foreach ( $pairs as $pair ) {
				$attrs[ strtolower( $pair[1] ) ] = html_entity_decode( $pair[2] . ( $pair[3] ?? '' ) . ( $pair[4] ?? '' ), ENT_QUOTES );
			}
		}
		return $attrs;
	}

	/**
	 * Whether a value is an absolute http(s) URL.
	 *
	 * @param string $url Value.
	 * @return bool
	 */
	private static function is_url( string $url ): bool {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, tested without WordPress.
		return in_array( $scheme, array( 'http', 'https' ), true ) && '' !== self::host( $url );
	}

	/**
	 * Lower-case host of a URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host( string $url ): string {
		return strtolower( (string) parse_url( $url, PHP_URL_HOST ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, tested without WordPress.
	}

	/**
	 * URL with case-insensitive parts lowered, default ports and a trailing slash removed.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function normalize( string $url ): string {
		$parts = parse_url( html_entity_decode( trim( $url ), ENT_QUOTES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, tested without WordPress.
		if ( ! is_array( $parts ) || ! isset( $parts['host'] ) ) {
			return trim( $url );
		}
		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$port   = isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ? ':' . $parts['port'] : '';
		$path   = rtrim( $parts['path'] ?? '', '/' );
		return $scheme . '://' . strtolower( $parts['host'] ) . $port . $path . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}
}
