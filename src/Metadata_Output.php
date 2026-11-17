<?php
/**
 * Title and meta description for products and product categories when no SEO plugin prints them.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Prints saved values as plain text. Values with Rankavia SEO variables such as %sitename% need
 * Rankavia SEO to render them and are left out rather than shown with the variables in them.
 */
final class Metadata_Output {

	/**
	 * Store.
	 *
	 * @var Metadata_Store
	 */
	private Metadata_Store $store;

	/**
	 * Output.
	 *
	 * @param Metadata_Store $store Store.
	 */
	public function __construct( Metadata_Store $store ) {
		$this->store = $store;
	}

	/** Register storefront hooks. Call only when this add-on owns the output. */
	public function boot(): void {
		add_filter( 'pre_get_document_title', array( $this, 'document_title' ), 20 );
		add_action( 'wp_head', array( $this, 'print_description' ), 1 );
	}

	/**
	 * Saved title of the product or category being shown.
	 *
	 * @param mixed $title Title from earlier filters.
	 * @return mixed
	 */
	public function document_title( $title ) {
		$value = $this->value( 'title' );
		return null === $value ? $title : $value;
	}

	/** Print the saved meta description of the product or category being shown. */
	public function print_description(): void {
		$value = $this->value( 'description' );
		if ( null !== $value ) {
			echo '<meta name="description" content="' . esc_attr( $value ) . '" />' . "\n";
		}
	}

	/**
	 * Whether a value uses Rankavia SEO template variables.
	 *
	 * @param string $value Saved value.
	 * @return bool
	 */
	public static function has_variables( string $value ): bool {
		return 1 === preg_match( '/%[a-z_]+%/', $value );
	}

	/**
	 * Printable saved value for the current product or category view.
	 *
	 * @param string $field title or description.
	 * @return string|null
	 */
	private function value( string $field ): ?string {
		if ( function_exists( 'is_product' ) && is_product() ) {
			$values = $this->store->read( 'product', (int) get_queried_object_id() );
		} elseif ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$values = $this->store->read( 'product_cat', (int) get_queried_object_id() );
		} else {
			return null;
		}
		$value = $values[ $field ] ?? null;
		return null === $value || self::has_variables( $value ) ? null : $value;
	}
}
