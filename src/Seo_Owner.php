<?php
/**
 * Which plugin prints product and category titles and meta descriptions.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * One owner per output: another SEO plugin, Rankavia SEO, or this add-on on its own.
 */
final class Seo_Owner {
	public const OTHER    = 'other';
	public const RANKAVIA = 'rankavia';
	public const SELF     = 'self';

	/**
	 * SEO plugins that print their own title and description, by the constant they define: id and name.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private const OTHERS = array(
		'WPSEO_VERSION'             => array( 'yoast', 'Yoast SEO' ),
		'RANK_MATH_VERSION'         => array( 'rankmath', 'Rank Math' ),
		'AIOSEO_VERSION'            => array( 'aioseo', 'All in One SEO' ),
		'SEOPRESS_VERSION'          => array( 'seopress', 'SEOPress' ),
		'THE_SEO_FRAMEWORK_VERSION' => array( 'tsf', 'The SEO Framework' ),
	);

	/**
	 * Owner kind.
	 *
	 * @var string
	 */
	private string $kind;

	/**
	 * Owner name for messages.
	 *
	 * @var string
	 */
	private string $name;

	/**
	 * Whether the owner reads category overrides.
	 *
	 * @var bool
	 */
	private bool $terms;

	/**
	 * Plugin id: yoast, rankmath, aioseo, seopress, tsf, rankavia or self.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Owner.
	 *
	 * @param string $kind  Owner kind.
	 * @param string $name  Owner name.
	 * @param bool   $terms Whether category overrides are rendered.
	 * @param string $id    Plugin id; defaults to the kind.
	 */
	public function __construct( string $kind, string $name, bool $terms, string $id = '' ) {
		$this->kind  = $kind;
		$this->name  = $name;
		$this->terms = $terms;
		$this->id    = '' === $id ? $kind : $id;
	}

	/** Plugin id: yoast, rankmath, aioseo, seopress, tsf, rankavia or self. */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Detect the owner for this request. Call on or after plugins_loaded.
	 *
	 * @return self
	 */
	public static function detect(): self {
		foreach ( self::OTHERS as $constant => $plugin ) {
			if ( defined( $constant ) ) {
				return new self( self::OTHER, $plugin[1], false, $plugin[0] );
			}
		}
		if ( defined( 'RANKAVIA_VERSION' ) ) {
			return new self(
				self::RANKAVIA,
				'Rankavia SEO',
				defined( 'Bixisoft\\Rankavia\\Modules\\Meta\\Meta_Module::TERM_OVERRIDES' )
			);
		}
		return new self( self::SELF, 'Rankavia for WooCommerce', true );
	}

	/** Owner kind. */
	public function kind(): string {
		return $this->kind;
	}

	/** Owner name for messages. */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Whether edits made here appear on the storefront.
	 *
	 * @param string $kind Entity kind: product or product_cat.
	 * @return bool
	 */
	public function renders( string $kind ): bool {
		if ( self::OTHER === $this->kind ) {
			return false;
		}
		return 'product' === $kind || $this->terms;
	}
}
