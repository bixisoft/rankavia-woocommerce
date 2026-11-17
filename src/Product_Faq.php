<?php
/**
 * Merchant-written product questions and answers, shown on the product page.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Answers are plain text. The visible tab and the optional FAQPage structured data are built from
 * the same saved entries, and the structured data is printed only inside the visible tab, so it
 * can never describe answers the page does not show.
 */
final class Product_Faq {
	public const META     = '_rankavia_wc_faq';
	public const SCHEMA   = '_rankavia_wc_faq_schema';
	public const MAX      = 10;
	public const QUESTION = 300;
	public const ANSWER   = 2000;
	private const NONCE   = 'rankavia_wc_faq';
	private const REV     = 'rankavia_wc_faq_rev';
	private const NOTICE  = 'rankavia_wc_faq_notice_';
	private const TAB     = 'rankavia_faq';

	/**
	 * Output owner.
	 *
	 * @var Seo_Owner
	 */
	private Seo_Owner $owner;

	/**
	 * FAQ.
	 *
	 * @param Seo_Owner $owner Output owner.
	 */
	public function __construct( Seo_Owner $owner ) {
		$this->owner = $owner;
	}

	/** Register WooCommerce hooks. */
	public function boot(): void {
		add_filter( 'woocommerce_product_tabs', array( $this, 'product_tab' ), 30 );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	/**
	 * Complete, trimmed entries: plain text, both parts present, at most MAX.
	 *
	 * @param mixed $raw Entries.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function normalize( $raw ): array {
		$items = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( count( $items ) >= self::MAX ) {
				break;
			}
			if ( ! is_array( $row ) || ! is_string( $row['question'] ?? null ) || ! is_string( $row['answer'] ?? null ) ) {
				continue;
			}
			$question = self::plain( $row['question'], false );
			$answer   = self::plain( $row['answer'], true );
			if ( '' === $question || '' === $answer || mb_strlen( $question ) > self::QUESTION || mb_strlen( $answer ) > self::ANSWER ) {
				continue;
			}
			$items[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}
		return $items;
	}

	/**
	 * Entries saved on a product.
	 *
	 * @param string $stored Stored JSON.
	 * @return array<int,array{question:string,answer:string}>
	 */
	public static function decode( string $stored ): array {
		return '' === $stored ? array() : self::normalize( json_decode( $stored, true ) );
	}

	/**
	 * Identity of the stored values, to notice changes made while the form was open.
	 *
	 * @param string $stored Stored JSON.
	 * @param string $schema Stored structured data flag.
	 * @return string
	 */
	public static function revision( string $stored, string $schema ): string {
		return hash( 'sha256', $stored . "\0" . $schema );
	}

	/**
	 * Visible questions and answers.
	 *
	 * @param array<int,array{question:string,answer:string}> $items Entries.
	 * @return string HTML.
	 */
	public static function html( array $items ): string {
		$html = '<div class="rankavia-wc-faq">';
		foreach ( $items as $item ) {
			$paragraphs = array();
			$parts      = preg_split( "#\n{2,}#", $item['answer'] );
			foreach ( is_array( $parts ) ? $parts : array() as $paragraph ) {
				$paragraphs[] = '<p>' . str_replace( "\n", '<br />', esc_html( $paragraph ) ) . '</p>';
			}
			$html .= '<h3>' . esc_html( $item['question'] ) . '</h3>' . implode( '', $paragraphs );
		}
		return $html . '</div>';
	}

	/**
	 * FAQPage structured data for the same entries.
	 *
	 * @param array<int,array{question:string,answer:string}> $items Entries.
	 * @return array<string,mixed>
	 */
	public static function graph( array $items ): array {
		$questions = array();
		foreach ( $items as $item ) {
			$questions[] = array(
				'@type'          => 'Question',
				'name'           => $item['question'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $item['answer'],
				),
			);
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $questions,
		);
	}

	/**
	 * Add the questions tab to a product page that has entries.
	 *
	 * @param mixed $tabs Product tabs.
	 * @return mixed
	 */
	public function product_tab( $tabs ) {
		global $product;
		if ( ! is_array( $tabs ) || ! $product instanceof \WC_Product || array() === self::decode( (string) $product->get_meta( self::META, true ) ) ) {
			return $tabs;
		}
		$tabs[ self::TAB ] = array(
			'title'    => __( 'Questions and answers', 'rankavia-woocommerce' ),
			'priority' => 25,
			'callback' => array( $this, 'render_tab' ),
		);
		return $tabs;
	}

	/** Print the tab and, when allowed, matching structured data inside it. */
	public function render_tab(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$items = self::decode( (string) $product->get_meta( self::META, true ) );
		if ( array() === $items ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Questions and answers', 'rankavia-woocommerce' ) . '</h2>';
		echo self::html( $items ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html() escapes every value.
		if ( $this->may_print_schema( $product ) ) {
			$json = wp_json_encode( self::graph( $items ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( is_string( $json ) ) {
				echo '<script type="application/ld+json">' . $json . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON with HTML characters escaped.
			}
		}
	}

	/**
	 * Whether this product page may carry FAQPage structured data.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool
	 */
	public function may_print_schema( \WC_Product $product ): bool {
		$id = $product->get_id();
		if ( 'yes' !== $product->get_meta( self::SCHEMA, true ) || Seo_Owner::OTHER === $this->owner->kind() ) {
			return false;
		}
		if ( is_admin() || is_preview() || ! is_singular( 'product' ) || (int) get_queried_object_id() !== $id || '0' === (string) get_option( 'blog_public', '1' ) ) {
			return false;
		}
		if ( 'publish' !== get_post_status( $id ) || '' !== (string) get_post_field( 'post_password', $id ) || '1' === (string) get_post_meta( $id, '_rankavia_noindex', true ) ) {
			return false;
		}
		// The Rankavia SEO FAQ block in the description already prints FAQPage data; one per page.
		if ( function_exists( 'has_block' ) && has_block( 'rankavia/faq', $id ) ) {
			return false;
		}
		$robots = apply_filters( 'wp_robots', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Respect the core robots policy chain.
		return ! ( is_array( $robots ) && ! empty( $robots['noindex'] ) );
	}

	/**
	 * Product data tab in the editor.
	 *
	 * @param mixed $tabs Product data tabs.
	 * @return mixed
	 */
	public function data_tab( $tabs ) {
		if ( is_array( $tabs ) ) {
			$tabs[ self::TAB ] = array(
				'label'    => __( 'Questions', 'rankavia-woocommerce' ),
				'target'   => 'rankavia_wc_faq_panel',
				'class'    => array(),
				'priority' => 75,
			);
		}
		return $tabs;
	}

	/** Product data panel with one row per entry and a few empty rows. */
	public function panel(): void {
		global $product_object;
		echo '<div id="rankavia_wc_faq_panel" class="panel woocommerce_options_panel hidden">';
		if ( ! $product_object instanceof \WC_Product || ! current_user_can( 'edit_post', $product_object->get_id() ) ) {
			echo '</div>';
			return;
		}
		$stored = (string) $product_object->get_meta( self::META, true );
		$schema = (string) $product_object->get_meta( self::SCHEMA, true );
		$items  = self::decode( $stored );
		$rows   = min( self::MAX, count( $items ) + 3 );
		wp_nonce_field( self::NONCE, self::NONCE . '_nonce' );
		echo '<input type="hidden" name="' . esc_attr( self::REV ) . '" value="' . esc_attr( self::revision( $stored, $schema ) ) . '">';
		echo '<div class="options_group"><p>' . esc_html__( 'Questions shoppers ask about this product, answered in your own words. They appear in a "Questions and answers" tab on the product page. Empty both boxes of a question to remove it. Answers are plain text; leave a blank line between paragraphs.', 'rankavia-woocommerce' ) . '</p>';
		for ( $row = 0; $row < $rows; ++$row ) {
			$item = $items[ $row ] ?? array(
				'question' => '',
				'answer'   => '',
			);
			printf(
				'<p class="form-field"><label for="rankavia_wc_faq_q_%1$d">%2$s</label><input type="text" class="short" style="width:80%%" id="rankavia_wc_faq_q_%1$d" name="rankavia_wc_faq[%1$d][question]" maxlength="%3$d" value="%4$s" /></p>',
				(int) $row,
				esc_html(
					sprintf(
						/* translators: %d: question number. */
						__( 'Question %d', 'rankavia-woocommerce' ),
						$row + 1
					)
				),
				(int) self::QUESTION,
				esc_attr( $item['question'] )
			);
			printf(
				'<p class="form-field"><label for="rankavia_wc_faq_a_%1$d">%2$s</label><textarea class="short" style="width:80%%" rows="3" id="rankavia_wc_faq_a_%1$d" name="rankavia_wc_faq[%1$d][answer]" maxlength="%3$d">%4$s</textarea></p>',
				(int) $row,
				esc_html__( 'Answer', 'rankavia-woocommerce' ),
				(int) self::ANSWER,
				esc_textarea( $item['answer'] )
			);
		}
		if ( $rows < self::MAX ) {
			echo '<p class="description" style="padding:0 12px">' . esc_html__( 'Save the product to get more empty rows, up to ten questions.', 'rankavia-woocommerce' ) . '</p>';
		}
		echo '</div><div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => self::SCHEMA,
				'label'       => __( 'FAQ structured data', 'rankavia-woocommerce' ),
				'cbvalue'     => 'yes',
				'value'       => 'yes' === $schema ? 'yes' : 'no',
				'description' => __( 'Also describe these questions as FAQPage structured data. Off by default. Google shows FAQ results only for well-known government and health sites, so this usually does not change how a shop appears in search; it is not a ranking boost. It is never printed when another SEO plugin is active or the page is set to noindex.', 'rankavia-woocommerce' ),
			)
		);
		echo '</div></div>';
	}

	/**
	 * Save the entries.
	 *
	 * @param int $product_id Product post ID.
	 * @return void
	 */
	public function save( int $product_id ): void {
		$nonce = isset( $_POST[ self::NONCE . '_nonce' ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE . '_nonce' ] ) ) : '';
		if ( ! current_user_can( 'edit_post', $product_id ) || ! wp_verify_nonce( $nonce, self::NONCE ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$stored = (string) $product->get_meta( self::META, true );
		$schema = (string) $product->get_meta( self::SCHEMA, true );
		$seen   = isset( $_POST[ self::REV ] ) && is_string( $_POST[ self::REV ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::REV ] ) ) : '';
		if ( ! hash_equals( self::revision( $stored, $schema ), $seen ) ) {
			$this->flag( 'stale' );
			return;
		}

		$rows       = isset( $_POST['rankavia_wc_faq'] ) && is_array( $_POST['rankavia_wc_faq'] ) ? wp_unslash( $_POST['rankavia_wc_faq'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each value is sanitized below.
		$submitted  = array();
		$incomplete = false;
		foreach ( $rows as $row ) {
			$question = is_array( $row ) && is_string( $row['question'] ?? null ) ? sanitize_text_field( $row['question'] ) : '';
			$answer   = is_array( $row ) && is_string( $row['answer'] ?? null ) ? sanitize_textarea_field( $row['answer'] ) : '';
			if ( '' === trim( $question ) && '' === trim( $answer ) ) {
				continue;
			}
			if ( '' === trim( $question ) || '' === trim( $answer ) ) {
				$incomplete = true;
				continue;
			}
			$submitted[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}
		$items = self::normalize( $submitted );
		if ( count( $items ) < count( $submitted ) ) {
			$incomplete = true;
		}

		if ( array() === $items ) {
			$product->delete_meta_data( self::META );
		} else {
			$product->update_meta_data( self::META, (string) wp_json_encode( $items, JSON_UNESCAPED_UNICODE ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified above.
		if ( isset( $_POST[ self::SCHEMA ] ) && 'yes' === $_POST[ self::SCHEMA ] ) {
			$product->update_meta_data( self::SCHEMA, 'yes' );
		} else {
			$product->delete_meta_data( self::SCHEMA );
		}
		$product->save_meta_data();
		if ( $incomplete ) {
			$this->flag( 'incomplete' );
		}
	}

	/** Show the outcome of the last save once. */
	public function notice(): void {
		$key  = self::NOTICE . get_current_user_id();
		$code = get_transient( $key );
		if ( ! is_string( $code ) ) {
			return;
		}
		delete_transient( $key );
		$messages = array(
			'stale'      => array( 'warning', __( 'The product questions were changed by someone else while you edited this product, so your questions were not saved. Check them and save again.', 'rankavia-woocommerce' ) ),
			'incomplete' => array( 'warning', __( 'Some product questions were not saved: each needs both a question (up to 300 characters) and an answer (up to 2,000 characters), and at most ten are kept.', 'rankavia-woocommerce' ) ),
		);
		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
		}
	}

	/**
	 * Plain text: tags removed, whitespace tidied, paragraphs kept for answers.
	 *
	 * @param string $text      Text.
	 * @param bool   $multiline Keep line breaks.
	 * @return string
	 */
	private static function plain( string $text, bool $multiline ): string {
		$text = str_replace( array( "\r\n", "\r" ), "\n", strip_tags( $text ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Pure helper, tested without WordPress.
		if ( ! $multiline ) {
			return trim( (string) preg_replace( '#\s+#u', ' ', $text ) );
		}
		$text = (string) preg_replace( '#[ \t]+#u', ' ', $text );
		$text = (string) preg_replace( '# *\n *#u', "\n", $text );
		return trim( (string) preg_replace( "#\n{3,}#", "\n\n", $text ) );
	}

	/**
	 * Remember a message for the next admin page.
	 *
	 * @param string $code Message code.
	 * @return void
	 */
	private function flag( string $code ): void {
		set_transient( self::NOTICE . get_current_user_id(), $code, 300 );
	}
}
