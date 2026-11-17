<?php
/**
 * Product structured data checked against the price, currency, stock and identifiers the shop shows.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Read-only diagnostics. It never writes product data and never fills in a missing fact.
 *
 * The graph comes from WooCommerce's own generator with every filter applied, or from the
 * product page as an anonymous visitor receives it. Facts come from the WooCommerce product.
 */
final class Product_Graph_Check {
	public const PROBLEM = 'problem';
	public const MISSING = 'missing';

	private const GTIN_KEYS = array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14' );

	/**
	 * Compare a Product graph with the product's own facts.
	 *
	 * @param array<string,mixed>|null $graph Product node, null when none is printed.
	 * @param array<string,mixed>      $facts See facts().
	 * @return array<int,array{level:string,code:string,graph:string,shop:string}>
	 */
	public static function evaluate( ?array $graph, array $facts ): array {
		$found = array();
		$add   = static function ( string $level, string $code, string $graph_value = '', string $shop_value = '' ) use ( &$found ): void {
			$found[] = array(
				'level' => $level,
				'code'  => $code,
				'graph' => $graph_value,
				'shop'  => $shop_value,
			);
		};

		if ( null === $graph ) {
			if ( ! $facts['has_price'] && 0 === $facts['rating_count'] ) {
				$add( self::MISSING, 'no_price' );
			} else {
				$add( self::PROBLEM, 'no_graph' );
			}
			return $found;
		}

		$offer = self::offer( $graph );
		if ( $facts['has_price'] && null === $offer ) {
			$add( self::PROBLEM, 'no_offer' );
		}
		if ( null !== $offer ) {
			self::check_offer( $offer, $facts, $add );
		}

		// Ratings must come from real approved reviews.
		$rating = isset( $graph['aggregateRating'] ) && is_array( $graph['aggregateRating'] ) ? $graph['aggregateRating'] : null;
		if ( null !== $rating && 0 === $facts['rating_count'] ) {
			$add( self::PROBLEM, 'rating_without_reviews', self::text( $rating['reviewCount'] ?? $rating['ratingCount'] ?? '' ) );
		} elseif ( null !== $rating && isset( $rating['reviewCount'] ) && (int) $rating['reviewCount'] !== $facts['review_count'] ) {
			$add( self::PROBLEM, 'review_count', self::text( $rating['reviewCount'] ), (string) $facts['review_count'] );
		}

		self::check_identifiers( $graph, $facts, $add );
		return $found;
	}

	/**
	 * The first offer of a Product node.
	 *
	 * @param array<string,mixed> $graph Product node.
	 * @return array<string,mixed>|null
	 */
	public static function offer( array $graph ): ?array {
		$offers = $graph['offers'] ?? null;
		if ( ! is_array( $offers ) || array() === $offers ) {
			return null;
		}
		if ( isset( $offers['@type'] ) ) {
			return $offers;
		}
		$first = reset( $offers );
		return is_array( $first ) ? $first : null;
	}

	/**
	 * Find the Product node in decoded JSON-LD: a node, a list of nodes or an @graph.
	 *
	 * @param mixed $data Decoded JSON-LD.
	 * @return array<string,mixed>|null
	 */
	public static function product_node( $data ): ?array {
		if ( ! is_array( $data ) ) {
			return null;
		}
		$type = $data['@type'] ?? null;
		if ( 'Product' === $type || ( is_array( $type ) && in_array( 'Product', $type, true ) ) ) {
			return $data;
		}
		$children = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( isset( $data['@type'] ) ? array() : $data );
		foreach ( $children as $child ) {
			$node = self::product_node( $child );
			if ( null !== $node ) {
				return $node;
			}
		}
		return null;
	}

