<?php
/**
 * Read-only alt text review for product images.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Lists a product's main and gallery images with their alt text and points out missing or weak
 * text. It never writes alt text; merchants edit it in the Media Library.
 */
final class Image_Alt_Check {
	public const MISSING = 'missing';
	public const NOTE    = 'note';
	public const LONG    = 125;

	/**
	 * Findings per image, keyed by attachment ID.
	 *
	 * @param array<int,array{id:int,alt:string,file:string}> $images Product images, main image first.
	 * @return array<int,array<int,array{level:string,code:string}>>
	 */
	public static function evaluate( array $images ): array {
		$counts = array();
		foreach ( $images as $image ) {
			$key = self::key( $image['alt'] );
			if ( '' !== $key ) {
				$counts[ $key ] = ( $counts[ $key ] ?? 0 ) + 1;
			}
		}
		$found = array();
		foreach ( $images as $image ) {
			$list = array();
			$alt  = trim( $image['alt'] );
			if ( '' === $alt ) {
				$list[] = self::finding( self::MISSING, 'missing' );
			} else {
				if ( self::looks_like_file( $alt, $image['file'] ) ) {
					$list[] = self::finding( self::NOTE, 'filename' );
				}
				if ( mb_strlen( $alt ) > self::LONG ) {
					$list[] = self::finding( self::NOTE, 'long' );
				}
				if ( ( $counts[ self::key( $alt ) ] ?? 0 ) > 1 ) {
					$list[] = self::finding( self::NOTE, 'repeated' );
				}
			}
			$found[ $image['id'] ] = $list;
		}
		return $found;
	}

	/**
	 * Plain-language text for a finding.
	 *
	 * @param array{level:string,code:string} $finding Finding.
	 * @return string
	 */
	public static function message( array $finding ): string {
		$messages = array(
			'missing'  => __( 'No alt text. Describe what the image shows.', 'rankavia-woocommerce' ),
			'filename' => __( 'The alt text looks like a file name. Describe the image instead.', 'rankavia-woocommerce' ),
			'long'     => __( 'Long alt text. A short sentence is usually enough; put details in the product description.', 'rankavia-woocommerce' ),
			'repeated' => __( 'Other images of this product use the same alt text. Say what this one shows that the others do not, such as the angle, colour or detail.', 'rankavia-woocommerce' ),
		);
		return $messages[ $finding['code'] ] ?? $finding['code'];
	}

	/**
	 * Main and gallery images of a product.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int,array{id:int,alt:string,file:string,main:bool}>
	 */
	public function images( \WC_Product $product ): array {
		$ids    = array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) );
		$images = array();
		$seen   = array();
		foreach ( $ids as $index => $id ) {
			if ( $id <= 0 || isset( $seen[ $id ] ) || ! wp_attachment_is_image( $id ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$file        = get_attached_file( $id );
			$images[]    = array(
				'id'   => $id,
				'alt'  => trim( wp_strip_all_tags( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ),
				'file' => is_string( $file ) ? wp_basename( $file ) : '',
				'main' => 0 === $index,
			);
		}
		return $images;
	}

	/**
	 * Whether alt text is a file name or a camera's default name.
	 *
	 * @param string $alt  Alt text.
	 * @param string $file File name.
	 * @return bool
	 */
	private static function looks_like_file( string $alt, string $file ): bool {
		$plain = strtolower( (string) preg_replace( '#[\s_\-.]+#', '', $alt ) );
		$name  = strtolower( (string) preg_replace( '#[\s_\-.]+#', '', (string) preg_replace( '#\.[a-z0-9]{2,5}$#i', '', $file ) ) );
		$whole = strtolower( (string) preg_replace( '#[\s_\-.]+#', '', $file ) );
		if ( '' !== $name && ( $name === $plain || $whole === $plain ) ) {
			return true;
		}
		return 1 === preg_match( '#^(img|dsc|dscn|dcim|image|photo|pxl|screenshot)[\s_\-]*\d+#i', $alt ) || 1 === preg_match( '#\.(jpe?g|png|gif|webp|avif)$#i', $alt );
	}

	/**
	 * Comparison key for alt text.
	 *
	 * @param string $alt Alt text.
	 * @return string
	 */
	private static function key( string $alt ): string {
		return mb_strtolower( trim( (string) preg_replace( '#\s+#u', ' ', $alt ) ) );
	}

	/**
	 * Finding.
	 *
	 * @param string $level MISSING or NOTE.
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
