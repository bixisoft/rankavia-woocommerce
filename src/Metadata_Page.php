<?php
/**
 * WooCommerce > Rankavia SEO: product and category title and description inventory, editor and undo.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Admin screen. Every write is one entity, checked against its edit capability, a nonce and the
 * revision the form was opened with.
 */
final class Metadata_Page {
	public const SLUG             = 'rankavia-wc-seo';
	private const PER_PAGE        = 20;
	private const TITLE_LONG      = 60;
	private const DESCRIPTION_MIN = 70;
	private const DESCRIPTION_MAX = 160;

	/**
	 * Store.
	 *
	 * @var Metadata_Store
	 */
	private Metadata_Store $store;

	/**
	 * Output owner.
	 *
	 * @var Seo_Owner
	 */
	private Seo_Owner $owner;

	/**
	 * Product structured data check.
	 *
	 * @var Product_Graph_Check
	 */
	private Product_Graph_Check $check;

	/**
	 * Canonical and indexing check.
	 *
	 * @var Index_Policy_Check
	 */
	private Index_Policy_Check $index;

	/**
	 * Product image alt text review.
	 *
	 * @var Image_Alt_Check
	 */
	private Image_Alt_Check $images;

	/**
	 * Sitemap audit.
	 *
	 * @var Sitemap_Check
	 */
	private Sitemap_Check $sitemap;

	/**
	 * Sitemap policy.
	 *
	 * @var Sitemap_Policy
	 */
	private Sitemap_Policy $policy;

	/**
	 * Page.
	 *
	 * @param Metadata_Store      $store Store.
	 * @param Seo_Owner           $owner Output owner.
	 * @param Product_Graph_Check $check Product structured data check.
	 * @param Index_Policy_Check  $index  Canonical and indexing check.
	 * @param Image_Alt_Check     $images  Product image alt text review.
	 * @param Sitemap_Check       $sitemap Sitemap audit.
	 * @param Sitemap_Policy      $policy  Sitemap policy.
	 */
	public function __construct( Metadata_Store $store, Seo_Owner $owner, Product_Graph_Check $check, Index_Policy_Check $index, Image_Alt_Check $images, Sitemap_Check $sitemap, Sitemap_Policy $policy ) {
		$this->store   = $store;
		$this->owner   = $owner;
		$this->check   = $check;
		$this->index   = $index;
		$this->images  = $images;
		$this->sitemap = $sitemap;
		$this->policy  = $policy;
	}