	/**
	 * Product node printed in a page's JSON-LD scripts.
	 *
	 * @param string $html Page HTML.
	 * @return array{graph:array<string,mixed>|null,count:int}
	 */
	public static function from_html( string $html ): array {
		$graph = null;
		$count = 0;
		if ( preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $scripts ) ) {
			foreach ( $scripts[1] as $json ) {
				$decoded = json_decode( trim( $json ), true );
				if ( null === $decoded ) {
					$decoded = json_decode( html_entity_decode( trim( $json ), ENT_QUOTES ), true );
				}
				$node = self::product_node( $decoded );
				if ( null !== $node ) {
					++$count;
					$graph = $graph ?? $node;
				}
			}
		}
		return array(
			'graph' => $graph,
			'count' => $count,
		);
	}

	/**
	 * Facts the shop shows for a product, as WooCommerce computes them for display.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,mixed>
	 */
	public function facts( \WC_Product $product ): array {
		$decimals = (int) wc_get_price_decimals();
		$facts    = array(
			'type'         => (string) $product->get_type(),
			'has_price'    => '' !== (string) $product->get_price(),
			'price'        => null,
			'low'          => null,
			'high'         => null,
			'decimals'     => $decimals,
			'currency'     => (string) get_woocommerce_currency(),
			'availability' => $product->is_in_stock() ? ( 'onbackorder' === $product->get_stock_status() ? 'BackOrder' : 'InStock' ) : 'OutOfStock',
			'rating_count' => (int) $product->get_rating_count(),
			'review_count' => (int) $product->get_review_count(),
			'gtin_native'  => Product_Module::native_gtin( $product ),
			'gtin_addon'   => Product_Module::normalize_gtin( (string) $product->get_meta( Product_Module::GTIN, true ) ),
			'sku'          => (string) $product->get_sku(),
		);
		if ( $product instanceof \WC_Product_Variable ) {
			$facts['low']  = (string) $product->get_variation_price( 'min', true );
			$facts['high'] = (string) $product->get_variation_price( 'max', true );
		} elseif ( $product instanceof \WC_Product_Grouped ) {
			$prices = array();
			foreach ( array_filter( array_map( 'wc_get_product', $product->get_children() ), 'wc_products_array_filter_visible_grouped' ) as $child ) {
				if ( '' !== (string) $child->get_price() ) {
					$prices[] = (float) wc_get_price_to_display( $child );
				}
			}
			$facts['has_price'] = array() !== $prices;
			$facts['price']     = array() === $prices ? null : (string) min( $prices );
		} elseif ( $facts['has_price'] ) {
			$facts['price'] = (string) wc_get_price_to_display( $product );
		}
		return $facts;
	}

	/**
	 * The Product node WooCommerce generates for a product, with every filter applied.
	 *
	 * Uses a fresh generator so the storefront's collected data and hooks are untouched.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{status:string,graph:array<string,mixed>|null}
	 */
	public function generated( \WC_Product $product ): array {
		if ( ! class_exists( 'WC_Structured_Data' ) ) {
			return array(
				'status' => 'unavailable',
				'graph'  => null,
			);
		}
		ob_start();
		try {
			$generator = ( new \ReflectionClass( 'WC_Structured_Data' ) )->newInstanceWithoutConstructor();
			$generator->generate_product_data( $product );
			$data = $generator->get_data();
		} catch ( \Throwable $error ) {
			ob_end_clean();
			return array(
				'status' => 'unavailable',
				'graph'  => null,
			);
		}
		ob_end_clean();
		return array(
			'status' => 'ok',
			'graph'  => self::product_node( is_array( $data ) ? array_values( $data ) : null ),
		);
	}

	/**
	 * The Product node on the published product page, fetched from this site as a visitor.
	 *
	 * @param \WC_Product $product Product.
	 * @return array{status:string,graph:array<string,mixed>|null,count:int,url:string}
	 */
	public function live( \WC_Product $product ): array {
		$url    = (string) get_permalink( $product->get_id() );
		$result = array(
			'status' => 'unavailable',
			'graph'  => null,
			'count'  => 0,
			'url'    => $url,
		);
		if ( '' === $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return $result;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter.
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$result['status'] = 'unreachable';
			return $result;
		}
		$parsed           = self::from_html( (string) wp_remote_retrieve_body( $response ) );
		$result['status'] = 'ok';
		$result['graph']  = $parsed['graph'];
		$result['count']  = $parsed['count'];
		return $result;
	}

	/**
	 * Plain-language text for a finding.
	 *
	 * @param array{level:string,code:string,graph:string,shop:string} $finding Finding.
	 * @return string
	 */
	public static function message( array $finding ): string {
		$messages = array(
			/* translators: Product structured data diagnostics. */
			'no_price'               => __( 'No price and no reviews, so WooCommerce prints no product structured data. Add a price when the product is for sale; nothing is filled in for you.', 'rankavia-woocommerce' ),
			'no_graph'               => __( 'The product has a price or reviews, but no product structured data is produced. Another plugin or theme may remove it.', 'rankavia-woocommerce' ),
			'no_offer'               => __( 'The product has a price, but the structured data has no offer.', 'rankavia-woocommerce' ),
			/* translators: 1: price in structured data, 2: price the shop shows. */
			'price'                  => __( 'Structured data price %1$s differs from the shown price %2$s. Check tax display, currency or pricing plugins.', 'rankavia-woocommerce' ),
			'price_missing'          => __( 'The offer has no price.', 'rankavia-woocommerce' ),
			/* translators: 1: price range in structured data, 2: price range the shop shows. */
			'price_range'            => __( 'Structured data price range %1$s differs from the shown range %2$s.', 'rankavia-woocommerce' ),
			/* translators: 1: currency in structured data, 2: shop currency. */
			'currency'               => __( 'Structured data currency %1$s differs from the shop currency %2$s.', 'rankavia-woocommerce' ),
			'currency_missing'       => __( 'The offer has no currency.', 'rankavia-woocommerce' ),
			/* translators: 1: availability in structured data, 2: stock status the shop shows. */
			'availability'           => __( 'Structured data availability %1$s differs from the shown stock status %2$s.', 'rankavia-woocommerce' ),
			'availability_missing'   => __( 'The offer has no availability.', 'rankavia-woocommerce' ),
			/* translators: %s: review count in structured data. */
			'rating_without_reviews' => __( 'Structured data has a rating (%s reviews), but the product has no approved rated reviews.', 'rankavia-woocommerce' ),
			/* translators: 1: review count in structured data, 2: approved review count. */
			'review_count'           => __( 'Structured data review count %1$s differs from the %2$s approved reviews.', 'rankavia-woocommerce' ),
			/* translators: %s: GTIN. */
			'gtin_rejected'          => __( 'WooCommerce leaves out the GTIN %s because it is not 8, 12, 13 or 14 digits.', 'rankavia-woocommerce' ),
			/* translators: %s: GTIN. */
			'gtin_check_digit'       => __( 'The GTIN %s has a wrong check digit. Check it against the barcode.', 'rankavia-woocommerce' ),
			/* translators: 1: GTIN in structured data, 2: GTIN saved for the product. */
			'gtin_mismatch'          => __( 'Structured data GTIN %1$s differs from the saved GTIN %2$s.', 'rankavia-woocommerce' ),
			'gtin'                   => __( 'No GTIN. Products without one can be identified by brand and MPN.', 'rankavia-woocommerce' ),
			'brand'                  => __( 'No brand.', 'rankavia-woocommerce' ),
			'sku'                    => __( 'No SKU, so WooCommerce uses the product ID.', 'rankavia-woocommerce' ),
		);
		$text     = $messages[ $finding['code'] ] ?? $finding['code'];
		return sprintf( $text, $finding['graph'], $finding['shop'] );
	}

	/**
	 * Price, currency and availability of the first offer.
	 *
	 * @param array<string,mixed> $offer Offer.
	 * @param array<string,mixed> $facts Facts.
	 * @param callable            $add   Finding collector.
	 * @return void
	 */
	private static function check_offer( array $offer, array $facts, callable $add ): void {
		$spec     = self::current_spec( $offer );
		$decimals = (int) $facts['decimals'];

		if ( 'AggregateOffer' === ( $offer['@type'] ?? '' ) ) {
			$low  = self::text( $offer['lowPrice'] ?? '' );
			$high = self::text( $offer['highPrice'] ?? '' );
			if ( null !== $facts['low'] && ( ! self::same_price( $low, (string) $facts['low'], $decimals ) || ! self::same_price( $high, (string) $facts['high'], $decimals ) ) ) {
				$add( self::PROBLEM, 'price_range', $low . '–' . $high, self::price( (string) $facts['low'], $decimals ) . '–' . self::price( (string) $facts['high'], $decimals ) );
			}
		} else {
			$price    = self::text( $offer['price'] ?? $spec['price'] ?? '' );
			$expected = $facts['price'] ?? ( null !== $facts['low'] && $facts['low'] === $facts['high'] ? $facts['low'] : null );
			if ( '' === $price ) {
				$add( self::PROBLEM, 'price_missing' );
			} elseif ( null !== $expected && ! self::same_price( $price, (string) $expected, $decimals ) ) {
				$add( self::PROBLEM, 'price', $price, self::price( (string) $expected, $decimals ) );
			} elseif ( null === $expected && null !== $facts['low'] ) {
				$add( self::PROBLEM, 'price_range', $price, self::price( (string) $facts['low'], $decimals ) . '–' . self::price( (string) $facts['high'], $decimals ) );
			}
		}

		$currency = self::text( $offer['priceCurrency'] ?? $spec['priceCurrency'] ?? '' );
		if ( '' === $currency ) {
			$add( self::PROBLEM, 'currency_missing' );
		} elseif ( strtoupper( $currency ) !== strtoupper( (string) $facts['currency'] ) ) {
			$add( self::PROBLEM, 'currency', $currency, (string) $facts['currency'] );
		}

		$availability = self::text( $offer['availability'] ?? '' );
		$short        = (string) preg_replace( '#^https?://schema\.org/#i', '', $availability );
		if ( '' === $availability ) {
			$add( self::PROBLEM, 'availability_missing' );
		} elseif ( $short !== $facts['availability'] ) {
			$add( self::PROBLEM, 'availability', $short, (string) $facts['availability'] );
		}
	}

	/**
	 * GTIN, brand and SKU.
	 *
	 * @param array<string,mixed> $graph Product node.
	 * @param array<string,mixed> $facts Facts.
	 * @param callable            $add   Finding collector.
	 * @return void
	 */
	private static function check_identifiers( array $graph, array $facts, callable $add ): void {
		$printed = '';
		foreach ( self::GTIN_KEYS as $key ) {
			if ( isset( $graph[ $key ] ) && '' !== self::text( $graph[ $key ] ) ) {
				$printed = self::text( $graph[ $key ] );
				break;
			}
		}

		$native   = (string) preg_replace( '/[^0-9]/', '', (string) $facts['gtin_native'] );
		$addon    = (string) $facts['gtin_addon'];
		$expected = '';
		if ( '' !== $native ) {
			if ( ! preg_match( '/^(\d{8}|\d{12,14})$/', $native ) ) {
				$add( self::PROBLEM, 'gtin_rejected', (string) $facts['gtin_native'] );
			} else {
				$expected = $native;
				if ( ! Product_Module::valid_gtin( $native ) ) {
					$add( self::PROBLEM, 'gtin_check_digit', $native );
				}
			}
		} elseif ( Product_Module::valid_gtin( $addon ) ) {
			$expected = $addon;
		}

		if ( '' !== $printed && '' !== $expected && $printed !== $expected ) {
			$add( self::PROBLEM, 'gtin_mismatch', $printed, $expected );
		} elseif ( '' === $printed && '' === $expected ) {
			$add( self::MISSING, 'gtin' );
		}

		$brand = $graph['brand'] ?? null;
		if ( ! ( is_array( $brand ) ? '' !== self::text( $brand['name'] ?? '' ) : '' !== self::text( $brand ?? '' ) ) ) {
			$add( self::MISSING, 'brand' );
		}
		if ( '' === $facts['sku'] ) {
			$add( self::MISSING, 'sku' );
		}
	}

	/**
	 * The price specification for the current price, skipping a list (regular) price.
	 *
	 * @param array<string,mixed> $offer Offer.
	 * @return array<string,mixed>
	 */
	private static function current_spec( array $offer ): array {
		$specs = $offer['priceSpecification'] ?? array();
		if ( ! is_array( $specs ) ) {
			return array();
		}
		if ( isset( $specs['@type'] ) || isset( $specs['price'] ) ) {
			$specs = array( $specs );
		}
		foreach ( $specs as $spec ) {
			if ( is_array( $spec ) && false === stripos( self::text( $spec['priceType'] ?? '' ), 'ListPrice' ) ) {
				return $spec;
			}
		}
		return array();
	}

	/**
	 * Whether two prices are equal at the shop's precision.
	 *
	 * @param string $a        First.
	 * @param string $b        Second.
	 * @param int    $decimals Price decimals.
	 * @return bool
	 */
	private static function same_price( string $a, string $b, int $decimals ): bool {
		if ( ! is_numeric( $a ) || ! is_numeric( $b ) ) {
			return false;
		}
		return abs( (float) $a - (float) $b ) < 0.5 * ( 10 ** -max( 0, $decimals ) );
	}

	/**
	 * A price at the shop's precision.
	 *
	 * @param string $value    Price.
	 * @param int    $decimals Price decimals.
	 * @return string
	 */
	private static function price( string $value, int $decimals ): string {
		return is_numeric( $value ) ? number_format( (float) $value, max( 0, $decimals ), '.', '' ) : $value;
	}

	/**
	 * A scalar as text.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function text( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
}
