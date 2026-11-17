<?php
declare( strict_types=1 );

// Pure FAQ and image alt tests; run with: php tests/Product_Faq_Test.php

function __( $text, $domain = '' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }

require dirname( __DIR__ ) . '/src/Seo_Owner.php';
require dirname( __DIR__ ) . '/src/Product_Faq.php';
require dirname( __DIR__ ) . '/src/Image_Alt_Check.php';

use Bixisoft\RankaviaWooCommerce\Image_Alt_Check;
use Bixisoft\RankaviaWooCommerce\Product_Faq as Faq;

function check( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

// Entries.
$items = Faq::normalize(
	array(
		array( 'question' => "  Is it   <b>washable</b>? ", 'answer' => "Yes, at 30 degrees.\r\n\r\n\r\nDry   flat." ),
		array( 'question' => 'No answer', 'answer' => '   ' ),
		array( 'question' => '', 'answer' => 'Orphan answer' ),
		'not a row',
		array( 'question' => array( 'x' ), 'answer' => 'Bad type' ),
		array( 'question' => str_repeat( 'q', 301 ), 'answer' => 'Too long question' ),
		array( 'question' => 'Sizes?', 'answer' => "S, M\nand L" ),
	)
);
check( 2 === count( $items ), 'Wrong number of entries kept' );
check( 'Is it washable?' === $items[0]['question'], 'Question not tidied' );
check( "Yes, at 30 degrees.\n\nDry flat." === $items[0]['answer'], 'Answer paragraphs not kept' );
$many = array_fill( 0, 15, array( 'question' => 'Q', 'answer' => 'A' ) );
check( Faq::MAX === count( Faq::normalize( $many ) ), 'More than ten kept' );
check( array() === Faq::decode( '' ) && array() === Faq::decode( 'not json' ), 'Bad storage decoded' );
check( $items === Faq::decode( json_encode( $items ) ), 'Round trip' );
check( Faq::revision( 'a', '' ) !== Faq::revision( 'a', 'yes' ), 'Schema flag not in revision' );

// Visible answers and structured data come from the same text.
$xss  = Faq::normalize( array( array( 'question' => 'Fits "large" & <script>x</script> heads?', 'answer' => "1 < 2\nsecond line" ) ) );
$html = Faq::html( $xss );
check( false === strpos( $html, '<script>' ) && false !== strpos( $html, '&quot;large&quot; &amp;' ), 'Visible text not escaped' );
check( false !== strpos( $html, '1 &lt; 2<br />second line' ), 'Line break not kept' );
check( 2 === substr_count( Faq::html( $items ), '<h3>' ) && false !== strpos( Faq::html( $items ), '<p>Dry flat.</p>' ), 'Paragraphs not rendered' );
$graph = Faq::graph( $items );
check( 'FAQPage' === $graph['@type'] && 2 === count( $graph['mainEntity'] ), 'Graph shape' );
check( $items[0]['answer'] === $graph['mainEntity'][0]['acceptedAnswer']['text'] && $items[1]['question'] === $graph['mainEntity'][1]['name'], 'Graph differs from visible text' );

// Image alt text.
$found = Image_Alt_Check::evaluate(
	array(
		array( 'id' => 1, 'alt' => 'Red wool hat, front view', 'file' => 'red-hat-front.jpg' ),
		array( 'id' => 2, 'alt' => '', 'file' => 'red-hat-side.jpg' ),
		array( 'id' => 3, 'alt' => 'red-hat-back', 'file' => 'red-hat-back.jpg' ),
		array( 'id' => 4, 'alt' => 'IMG_2041', 'file' => 'upload.jpg' ),
		array( 'id' => 5, 'alt' => 'Red Wool Hat', 'file' => 'a.jpg' ),
		array( 'id' => 6, 'alt' => 'red wool  hat', 'file' => 'b.jpg' ),
		array( 'id' => 7, 'alt' => str_repeat( 'very long ', 15 ), 'file' => 'c.jpg' ),
		array( 'id' => 8, 'alt' => 'photo.jpeg', 'file' => 'd.jpg' ),
	)
);
$codes = static function ( array $list ): array {
	return array_map( static function ( $f ) { return $f['code']; }, $list );
};
check( array() === $found[1], 'Good alt flagged' );
check( array( 'missing' ) === $codes( $found[2] ), 'Missing alt' );
check( array( 'filename' ) === $codes( $found[3] ), 'File name alt' );
check( array( 'filename' ) === $codes( $found[4] ), 'Camera name alt' );
check( array( 'repeated' ) === $codes( $found[5] ) && array( 'repeated' ) === $codes( $found[6] ), 'Repeated alt' );
check( array( 'long' ) === $codes( $found[7] ), 'Long alt' );
check( array( 'filename' ) === $codes( $found[8] ), 'Extension alt' );
check( array( 2 => array() ) === Image_Alt_Check::evaluate( array( array( 'id' => 2, 'alt' => 'Hat', 'file' => 'x.jpg' ) ) ), 'Single image' );
check( '' !== Image_Alt_Check::message( array( 'level' => 'note', 'code' => 'repeated' ) ), 'Message' );

echo "Product_Faq_Test: all checks passed\n";