	/** Register admin hooks. */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_rankavia_wc_meta_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_rankavia_wc_meta_undo', array( $this, 'handle_undo' ) );
		add_action( 'admin_post_rankavia_wc_sitemap_save', array( $this, 'handle_sitemap_save' ) );
	}

	/** Add the submenu under WooCommerce. */
	public function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Rankavia SEO', 'rankavia-woocommerce' ),
			__( 'Rankavia SEO', 'rankavia-woocommerce' ),
			'edit_products', // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** Render the screen. */
	public function render(): void {
		if ( ! current_user_can( 'edit_products' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only view parameters.
		$kind   = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		$kind   = in_array( $kind, array( 'product', 'product_cat', 'product_data', 'indexing', 'images', 'sitemap' ), true ) ? $kind : ( Seo_Owner::OTHER === $this->owner->kind() ? 'product_data' : 'product' );
		$live   = isset( $_GET['live'] ) ? absint( $_GET['live'] ) : 0;
		$term   = isset( $_GET['live_term'] ) ? absint( $_GET['live_term'] ) : 0;
		$stores = ! empty( $_GET['live_store'] );
		$edit   = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$result = isset( $_GET['result'] ) ? sanitize_key( wp_unslash( $_GET['result'] ) ) : '';
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$paged  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		echo '<div class="wrap rankavia-wc-seo"><h1>' . esc_html__( 'Rankavia SEO for products and categories', 'rankavia-woocommerce' ) . '</h1>';
		$this->result_notice( $result );
		$this->tabs( $kind );

		if ( 'sitemap' === $kind ) {
			$this->sitemap_tab( $paged, ! empty( $_GET['fetch'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked in sitemap_tab().
			echo '</div>';
			return;
		}

		if ( 'images' === $kind ) {
			$this->image_alts( $paged );
			echo '</div>';
			return;
		}

		if ( 'indexing' === $kind ) {
			$this->indexing( $filter, $paged, $live, $term, $stores );
			echo '</div>';
			return;
		}

		if ( 'product_data' === $kind ) {
			if ( $live > 0 ) {
				$this->live_check( $live );
			}
			$this->product_data( $paged );
			echo '</div>';
			return;
		}

		$this->owner_notice();
		if ( Seo_Owner::OTHER === $this->owner->kind() ) {
			echo '</div>';
			return;
		}

		if ( 'product_cat' === $kind && ! current_user_can( 'manage_product_terms' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			echo '<p>' . esc_html__( 'You cannot edit product categories.', 'rankavia-woocommerce' ) . '</p></div>';
			return;
		}

		if ( $edit > 0 ) {
			$this->editor( $kind, $edit );
		} else {
			$this->summary( $kind );
			$this->inventory( $kind, $filter, $paged );
		}
		$this->history();
		echo '</div>';
	}

	/** Save one entity. */
	public function handle_save(): void {
		$kind  = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$id    = isset( $_POST['object'] ) ? absint( $_POST['object'] ) : 0;
		$back  = $this->url(
			array(
				'kind' => $kind,
				'edit' => $id,
			)
		);
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! Metadata_Store::valid_kind( $kind ) || ! wp_verify_nonce( $nonce, 'rankavia_wc_meta_save_' . $kind . '_' . $id ) ) {
			$this->finish( add_query_arg( 'result', 'denied', $back ) );
			return;
		}
		if ( Seo_Owner::OTHER === $this->owner->kind() ) {
			$this->finish( add_query_arg( 'result', 'owned', $back ) );
			return;
		}

		$wanted = array();
		foreach ( array_keys( Metadata_Store::FIELDS ) as $field ) {
			if ( isset( $_POST[ $field ] ) ) {
				$wanted[ $field ] = Metadata_Store::clean( (string) wp_unslash( $_POST[ $field ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Cleaned by Metadata_Store::clean.
			}
		}
		$revision = isset( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		$outcome  = $this->store->apply( $kind, $id, $wanted, $revision );
		$this->finish( add_query_arg( 'result', $outcome['status'], $back ) );
	}

	/** Undo one journal entry. */
	public function handle_undo(): void {
		$entry = isset( $_POST['entry'] ) ? sanitize_key( wp_unslash( $_POST['entry'] ) ) : '';
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$back  = isset( $_POST['back'] ) ? esc_url_raw( wp_unslash( $_POST['back'] ) ) : '';
		$back  = '' !== $back ? wp_validate_redirect( $back, $this->url() ) : $this->url();
		if ( '' === $entry || ! wp_verify_nonce( $nonce, 'rankavia_wc_meta_undo_' . $entry ) ) {
			$this->finish( add_query_arg( 'result', 'denied', $back ) );
			return;
		}
		if ( Seo_Owner::OTHER === $this->owner->kind() ) {
			$this->finish( add_query_arg( 'result', 'owned', $back ) );
			return;
		}
		$this->finish( add_query_arg( 'result', 'undo_' . $this->store->undo( $entry ), $back ) );
	}

	/**
	 * Redirect and end the request. Separate so tests can stub it.
	 *
	 * @param string $url Destination.
	 * @return void
	 */
	protected function finish( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Screen URL.
	 *
	 * @param array<string,int|string> $args Query arguments.
	 * @return string
	 */
	private function url( array $args = array() ): string {
		return add_query_arg( array( 'page' => self::SLUG ) + $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Message for a save or undo result.
	 *
	 * @param string $result Result code.
	 * @return void
	 */
	private function result_notice( string $result ): void {
		$messages = array(
			'saved'         => array( 'success', __( 'Saved. You can undo this change below.', 'rankavia-woocommerce' ) ),
			'unchanged'     => array( 'info', __( 'Nothing changed.', 'rankavia-woocommerce' ) ),
			'stale'         => array( 'warning', __( 'Not saved: someone changed these values after you opened the form. Review the current values and try again.', 'rankavia-woocommerce' ) ),
			'denied'        => array( 'error', __( 'Not saved: your session expired or you cannot edit this item.', 'rankavia-woocommerce' ) ),
			'owned'         => array( 'error', __( 'Not saved: another SEO plugin prints these values.', 'rankavia-woocommerce' ) ),
			'undo_undone'   => array( 'success', __( 'Change undone.', 'rankavia-woocommerce' ) ),
			'undo_conflict' => array( 'warning', __( 'Not undone: the values were changed again after this change. Nothing was overwritten.', 'rankavia-woocommerce' ) ),
			'undo_already'  => array( 'info', __( 'This change was already undone.', 'rankavia-woocommerce' ) ),
			'undo_denied'   => array( 'error', __( 'Not undone: you cannot edit this item, or it no longer exists.', 'rankavia-woocommerce' ) ),
			'undo_missing'  => array( 'error', __( 'Not undone: this change is no longer in the history.', 'rankavia-woocommerce' ) ),
			'sitemap_saved' => array( 'success', __( 'Sitemap settings saved. Search engines see the change the next time they read the sitemap.', 'rankavia-woocommerce' ) ),
		);
		if ( ! isset( $messages[ $result ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%1$s"><p>%2$s</p></div>',
			esc_attr( $messages[ $result ][0] ),
			esc_html( $messages[ $result ][1] )
		);
	}

	/** Explain which plugin prints the values. */
	private function owner_notice(): void {
		if ( Seo_Owner::OTHER === $this->owner->kind() ) {
			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: SEO plugin name. */
						__( '%s prints product and category titles and descriptions on this site. Edit them there; this screen stays read-only so the two plugins never print two sets of tags.', 'rankavia-woocommerce' ),
						$this->owner->name()
					)
				)
			);
			return;
		}
		if ( Seo_Owner::RANKAVIA === $this->owner->kind() ) {
			$text = $this->owner->renders( 'product_cat' )
				? __( 'Rankavia SEO prints these titles and descriptions. Values saved here are the same ones its product editor uses, and its template variables work.', 'rankavia-woocommerce' )
				: __( 'Rankavia SEO prints these titles and descriptions. Update Rankavia SEO to show category values saved here; product values already show.', 'rankavia-woocommerce' );
		} else {
			$text = __( 'No SEO plugin is active, so this add-on prints the product and category values saved here as plain text. Values with Rankavia SEO template variables need Rankavia SEO and are not printed.', 'rankavia-woocommerce' );
		}
		printf( '<p class="description">%s</p>', esc_html( $text ) );
	}

	/**
	 * Products and categories tabs.
	 *
	 * @param string $kind Current kind.
	 * @return void
	 */
	private function tabs( string $kind ): void {
		$tabs = array(
			'product'      => __( 'Products', 'rankavia-woocommerce' ),
			'product_cat'  => __( 'Categories', 'rankavia-woocommerce' ),
			'product_data' => __( 'Product data', 'rankavia-woocommerce' ),
			'indexing'     => __( 'Indexing', 'rankavia-woocommerce' ),
			'images'       => __( 'Images', 'rankavia-woocommerce' ),
			'sitemap'      => __( 'Sitemap', 'rankavia-woocommerce' ),
		);
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $tab => $label ) {
			printf(
				'<a href="%1$s" class="nav-tab%2$s"%3$s>%4$s</a>',
				esc_url( $this->url( array( 'kind' => $tab ) ) ),
				$tab === $kind ? ' nav-tab-active' : '',
				$tab === $kind ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';
	}

	/**
	 * Health counts for published products or all categories.
	 *
	 * @param string $kind Entity kind.
	 * @return void
	 */
	private function summary( string $kind ): void {
		$total    = $this->count( $kind, 'all' );
		$no_title = $this->count( $kind, 'missing_title' );
		$no_desc  = $this->count( $kind, 'missing_description' );
		$label    = 'product' === $kind ? __( 'Published products', 'rankavia-woocommerce' ) : __( 'Categories', 'rankavia-woocommerce' );
		$rows     = array(
			array( $label, $total, 'all' ),
			array( __( 'Without their own SEO title', 'rankavia-woocommerce' ), $no_title, 'missing_title' ),
			array( __( 'Without a meta description', 'rankavia-woocommerce' ), $no_desc, 'missing_description' ),
		);
		echo '<h2>' . esc_html__( 'Health', 'rankavia-woocommerce' ) . '</h2><ul class="rankavia-wc-health">';
		foreach ( $rows as $row ) {
			printf(
				'<li><a href="%1$s"><strong>%2$s</strong></a> %3$s</li>',
				esc_url(
					$this->url(
						array(
							'kind'   => $kind,
							'filter' => $row[2],
						)
					)
				),
				esc_html( number_format_i18n( $row[1] ) ),
				esc_html( $row[0] )
			);
		}
		echo '</ul><p class="description">' . esc_html__( 'Without its own title, an item uses the default title. Without a description, search engines choose text from the page. Nothing here is filled in automatically.', 'rankavia-woocommerce' ) . '</p>';
	}

	/**
	 * Meta query for a filter.
	 *
	 * @param string $filter Filter.
	 * @return array<int|string,mixed>
	 */
	private static function meta_query( string $filter ): array {
		$keys = array(
			'missing_title'       => Metadata_Store::TITLE,
			'missing_description' => Metadata_Store::DESCRIPTION,
		);
		if ( ! isset( $keys[ $filter ] ) ) {
			return array();
		}
		return array(
			'relation' => 'OR',
			array(
				'key'     => $keys[ $filter ],
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => $keys[ $filter ],
				'value' => '',
			),
		);
	}

	/**
	 * Count items for a filter.
	 *
	 * @param string $kind   Entity kind.
	 * @param string $filter Filter.
	 * @return int
	 */
	private function count( string $kind, string $filter ): int {
		if ( 'product' === $kind ) {
			$query = new \WP_Query(
				array(
					'post_type'              => 'product',
					'post_status'            => 'publish',
					'fields'                 => 'ids',
					'posts_per_page'         => 1,
					'meta_query'             => self::meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin count.
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
			return (int) $query->found_posts;
		}
		$count = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'fields'     => 'count',
				'meta_query' => self::meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Bounded admin count.
			)
		);
		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * One page of items.
	 *
	 * @param string $kind   Entity kind.
	 * @param string $filter Filter.
	 * @param int    $paged  Page number.
	 * @return array<int,array{id:int,name:string,link:string,edit:string}>
	 */
	private function items( string $kind, string $filter, int $paged ): array {
		$items = array();
		if ( 'product' === $kind ) {
			$query = new \WP_Query(
				array(
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'posts_per_page' => self::PER_PAGE,
					'paged'          => $paged,
					'orderby'        => 'title',
					'order'          => 'ASC',
					'no_found_rows'  => true,
					'meta_query'     => self::meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One admin page of results.
				)
			);
			foreach ( $query->posts as $post ) {
				if ( $post instanceof \WP_Post ) {
					$items[] = array(
						'id'   => (int) $post->ID,
						'name' => get_the_title( $post ),
						'link' => (string) get_permalink( $post ),
						'edit' => (string) get_edit_post_link( $post->ID, 'raw' ),
					);
				}
			}
			return $items;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => self::PER_PAGE,
				'offset'     => ( $paged - 1 ) * self::PER_PAGE,
				'orderby'    => 'name',
				'meta_query' => self::meta_query( $filter ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- One admin page of results.
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term ) {
				$link    = get_term_link( $term );
				$items[] = array(
					'id'   => (int) $term->term_id,
					'name' => $term->name,
					'link' => is_string( $link ) ? $link : '',
					'edit' => (string) get_edit_term_link( $term->term_id, 'product_cat', 'product' ),
				);
			}
		}
		return $items;
	}

	/**
	 * Inventory table with filter and pagination.
	 *
	 * @param string $kind   Entity kind.
	 * @param string $filter Filter.
	 * @param int    $paged  Page number.
	 * @return void
	 */
	private function inventory( string $kind, string $filter, int $paged ): void {
		$filter = in_array( $filter, array( 'missing_title', 'missing_description' ), true ) ? $filter : 'all';
		$total  = $this->count( $kind, $filter );
		$pages  = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged  = min( $paged, $pages );
		$items  = $this->items( $kind, $filter, $paged );

		$filters = array(
			'all'                 => __( 'All', 'rankavia-woocommerce' ),
			'missing_title'       => __( 'Without own title', 'rankavia-woocommerce' ),
			'missing_description' => __( 'Without description', 'rankavia-woocommerce' ),
		);
		echo '<h2>' . esc_html( 'product' === $kind ? __( 'Products', 'rankavia-woocommerce' ) : __( 'Categories', 'rankavia-woocommerce' ) ) . '</h2><ul class="subsubsub">';
		$links = array();
		foreach ( $filters as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url(
					$this->url(
						array(
							'kind'   => $kind,
							'filter' => $key,
						)
					)
				),
				$key === $filter ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo implode( ' | ', $links ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'rankavia-woocommerce' ), __( 'SEO title', 'rankavia-woocommerce' ), __( 'Meta description', 'rankavia-woocommerce' ), __( 'Notes', 'rankavia-woocommerce' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $items ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing to show.', 'rankavia-woocommerce' ) . '</td></tr>';
		}
		foreach ( $items as $item ) {
			$values = $this->store->read( $kind, $item['id'] );
			echo '<tr><td>';
			if ( '' !== $item['link'] ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $item['link'] ), esc_html( $item['name'] ) );
			} else {
				echo esc_html( $item['name'] );
			}
			echo '</td><td>' . $this->cell( $values['title'], __( 'Default title', 'rankavia-woocommerce' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() escapes.
			echo '<td>' . $this->cell( $values['description'], __( 'Missing', 'rankavia-woocommerce' ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cell() escapes.
			echo '<td>' . esc_html( implode( ' ', $this->notes( $values ) ) ) . '</td>';
			printf(
				'<td><a class="button button-small" href="%1$s">%2$s</a></td></tr>',
				esc_url(
					$this->url(
						array(
							'kind' => $kind,
							'edit' => $item['id'],
						)
					)
				),
				esc_html__( 'Edit', 'rankavia-woocommerce' )
			);
		}
		echo '</tbody></table>';
		$this->pager( $kind, $filter, $paged, $pages );
	}

	/**
	 * Previous and next page links.
	 *
	 * @param string $kind   Tab.
	 * @param string $filter Filter.
	 * @param int    $paged  Current page.
	 * @param int    $pages  Page count.
	 * @return void
	 */
	private function pager( string $kind, string $filter, int $paged, int $pages ): void {
		if ( $pages > 1 ) {
			echo '<p class="tablenav-pages">';
			printf(
				/* translators: 1: current page, 2: page count. */
				esc_html__( 'Page %1$s of %2$s', 'rankavia-woocommerce' ),
				esc_html( number_format_i18n( $paged ) ),
				esc_html( number_format_i18n( $pages ) )
			);
			foreach ( array(
				$paged - 1 => __( 'Previous', 'rankavia-woocommerce' ),
				$paged + 1 => __( 'Next', 'rankavia-woocommerce' ),
			) as $target => $label ) {
				if ( $target >= 1 && $target <= $pages ) {
					printf(
						' <a class="button button-small" href="%1$s">%2$s</a>',
						esc_url(
							$this->url(
								array(
									'kind'   => $kind,
									'filter' => $filter,
									'paged'  => $target,
								)
							)
						),
						esc_html( $label )
					);
				}
			}
			echo '</p>';
		}
	}

	/**
	 * Product structured data compared with what the shop shows, one page of products.
	 *
	 * @param int $paged Page number.
	 * @return void
	 */
	private function product_data( int $paged ): void {
		$total = $this->count( 'product', 'all' );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$items = $this->items( 'product', 'all', $paged );

		echo '<h2>' . esc_html__( 'Product structured data', 'rankavia-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Compares the product data WooCommerce gives search engines with the price, currency, stock status, reviews and identifiers your shop shows. Nothing is changed or filled in. Fix a problem on the product itself, or in the plugin that changes prices or structured data.', 'rankavia-woocommerce' ) . '</p>';
		if ( Seo_Owner::OTHER === $this->owner->kind() ) {
			printf(
				'<p class="description">%s</p>',
				esc_html(
					sprintf(
						/* translators: %s: SEO plugin name. */
						__( '%s is active and may replace WooCommerce product data. Use "Check live page" to see what the product page prints.', 'rankavia-woocommerce' ),
						$this->owner->name()
					)
				)
			);
		}

		$rows     = array();
		$problems = 0;
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['id'] );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$facts     = $this->check->facts( $product );
			$generated = $this->check->generated( $product );
			$findings  = 'ok' === $generated['status'] ? Product_Graph_Check::evaluate( $generated['graph'], $facts ) : array();
			foreach ( $findings as $finding ) {
				if ( Product_Graph_Check::PROBLEM === $finding['level'] ) {
					++$problems;
					break;
				}
			}
			$rows[] = array( $item, $product, $facts, $generated['status'], $findings );
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: products with problems, 2: products checked on this page. */
					__( 'Products on this page with a mismatch: %1$s of %2$s.', 'rankavia-woocommerce' ),
					number_format_i18n( $problems ),
					number_format_i18n( count( $rows ) )
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Product', 'rankavia-woocommerce' ), __( 'Shown price', 'rankavia-woocommerce' ), __( 'Stock', 'rankavia-woocommerce' ), __( 'Findings', 'rankavia-woocommerce' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing to show.', 'rankavia-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			list( $item, $product, $facts, $status, $findings ) = $row;
			echo '<tr><td>';
			if ( '' !== $item['edit'] ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $item['edit'] ), esc_html( $item['name'] ) );
			} else {
				echo esc_html( $item['name'] );
			}
			echo '<br><span class="description">' . esc_html( $facts['type'] ) . '</span></td>';
			echo '<td>' . wp_kses_post( $product->get_price_html() ) . ' <span class="description">' . esc_html( $facts['currency'] ) . '</span></td>';
			$stock = array(
				'InStock'    => __( 'In stock', 'rankavia-woocommerce' ),
				'OutOfStock' => __( 'Out of stock', 'rankavia-woocommerce' ),
				'BackOrder'  => __( 'On backorder', 'rankavia-woocommerce' ),
			);
			echo '<td>' . esc_html( $stock[ $facts['availability'] ] ?? $facts['availability'] ) . '</td><td>';
			if ( 'ok' !== $status ) {
				echo '<em>' . esc_html__( 'WooCommerce structured data could not be generated here. Use "Check live page".', 'rankavia-woocommerce' ) . '</em>';
			} else {
				$this->findings( $findings );
			}
			printf(
				'</td><td><a class="button button-small" href="%1$s">%2$s</a></td></tr>',
				esc_url(
					wp_nonce_url(
						$this->url(
							array(
								'kind'  => 'product_data',
								'live'  => $item['id'],
								'paged' => $paged,
							)
						),
						'rankavia_wc_live_' . $item['id']
					)
				),
				esc_html__( 'Check live page', 'rankavia-woocommerce' )
			);
		}
		echo '</tbody></table>';
		$this->pager( 'product_data', 'all', $paged, $pages );
	}

	/**
	 * Fetch one product page as a visitor and check the product data it prints.
	 *
	 * @param int $id Product ID.
	 * @return void
	 */
	private function live_check( int $id ): void {
		$nonce   = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		$product = wc_get_product( $id );
		if ( ! wp_verify_nonce( $nonce, 'rankavia_wc_live_' . $id ) || ! $product instanceof \WC_Product || ! current_user_can( 'edit_post', $id ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The page check link expired or you cannot edit this product.', 'rankavia-woocommerce' ) . '</p></div>';
			return;
		}
		$result = $this->check->live( $product );
		echo '<div class="notice notice-info"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: product name. */
				__( 'Live page check: %s', 'rankavia-woocommerce' ),
				$product->get_name()
			)
		) . '</strong> <a href="' . esc_url( $result['url'] ) . '">' . esc_html( $result['url'] ) . '</a></p>';
		if ( 'ok' !== $result['status'] ) {
			echo '<p>' . esc_html__( 'The product page could not be loaded from this server, so it was not checked. It may be private, password protected or blocked for loopback requests.', 'rankavia-woocommerce' ) . '</p></div>';
			return;
		}
		if ( 0 === $result['count'] && ! ( '' === (string) $product->get_price() && 0 === (int) $product->get_rating_count() ) ) {
			echo '<p>' . esc_html__( 'The page prints no product structured data. A theme, block template, cache or other plugin may remove it.', 'rankavia-woocommerce' ) . '</p>';
		} elseif ( $result['count'] > 1 ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: number of Product graphs. */
					__( 'The page prints %s separate product descriptions. Search engines may read conflicting facts; keep one plugin in charge of product structured data.', 'rankavia-woocommerce' ),
					number_format_i18n( $result['count'] )
				)
			) . '</p>';
		}
		$this->findings( Product_Graph_Check::evaluate( $result['graph'], $this->check->facts( $product ) ) );
		echo '<p class="description">' . esc_html__( 'Fetched now as a signed-out visitor; a page cache may still serve older data to others.', 'rankavia-woocommerce' ) . '</p></div>';
	}

	/**
	 * Canonical and indexing settings of store pages, products and categories. Read-only.
	 *
	 * @param string $scope  products or categories.
	 * @param int    $paged  Page number.
	 * @param int    $live   Product to check live, or 0.
	 * @param int    $term   Category to check live, or 0.
	 * @param bool   $stores Whether to check store pages live.
	 * @return void
	 */
	private function indexing( string $scope, int $paged, int $live, int $term, bool $stores ): void {
		$scope = 'categories' === $scope ? 'categories' : 'products';
		echo '<h2>' . esc_html__( 'Canonical and indexing', 'rankavia-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Shows which pages ask search engines to stay away (noindex) and which address each page names as its main one (canonical). Nothing is changed here, and nothing is set to noindex for you; out-of-stock products are never hidden automatically.', 'rankavia-woocommerce' ) . '</p>';
		if ( $this->index->site_hidden() ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Settings, Reading asks search engines not to index this whole site, so no product or category can appear in search results.', 'rankavia-woocommerce' ) . '</p></div>';
		}
		if ( Seo_Owner::OTHER === $this->owner->kind() ) {
			$text = 'aioseo' === $this->owner->id()
				/* translators: %s: SEO plugin name. */
				? __( '%s keeps its indexing settings where this screen cannot read them. Use "Check live page" to see what each page prints.', 'rankavia-woocommerce' )
				/* translators: %s: SEO plugin name. */
				: __( 'Settings are read from %s. Use "Check live page" to confirm what a page prints.', 'rankavia-woocommerce' );
			echo '<p class="description">' . esc_html( sprintf( $text, $this->owner->name() ) ) . '</p>';
		}
		if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
			echo '<p class="description">' . esc_html__( 'WooCommerce hides out-of-stock products from shop lists. Their own pages stay open and indexable, which keeps them in search results for when they return.', 'rankavia-woocommerce' ) . '</p>';
		}

		if ( $live > 0 || $term > 0 ) {
			$this->index_live( $live, $term );
		}
		$this->store_pages( $stores );

		$links = array();
		foreach ( array(
			'products'   => __( 'Products', 'rankavia-woocommerce' ),
			'categories' => __( 'Categories', 'rankavia-woocommerce' ),
		) as $key => $label ) {
			$links[] = sprintf(
				'<li><a href="%1$s"%2$s>%3$s</a></li>',
				esc_url(
					$this->url(
						array(
							'kind'   => 'indexing',
							'filter' => $key,
						)
					)
				),
				$key === $scope ? ' class="current" aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '<h2>' . esc_html( 'products' === $scope ? __( 'Products', 'rankavia-woocommerce' ) : __( 'Categories', 'rankavia-woocommerce' ) ) . '</h2>';
		echo '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.

		$kind  = 'products' === $scope ? 'product' : 'product_cat';
		$total = $this->count( $kind, 'all' );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$rows  = array();
		$count = 0;
		foreach ( $this->items( $kind, 'all', $paged ) as $item ) {
			if ( 'product' === $kind ) {
				$product = wc_get_product( $item['id'] );
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$facts = $this->index->product_facts( $product );
			} else {
				$object = get_term( $item['id'], 'product_cat' );
				if ( ! $object instanceof \WP_Term ) {
					continue;
				}
				$facts = $this->index->term_facts( $object );
			}
			$found = Index_Policy_Check::evaluate( $facts );
			foreach ( $found as $finding ) {
				if ( Index_Policy_Check::PROBLEM === $finding['level'] ) {
					++$count;
					break;
				}
			}
			$rows[] = array( $item, $facts, $found );
		}
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: items with problems, 2: items checked on this page. */
					__( 'Items on this page with a problem: %1$s of %2$s.', 'rankavia-woocommerce' ),
					number_format_i18n( $count ),
					number_format_i18n( count( $rows ) )
				)
			)
		);

		$stock = array(
			'InStock'    => __( 'In stock', 'rankavia-woocommerce' ),
			'OutOfStock' => __( 'Out of stock', 'rankavia-woocommerce' ),
			'BackOrder'  => __( 'On backorder', 'rankavia-woocommerce' ),
		);
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Name', 'rankavia-woocommerce' ), 'product' === $kind ? __( 'Stock', 'rankavia-woocommerce' ) : __( 'Products', 'rankavia-woocommerce' ), __( 'Search engines', 'rankavia-woocommerce' ), __( 'Canonical', 'rankavia-woocommerce' ), __( 'Findings', 'rankavia-woocommerce' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Nothing to show.', 'rankavia-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			list( $item, $facts, $found ) = $row;
			echo '<tr><td>';
			if ( '' !== $item['link'] ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $item['link'] ), esc_html( $item['name'] ) );
			} else {
				echo esc_html( $item['name'] );
			}
			echo '</td><td>';
			if ( 'product' === $kind ) {
				echo esc_html( $stock[ $facts['stock'] ] ?? $facts['stock'] );
			} else {
				$object = get_term( $item['id'], 'product_cat' );
				echo esc_html( number_format_i18n( $object instanceof \WP_Term ? (int) $object->count : 0 ) );
			}
			echo '</td><td>' . esc_html( $this->index_label( $facts['noindex'] ) ) . '</td><td>';
			echo '' === $facts['canonical'] ? '<em>' . esc_html__( 'Own address', 'rankavia-woocommerce' ) . '</em>' : esc_html( $facts['canonical'] );
			echo '</td><td>';
			$this->index_findings( $found, __( 'No problems found.', 'rankavia-woocommerce' ) );
			printf(
				'</td><td><a class="button button-small" href="%1$s">%2$s</a></td></tr>',
				esc_url(
					wp_nonce_url(
						$this->url(
							array(
								'kind'   => 'indexing',
								'filter' => $scope,
								'paged'  => $paged,
								'product' === $kind ? 'live' : 'live_term' => $item['id'],
							)
						),
						'rankavia_wc_index_' . $kind . '_' . $item['id']
					)
				),
				esc_html__( 'Check live page', 'rankavia-woocommerce' )
			);
		}
		echo '</tbody></table>';
		$this->pager( 'indexing', $scope, $paged, $pages );
	}

	/**
	 * Store pages table, with live results when requested.
	 *
	 * @param bool $live Whether to fetch the pages now.
	 * @return void
	 */
	private function store_pages( bool $live ): void {
		$names  = array(
			'shop'      => __( 'Shop', 'rankavia-woocommerce' ),
			'cart'      => __( 'Cart', 'rankavia-woocommerce' ),
			'checkout'  => __( 'Checkout', 'rankavia-woocommerce' ),
			'myaccount' => __( 'My account', 'rankavia-woocommerce' ),
			'search'    => __( 'Product search results', 'rankavia-woocommerce' ),
		);
		$status = array(
			'publish' => __( 'Published', 'rankavia-woocommerce' ),
			'private' => __( 'Private', 'rankavia-woocommerce' ),
			'draft'   => __( 'Draft', 'rankavia-woocommerce' ),
		);
		$nonce  = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified next.
		$live   = $live && wp_verify_nonce( $nonce, 'rankavia_wc_index_store' );

		echo '<h2>' . esc_html__( 'Store pages', 'rankavia-woocommerce' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		$headings = array( __( 'Page', 'rankavia-woocommerce' ), __( 'Should be', 'rankavia-woocommerce' ), __( 'Search engines', 'rankavia-woocommerce' ), __( 'Findings', 'rankavia-woocommerce' ) );
		if ( $live ) {
			$headings[] = __( 'Live page', 'rankavia-woocommerce' );
		}
		foreach ( $headings as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $this->index->store_pages() as $page ) {
			$excluded = in_array( $page['key'], Index_Policy_Check::EXCLUDED, true );
			echo '<tr><td>';
			if ( '' !== $page['url'] ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $page['url'] ), esc_html( $names[ $page['key'] ] ) );
			} else {
				echo esc_html( $names[ $page['key'] ] );
			}
			if ( '' === $page['url'] ) {
				echo '<br><span class="description">' . esc_html__( 'Not set in WooCommerce settings', 'rankavia-woocommerce' ) . '</span>';
			} elseif ( 'publish' !== $page['status'] ) {
				echo '<br><span class="description">' . esc_html( $status[ $page['status'] ] ?? $page['status'] ) . '</span>';
			}
			echo '</td><td>' . esc_html( $excluded ? __( 'Kept out of search', 'rankavia-woocommerce' ) : __( 'Indexed', 'rankavia-woocommerce' ) ) . '</td>';
			echo '<td>' . esc_html( $this->index_label( $page['noindex'] ) ) . '</td><td>';
			$this->index_findings( $page['findings'], __( 'As expected.', 'rankavia-woocommerce' ) );
			echo '</td>';
			if ( $live ) {
				echo '<td>';
				$fetched = $this->index->page( $page['url'] );
				$this->live_summary( $fetched, Index_Policy_Check::evaluate_store_live( $page['key'], $fetched ) );
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		printf(
			'<p><a class="button" href="%1$s">%2$s</a> <span class="description">%3$s</span></p>',
			esc_url(
				wp_nonce_url(
					$this->url(
						array(
							'kind'       => 'indexing',
							'live_store' => 1,
						)
					),
					'rankavia_wc_index_store'
				)
			),
			esc_html__( 'Check store pages live', 'rankavia-woocommerce' ),
			esc_html__( 'Loads each page from your own site as a signed-out visitor.', 'rankavia-woocommerce' )
		);
	}

	/**
	 * Live check of one product or category.
	 *
	 * @param int $product Product ID, or 0.
	 * @param int $term    Category ID, or 0.
	 * @return void
	 */
	private function index_live( int $product, int $term ): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified next.
		$facts = null;
		$name  = '';
		if ( $product > 0 ) {
			$object = wc_get_product( $product );
			if ( wp_verify_nonce( $nonce, 'rankavia_wc_index_product_' . $product ) && $object instanceof \WC_Product && current_user_can( 'edit_post', $product ) ) {
				$facts = $this->index->product_facts( $object );
				$name  = $object->get_name();
			}
		} else {
			$object = get_term( $term, 'product_cat' );
			if ( wp_verify_nonce( $nonce, 'rankavia_wc_index_product_cat_' . $term ) && $object instanceof \WP_Term ) {
				$facts = $this->index->term_facts( $object );
				$name  = $object->name;
			}
		}
		if ( null === $facts ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The page check link expired or you cannot edit this item.', 'rankavia-woocommerce' ) . '</p></div>';
			return;
		}
		$result = $this->index->page( $facts['url'] );
		echo '<div class="notice notice-info"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: product or category name. */
				__( 'Live page check: %s', 'rankavia-woocommerce' ),
				$name
			)
		) . '</strong> <a href="' . esc_url( $facts['url'] ) . '">' . esc_html( $facts['url'] ) . '</a></p>';
		$this->live_summary( $result, Index_Policy_Check::evaluate_live( $result, $facts ) );
		echo '<p class="description">' . esc_html__( 'Fetched now as a signed-out visitor; a page cache may still serve older pages to others.', 'rankavia-woocommerce' ) . '</p></div>';
	}

	/**
	 * What a fetched page printed and the findings about it.
	 *
	 * @param array<string,mixed>                                     $result Result of Index_Policy_Check::page().
	 * @param array<int,array{level:string,code:string,value:string}> $found  Findings.
	 * @return void
	 */
	private function live_summary( array $result, array $found ): void {
		if ( 'ok' !== $result['status'] && 'redirect' !== $result['status'] ) {
			echo '<p>' . esc_html__( 'The page could not be loaded from this server, so it was not checked. It may be private, password protected or blocked for loopback requests.', 'rankavia-woocommerce' ) . '</p>';
			return;
		}
		if ( 'ok' === $result['status'] ) {
			$robots = (array) $result['found'];
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: robots directives, or "none". */
					__( 'Robots directives: %s', 'rankavia-woocommerce' ),
					array() === $robots ? __( 'none (indexable)', 'rankavia-woocommerce' ) : implode( '; ', $robots )
				)
			) . '</p>';
			if ( 'ok' !== $result['robots']['status'] && 'none' !== $result['robots']['status'] ) {
				echo '<p class="description">' . esc_html__( 'Robots.txt could not be loaded, so crawl rules were not checked.', 'rankavia-woocommerce' ) . '</p>';
			}
		}
		$this->index_findings( $found, __( 'No problems found.', 'rankavia-woocommerce' ) );
	}

	/**
	 * Indexing setting label.
	 *
	 * @param bool|null $noindex Configured noindex.
	 * @return string
	 */
	private function index_label( ?bool $noindex ): string {
		if ( null === $noindex ) {
			return __( 'Not readable here', 'rankavia-woocommerce' );
		}
		return $noindex ? __( 'Noindex', 'rankavia-woocommerce' ) : __( 'Indexed', 'rankavia-woocommerce' );
	}

	/** Save the sitemap category and store page policy. */
	public function handle_sitemap_save(): void {
		$back  = $this->url( array( 'kind' => 'sitemap' ) );
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $nonce, 'rankavia_wc_sitemap_save' ) || ! $this->policy->applies() ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			$this->finish( add_query_arg( 'result', 'denied', $back ) );
			return;
		}
		$exclude = isset( $_POST['exclude'] ) && is_array( $_POST['exclude'] ) ? array_map( 'absint', wp_unslash( $_POST['exclude'] ) ) : array();
		update_option(
			Sitemap_Policy::OPTION,
			Sitemap_Policy::clean(
				array(
					'categories'  => isset( $_POST['categories'] ) ? sanitize_key( wp_unslash( $_POST['categories'] ) ) : 'listed',
					'exclude'     => $exclude,
					'store_pages' => ! empty( $_POST['store_pages'] ),
				)
			),
			false
		);
		$this->finish( add_query_arg( 'result', 'sitemap_saved', $back ) );
	}

	/**
	 * Sitemap audit and policy.
	 *
	 * @param int  $paged Page number.
	 * @param bool $fetch Whether to read another SEO plugin's sitemap now.
	 * @return void
	 */
	private function sitemap_tab( int $paged, bool $fetch ): void {
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified next.
		$fetch = $fetch && wp_verify_nonce( $nonce, 'rankavia_wc_sitemap_fetch' );

		echo '<h2>' . esc_html__( 'XML sitemap', 'rankavia-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The sitemap lists the pages you want search engines to find. Products and categories that can be indexed should be in it; noindex, password-protected and cart, checkout or account pages should not. Everything here runs on your own site and needs no Rankavia account.', 'rankavia-woocommerce' ) . '</p>';
		printf(
			'<p>%1$s <a href="%2$s">%2$s</a></p>',
			esc_html(
				$this->sitemap->local()
					? ( Seo_Owner::RANKAVIA === $this->owner->kind() ? __( 'Sitemap: WordPress sitemap, shaped by Rankavia SEO:', 'rankavia-woocommerce' ) : __( 'Sitemap: WordPress sitemap:', 'rankavia-woocommerce' ) )
					/* translators: %s: SEO plugin name. */
					: sprintf( __( 'Sitemap: %s sitemap:', 'rankavia-woocommerce' ), $this->owner->name() )
			),
			esc_url( $this->sitemap->index_url() )
		);

		$data = $this->sitemap->collect( $fetch );
		if ( ! $this->sitemap->local() ) {
			printf(
				'<p><a class="button" href="%1$s">%2$s</a> <span class="description">%3$s</span></p>',
				esc_url(
					wp_nonce_url(
						$this->url(
							array(
								'kind'  => 'sitemap',
								'fetch' => 1,
							)
						),
						'rankavia_wc_sitemap_fetch'
					)
				),
				esc_html__( 'Check sitemap live', 'rankavia-woocommerce' ),
				esc_html__( 'Reads the sitemap files for products, categories and pages from your own site.', 'rankavia-woocommerce' )
			);
		}
		if ( 'disabled' === $data['status'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'WordPress sitemaps are turned off on this site, so no products are listed. They are off when Settings, Reading discourages search engines, or when a plugin or Rankavia SEO setting turns them off.', 'rankavia-woocommerce' ) . '</p></div>';
		} elseif ( 'unreachable' === $data['status'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The sitemap could not be loaded from this server. It may be turned off, at another address, or blocked for loopback requests.', 'rankavia-woocommerce' ) . '</p></div>';
		} elseif ( 'partial' === $data['status'] ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The sitemap has many files; only the first 30 were read, so some products may show as missing.', 'rankavia-woocommerce' ) . '</p></div>';
		}
		$checked = in_array( $data['status'], array( 'ok', 'partial' ), true );

		if ( $checked ) {
			$this->sitemap_summary( $data );
		}
		$this->sitemap_policy_form();
		if ( ! $checked ) {
			return;
		}

		$total = $this->count( 'product', 'all' );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );
		$stock = array(
			'InStock'    => __( 'In stock', 'rankavia-woocommerce' ),
			'OutOfStock' => __( 'Out of stock', 'rankavia-woocommerce' ),
			'BackOrder'  => __( 'On backorder', 'rankavia-woocommerce' ),
		);
		echo '<h2>' . esc_html__( 'Products', 'rankavia-woocommerce' ) . '</h2><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Product', 'rankavia-woocommerce' ), __( 'Stock', 'rankavia-woocommerce' ), __( 'Search engines', 'rankavia-woocommerce' ), __( 'In sitemap', 'rankavia-woocommerce' ), __( 'Findings', 'rankavia-woocommerce' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$items = $this->items( 'product', 'all', $paged );
		if ( array() === $items ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Nothing to show.', 'rankavia-woocommerce' ) . '</td></tr>';
		}
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['id'] );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$facts  = $this->index->product_facts( $product );
			$listed = isset( $data['urls'][ Index_Policy_Check::normalize( $facts['url'] ) ] );
			printf( '<tr><td><a href="%1$s">%2$s</a></td>', esc_url( $item['edit'] ), esc_html( $item['name'] ) );
			echo '<td>' . esc_html( $stock[ $facts['stock'] ] ?? $facts['stock'] ) . '</td>';
			echo '<td>' . esc_html( $this->index_label( $facts['noindex'] ) ) . '</td>';
			echo '<td>' . esc_html( $listed ? __( 'Yes', 'rankavia-woocommerce' ) : __( 'No', 'rankavia-woocommerce' ) ) . '</td><td>';
			$this->sitemap_findings( Sitemap_Check::evaluate( $facts, $listed ), Sitemap_Check::expected( $facts ) || $listed ? __( 'As expected.', 'rankavia-woocommerce' ) : __( 'Left out, as expected.', 'rankavia-woocommerce' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pager( 'sitemap', 'all', $paged, $pages );
	}

	/**
	 * Whole-shop sitemap counts and the items that need attention.
	 *
	 * @param array{status:string,source:string,urls:array<string,bool>,products:bool|null,categories:bool|null,files:int} $data Sitemap data.
	 * @return void
	 */
	private function sitemap_summary( array $data ): void {
		$products = 0;
		$listed   = 0;
		$missing  = array();
		$wrong    = array();
		$query    = new \WP_Query(
			array(
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'posts_per_page'         => 5000, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- IDs only, one admin summary, capped.
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $id ) {
			$product = wc_get_product( (int) $id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			++$products;
			$facts = $this->index->product_facts( $product );
			$in    = isset( $data['urls'][ Index_Policy_Check::normalize( $facts['url'] ) ] );
			if ( $in ) {
				++$listed;
			}
			foreach ( Sitemap_Check::evaluate( $facts, $in ) as $finding ) {
				if ( Sitemap_Check::PROBLEM === $finding['level'] ) {
					if ( $in ) {
						$wrong[] = $product->get_name();
					} else {
						$missing[] = $product->get_name();
					}
					break;
				}
			}
		}

		$categories = 0;
		$cat_listed = 0;
		$cat_wrong  = array();
		$excluded   = Sitemap_Policy::settings()['exclude'];
		$terms      = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 1000,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			++$categories;
			$facts = $this->index->term_facts( $term );
			$in    = isset( $data['urls'][ Index_Policy_Check::normalize( $facts['url'] ) ] );
			if ( $in ) {
				++$cat_listed;
			}
			// A category left out by the chosen policy is not a problem.
			$by_policy = ! $in && ( false === $data['categories'] || ( $this->policy->applies() && in_array( (int) $term->term_id, $excluded, true ) ) );
			foreach ( $by_policy ? array() : Sitemap_Check::evaluate( $facts, $in ) as $finding ) {
				if ( Sitemap_Check::PROBLEM === $finding['level'] ) {
					/* translators: 1: category name, 2: finding. */
					$cat_wrong[] = sprintf( __( '%1$s: %2$s', 'rankavia-woocommerce' ), $term->name, Sitemap_Check::message( $finding ) );
					break;
				}
			}
		}

		$stores = array();
		foreach ( Sitemap_Policy::store_page_ids() as $id ) {
			if ( isset( $data['urls'][ Index_Policy_Check::normalize( (string) get_permalink( $id ) ) ] ) ) {
				$stores[] = get_the_title( $id );
			}
		}

		echo '<h2>' . esc_html__( 'Summary', 'rankavia-woocommerce' ) . '</h2><ul class="rankavia-wc-health">';
		$lines = array(
			/* translators: 1: products in the sitemap, 2: published products. */
			sprintf( __( 'Published products in the sitemap: %1$s of %2$s.', 'rankavia-woocommerce' ), number_format_i18n( $listed ), number_format_i18n( $products ) ),
			/* translators: 1: categories in the sitemap, 2: product categories. */
			sprintf( __( 'Product categories in the sitemap: %1$s of %2$s.', 'rankavia-woocommerce' ), number_format_i18n( $cat_listed ), number_format_i18n( $categories ) ),
		);
		if ( $products >= 5000 ) {
			$lines[] = __( 'Only the first 5,000 products were counted.', 'rankavia-woocommerce' );
		}
		if ( false === $data['products'] ) {
			$lines[] = __( 'Products are not part of the sitemap at all.', 'rankavia-woocommerce' );
		}
		if ( false === $data['categories'] ) {
			$lines[] = __( 'Product categories are left out of the sitemap by its settings.', 'rankavia-woocommerce' );
		}
		foreach ( $lines as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul>';
		$groups = array(
			array( __( 'Indexable products missing from the sitemap:', 'rankavia-woocommerce' ), $missing ),
			array( __( 'Products in the sitemap that should not be:', 'rankavia-woocommerce' ), $wrong ),
			array( __( 'Categories that need attention:', 'rankavia-woocommerce' ), $cat_wrong ),
			array( __( 'Cart, checkout or account pages in the sitemap (they are noindex):', 'rankavia-woocommerce' ), $stores ),
		);
		foreach ( $groups as $group ) {
			if ( array() === $group[1] ) {
				continue;
			}
			$shown = array_slice( $group[1], 0, 20 );
			$more  = count( $group[1] ) - count( $shown );
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html( $group[0] ) . '</strong> ' . esc_html( implode( ', ', $shown ) );
			if ( $more > 0 ) {
				/* translators: %s: number of further items. */
				echo ' ' . esc_html( sprintf( __( 'and %s more.', 'rankavia-woocommerce' ), number_format_i18n( $more ) ) );
			}
			echo '</p></div>';
		}
		if ( array() === $missing && array() === $wrong && array() === $cat_wrong && array() === $stores ) {
			echo '<p>' . esc_html__( 'The sitemap lists the products and categories it should, and no store pages.', 'rankavia-woocommerce' ) . '</p>';
		}
	}

	/** Category and store page policy form, or where to set it when another plugin owns the sitemap. */
	private function sitemap_policy_form(): void {
		echo '<h2>' . esc_html__( 'Sitemap policy', 'rankavia-woocommerce' ) . '</h2>';
		if ( ! $this->policy->applies() ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: SEO plugin name. */
					__( '%s builds the sitemap on this site. Choose which product categories it lists in its settings; this add-on does not change it.', 'rankavia-woocommerce' ),
					$this->owner->name()
				)
			) . '</p>';
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		$settings = Sitemap_Policy::settings();
		$self     = Seo_Owner::SELF === $this->owner->kind();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rankavia_wc_sitemap_save' );
		echo '<input type="hidden" name="action" value="rankavia_wc_sitemap_save" /><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Product categories', 'rankavia-woocommerce' ) . '</th><td>';
		if ( $self ) {
			foreach ( array(
				'listed' => __( 'List categories that have products (WordPress default)', 'rankavia-woocommerce' ),
				'none'   => __( 'List no category pages', 'rankavia-woocommerce' ),
			) as $value => $label ) {
				printf(
					'<label><input type="radio" name="categories" value="%1$s"%2$s /> %3$s</label><br>',
					esc_attr( $value ),
					checked( $settings['categories'], $value, false ),
					esc_html( $label )
				);
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Rankavia SEO decides whether product categories are listed (Sitemaps settings). You can still leave out single categories below.', 'rankavia-woocommerce' ) . '</p>';
			echo '<input type="hidden" name="categories" value="' . esc_attr( $settings['categories'] ) . '" />';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'name',
			)
		);
		if ( is_array( $terms ) && array() !== $terms ) {
			echo '<fieldset style="margin-top:8px"><legend>' . esc_html__( 'Leave these categories out:', 'rankavia-woocommerce' ) . '</legend>';
			foreach ( $terms as $term ) {
				if ( $term instanceof \WP_Term ) {
					printf(
						'<label style="display:inline-block;min-width:200px"><input type="checkbox" name="exclude[]" value="%1$d"%2$s /> %3$s</label> ',
						(int) $term->term_id,
						checked( in_array( (int) $term->term_id, $settings['exclude'], true ), true, false ),
						esc_html( $term->name )
					);
				}
			}
			echo '</fieldset>';
		}
		echo '<p class="description">' . esc_html__( 'Categories without products are never listed by WordPress.', 'rankavia-woocommerce' ) . '</p></td></tr>';
		printf(
			'<tr><th scope="row">%1$s</th><td><label><input type="checkbox" name="store_pages" value="1"%2$s /> %3$s</label></td></tr>',
			esc_html__( 'Store pages', 'rankavia-woocommerce' ),
			checked( $settings['store_pages'], true, false ),
			esc_html__( 'Leave cart, checkout and account pages out of the sitemap (recommended; they are noindex)', 'rankavia-woocommerce' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save sitemap settings', 'rankavia-woocommerce' ) );
		echo '</form>';
	}

	/**
	 * Sitemap findings list.
	 *
	 * @param array<int,array{level:string,code:string}> $found Findings.
	 * @param string                                     $none  Text when there are none.
	 * @return void
	 */
	private function sitemap_findings( array $found, string $none ): void {
		if ( array() === $found ) {
			echo esc_html( $none );
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( $found as $finding ) {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html( Sitemap_Check::PROBLEM === $finding['level'] ? __( 'Problem:', 'rankavia-woocommerce' ) : __( 'Note:', 'rankavia-woocommerce' ) ),
				esc_html( Sitemap_Check::message( $finding ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Alt text of product images, one page of products. Read-only; alt text is edited in the Media Library.
	 *
	 * @param int $paged Page number.
	 * @return void
	 */
	private function image_alts( int $paged ): void {
		$total = $this->count( 'product', 'all' );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged = min( $paged, $pages );

		echo '<h2>' . esc_html__( 'Product image alt text', 'rankavia-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Alt text describes an image for shoppers who use screen readers and for search engines, which cannot see the picture. Describe what the image shows in a short sentence: the product, and the colour, angle or detail this photo adds. Do not repeat the product name on every image or add lists of keywords. Alt text belongs to the image in the Media Library, so a change applies wherever that image is used. Nothing is filled in automatically.', 'rankavia-woocommerce' ) . '</p>';

		$rows    = array();
		$all     = 0;
		$missing = 0;
		foreach ( $this->items( 'product', 'all', $paged ) as $item ) {
			$product = wc_get_product( $item['id'] );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$images = $this->images->images( $product );
			$found  = Image_Alt_Check::evaluate( $images );
			foreach ( $images as $image ) {
				++$all;
				if ( '' === $image['alt'] ) {
					++$missing;
				}
			}
			$rows[] = array( $item, $images, $found );
		}
		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: images without alt text, 2: images of products on this page. */
					__( 'Images without alt text on this page: %1$s of %2$s.', 'rankavia-woocommerce' ),
					number_format_i18n( $missing ),
					number_format_i18n( $all )
				)
			)
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'Product', 'rankavia-woocommerce' ), __( 'Images', 'rankavia-woocommerce' ) ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( array() === $rows ) {
			echo '<tr><td colspan="2">' . esc_html__( 'Nothing to show.', 'rankavia-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rows as $row ) {
			list( $item, $images, $found ) = $row;
			echo '<tr><td style="width:22%">';
			if ( '' !== $item['edit'] ) {
				printf( '<a href="%1$s">%2$s</a>', esc_url( $item['edit'] ), esc_html( $item['name'] ) );
			} else {
				echo esc_html( $item['name'] );
			}
			echo '</td><td>';
			if ( array() === $images ) {
				echo '<em>' . esc_html__( 'No product image or gallery.', 'rankavia-woocommerce' ) . '</em>';
			}
			foreach ( $images as $image ) {
				echo '<div style="display:flex;gap:10px;align-items:flex-start;margin:0 0 8px">';
				echo wp_get_attachment_image( $image['id'], array( 48, 48 ), false, array( 'alt' => '' ) );
				echo '<div><strong>' . esc_html( $image['main'] ? __( 'Main image', 'rankavia-woocommerce' ) : __( 'Gallery image', 'rankavia-woocommerce' ) ) . '</strong> <span class="description">' . esc_html( $image['file'] ) . '</span><br>';
				echo '' === $image['alt'] ? '<em>' . esc_html__( 'No alt text', 'rankavia-woocommerce' ) . '</em>' : '&ldquo;' . esc_html( $image['alt'] ) . '&rdquo;';
				$edit = get_edit_post_link( $image['id'], 'raw' );
				if ( is_string( $edit ) && '' !== $edit ) {
					printf( ' <a href="%1$s">%2$s</a>', esc_url( $edit ), esc_html__( 'Edit alt text', 'rankavia-woocommerce' ) );
				}
				foreach ( $found[ $image['id'] ] ?? array() as $finding ) {
					echo '<br><span' . ( Image_Alt_Check::MISSING === $finding['level'] ? ' style="color:#b32d2e"' : '' ) . '>' . esc_html( Image_Alt_Check::message( $finding ) ) . '</span>';
				}
				echo '</div></div>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		$this->pager( 'images', 'all', $paged, $pages );
	}

	/**
	 * Indexing findings list.
	 *
	 * @param array<int,array{level:string,code:string,value:string}> $found Findings.
	 * @param string                                                  $none  Text when there are none.
	 * @return void
	 */
	private function index_findings( array $found, string $none ): void {
		if ( array() === $found ) {
			echo esc_html( $none );
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( $found as $finding ) {
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html( Index_Policy_Check::PROBLEM === $finding['level'] ? __( 'Problem:', 'rankavia-woocommerce' ) : __( 'Note:', 'rankavia-woocommerce' ) ),
				esc_html( Index_Policy_Check::message( $finding ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Findings list.
	 *
	 * @param array<int,array{level:string,code:string,graph:string,shop:string}> $findings Findings.
	 * @return void
	 */
	private function findings( array $findings ): void {
		if ( array() === $findings ) {
			echo '<p>' . esc_html__( 'Matches what the shop shows.', 'rankavia-woocommerce' ) . '</p>';
			return;
		}
		echo '<ul style="margin:0">';
		foreach ( $findings as $finding ) {
			$problem = Product_Graph_Check::PROBLEM === $finding['level'];
			printf(
				'<li><strong>%1$s</strong> %2$s</li>',
				esc_html( $problem ? __( 'Mismatch:', 'rankavia-woocommerce' ) : __( 'Missing:', 'rankavia-woocommerce' ) ),
				esc_html( Product_Graph_Check::message( $finding ) )
			);
		}
		echo '</ul>';
	}

	/**
	 * Table cell text.
	 *
	 * @param string|null $value Stored value.
	 * @param string      $none  Text when there is none.
	 * @return string Escaped HTML.
	 */
	private function cell( ?string $value, string $none ): string {
		return null === $value ? '<em>' . esc_html( $none ) . '</em>' : esc_html( $value );
	}

	/**
	 * Guidance notes for stored values. Lengths are guidance, not rules.
	 *
	 * @param array<string,string|null> $values Stored values.
	 * @return array<int,string>
	 */
	private function notes( array $values ): array {
		$notes = array();
		$title = $values['title'] ?? null;
		$desc  = $values['description'] ?? null;
		if ( null !== $title && mb_strlen( $title ) > self::TITLE_LONG ) {
			$notes[] = __( 'Long title; search results often shorten it.', 'rankavia-woocommerce' );
		}
		if ( null !== $desc && mb_strlen( $desc ) > self::DESCRIPTION_MAX ) {
			$notes[] = __( 'Long description; search results often shorten it.', 'rankavia-woocommerce' );
		}
		if ( null !== $desc && mb_strlen( $desc ) < self::DESCRIPTION_MIN ) {
			$notes[] = __( 'Short description.', 'rankavia-woocommerce' );
		}
		if ( Seo_Owner::SELF === $this->owner->kind() && ( ( null !== $title && Metadata_Output::has_variables( $title ) ) || ( null !== $desc && Metadata_Output::has_variables( $desc ) ) ) ) {
			$notes[] = __( 'Uses Rankavia SEO variables, so it is not printed without Rankavia SEO.', 'rankavia-woocommerce' );
		}
		return $notes;
	}

	/**
	 * Single item editor with a search result preview.
	 *
	 * @param string $kind Entity kind.
	 * @param int    $id   Entity ID.
	 * @return void
	 */
	private function editor( string $kind, int $id ): void {
		if ( ! $this->store->can_edit( $kind, $id ) ) {
			echo '<p>' . esc_html__( 'This item does not exist or you cannot edit it.', 'rankavia-woocommerce' ) . '</p>';
			return;
		}
		if ( 'product' === $kind ) {
			$name = get_the_title( $id );
			$link = (string) get_permalink( $id );
		} else {
			$term = get_term( $id, 'product_cat' );
			$name = $term instanceof \WP_Term ? $term->name : '';
			$link = $term instanceof \WP_Term && is_string( get_term_link( $term ) ) ? (string) get_term_link( $term ) : '';
		}
		$values = $this->store->read( $kind, $id );

		printf(
			'<p><a href="%1$s">&larr; %2$s</a></p><h2>%3$s</h2>',
			esc_url( $this->url( array( 'kind' => $kind ) ) ),
			esc_html__( 'Back to the list', 'rankavia-woocommerce' ),
			esc_html( $name )
		);
		if ( ! $this->owner->renders( $kind ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Saved category values will show once Rankavia SEO is updated.', 'rankavia-woocommerce' ) . '</p></div>';
		}

		echo '<div class="rankavia-wc-preview" style="max-width:600px;padding:12px 16px;border:1px solid #dcdcde;background:#fff;margin:12px 0">';
		echo '<p style="margin:0 0 4px;color:#1a0dab;font-size:18px">' . esc_html( $values['title'] ?? $name ) . '</p>';
		echo '<p style="margin:0 0 4px;color:#006621">' . esc_html( $link ) . '</p>';
		echo '<p style="margin:0;color:#4d5156">' . esc_html( $values['description'] ?? __( 'No description: search engines choose text from the page.', 'rankavia-woocommerce' ) ) . '</p>';
		echo '<p class="description" style="margin-top:8px">' . esc_html__( 'Preview of the saved values. Search engines may show different text.', 'rankavia-woocommerce' ) . '</p></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'rankavia_wc_meta_save_' . $kind . '_' . $id );
		printf(
			'<input type="hidden" name="action" value="rankavia_wc_meta_save" /><input type="hidden" name="kind" value="%1$s" /><input type="hidden" name="object" value="%2$d" /><input type="hidden" name="revision" value="%3$s" />',
			esc_attr( $kind ),
			(int) $id,
			esc_attr( Metadata_Store::revision( $values ) )
		);
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="rankavia-wc-title">%1$s</label></th><td><input type="text" class="large-text" id="rankavia-wc-title" name="title" value="%2$s" /><p class="description">%3$s</p></td></tr>',
			esc_html__( 'SEO title', 'rankavia-woocommerce' ),
			esc_attr( $values['title'] ?? '' ),
			esc_html__( 'The whole title shown in browser tabs and search results. Leave empty to use the default title.', 'rankavia-woocommerce' )
		);
		printf(
			'<tr><th scope="row"><label for="rankavia-wc-description">%1$s</label></th><td><textarea class="large-text" rows="3" id="rankavia-wc-description" name="description">%2$s</textarea><p class="description">%3$s</p></td></tr>',
			esc_html__( 'Meta description', 'rankavia-woocommerce' ),
			esc_textarea( $values['description'] ?? '' ),
			esc_html__( 'A short summary for search results. Leave empty to remove it.', 'rankavia-woocommerce' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Save', 'rankavia-woocommerce' ) );
		echo '</form>';
	}

	/** Recent changes the current user can undo. */
	private function history(): void {
		$rows = array();
		foreach ( $this->store->journal() as $entry ) {
			if ( count( $rows ) >= 10 ) {
				break;
			}
			if ( $this->store->can_edit( $entry['kind'], $entry['object'] ) ) {
				$rows[] = $entry;
			}
		}
		if ( array() === $rows ) {
			return;
		}
		$labels = array(
			'title'       => __( 'SEO title', 'rankavia-woocommerce' ),
			'description' => __( 'Meta description', 'rankavia-woocommerce' ),
		);
		$none   = __( '(none)', 'rankavia-woocommerce' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Only used as a redirect target after wp_validate_redirect.
		$back = isset( $_SERVER['REQUEST_URI'] ) ? remove_query_arg( 'result', esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ) : $this->url();

		echo '<h2>' . esc_html__( 'Recent changes', 'rankavia-woocommerce' ) . '</h2><table class="widefat striped"><thead><tr>';
		foreach ( array( __( 'When', 'rankavia-woocommerce' ), __( 'Item', 'rankavia-woocommerce' ), __( 'Change', 'rankavia-woocommerce' ), '' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $entry ) {
			if ( 'product' === $entry['kind'] ) {
				$name = get_the_title( $entry['object'] );
			} else {
				$term = get_term( $entry['object'], 'product_cat' );
				$name = $term instanceof \WP_Term ? $term->name : '';
			}
			echo '<tr><td>' . esc_html( wp_date( (string) get_option( 'date_format' ) . ' ' . (string) get_option( 'time_format' ), $entry['time'] ) ) . '</td>';
			echo '<td>' . esc_html( $name ) . '</td><td>';
			foreach ( $entry['changes'] as $field => $change ) {
				printf(
					'<div><strong>%1$s:</strong> %2$s &rarr; %3$s</div>',
					esc_html( $labels[ $field ] ?? $field ),
					esc_html( $change['before'] ?? $none ),
					esc_html( $change['after'] ?? $none )
				);
			}
			echo '</td><td>';
			if ( $entry['undone'] > 0 ) {
				echo esc_html__( 'Undone', 'rankavia-woocommerce' );
			} else {
				// The nonce field is written by hand: wp_nonce_field() would repeat the same element ID on every row.
				printf(
					'<form method="post" action="%1$s"><input type="hidden" name="_wpnonce" value="%2$s" /><input type="hidden" name="action" value="rankavia_wc_meta_undo" /><input type="hidden" name="entry" value="%3$s" /><input type="hidden" name="back" value="%4$s" /><button type="submit" class="button button-small">%5$s</button></form>',
					esc_url( admin_url( 'admin-post.php' ) ),
					esc_attr( wp_create_nonce( 'rankavia_wc_meta_undo_' . $entry['id'] ) ),
					esc_attr( $entry['id'] ),
					esc_attr( $back ),
					esc_html__( 'Undo', 'rankavia-woocommerce' )
				);
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
