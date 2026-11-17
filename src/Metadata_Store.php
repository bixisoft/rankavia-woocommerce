<?php
/**
 * Product and category SEO title and description, with a change journal and conditional undo.
 *
 * @package RankaviaWooCommerce
 */

declare( strict_types=1 );

namespace Bixisoft\RankaviaWooCommerce;

/**
 * Reads and writes the same title and description fields Rankavia SEO renders, so one value has one owner.
 *
 * A save is refused when the stored values changed after the form was opened. Undo restores only
 * the fields a save changed, and only while they still hold what that save wrote.
 */
final class Metadata_Store {
	public const TITLE       = '_rankavia_title';
	public const DESCRIPTION = '_rankavia_description';
	public const JOURNAL     = 'rankavia_wc_meta_journal';
	public const JOURNAL_MAX = 100;

	/**
	 * Form field to meta key.
	 *
	 * @var array<string,string>
	 */
	public const FIELDS = array(
		'title'       => self::TITLE,
		'description' => self::DESCRIPTION,
	);

	/**
	 * Entity kinds and their meta type.
	 *
	 * @var array<string,string>
	 */
	private const KINDS = array(
		'product'     => 'post',
		'product_cat' => 'term',
	);

	/**
	 * Whether the kind is supported.
	 *
	 * @param string $kind Entity kind.
	 * @return bool
	 */
	public static function valid_kind( string $kind ): bool {
		return isset( self::KINDS[ $kind ] );
	}

	/**
	 * Whether the entity exists as the kind says.
	 *
	 * @param string $kind Entity kind.
	 * @param int    $id   Entity ID.
	 * @return bool
	 */
	public function exists( string $kind, int $id ): bool {
		if ( $id < 1 || ! self::valid_kind( $kind ) ) {
			return false;
		}
		if ( 'product' === $kind ) {
			return 'product' === get_post_type( $id );
		}
		return get_term( $id, 'product_cat' ) instanceof \WP_Term;
	}

	/**
	 * Whether the current user may edit this entity's SEO fields.
	 *
	 * @param string $kind Entity kind.
	 * @param int    $id   Entity ID.
	 * @return bool
	 */
	public function can_edit( string $kind, int $id ): bool {
		if ( ! $this->exists( $kind, $id ) ) {
			return false;
		}
		return 'product' === $kind
			? (bool) current_user_can( 'edit_post', $id )
			: (bool) current_user_can( 'edit_term', $id );
	}

	/**
	 * Stored values; null when a field has no value of its own.
	 *
	 * @param string $kind Entity kind.
	 * @param int    $id   Entity ID.
	 * @return array<string,string|null>
	 */
	public function read( string $kind, int $id ): array {
		$type   = self::KINDS[ $kind ] ?? 'post';
		$values = array();
		foreach ( self::FIELDS as $field => $key ) {
			$value            = metadata_exists( $type, $id, $key ) ? (string) get_metadata( $type, $id, $key, true ) : null;
			$values[ $field ] = null === $value || '' === trim( $value ) ? null : $value;
		}
		return $values;
	}

	/**
	 * Fingerprint of stored values, sent with the form and compared on save.
	 *
	 * @param array<string,string|null> $values Stored values.
	 * @return string
	 */
	public static function revision( array $values ): string {
		$parts = array();
		foreach ( array_keys( self::FIELDS ) as $field ) {
			$parts[] = null === ( $values[ $field ] ?? null ) ? "\0" : (string) $values[ $field ];
		}
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	/**
	 * Clean an entered value: plain text on one line; empty means no value of its own.
	 *
	 * @param string $value Entered value.
	 * @return string|null
	 */
	public static function clean( string $value ): ?string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', sanitize_text_field( $value ) ) );
		return '' === $value ? null : $value;
	}

	/**
	 * Save new values for one entity.
	 *
	 * @param string                    $kind     Entity kind.
	 * @param int                       $id       Entity ID.
	 * @param array<string,string|null> $wanted   Field to cleaned value; null removes the field's own value.
	 * @param string                    $revision Revision the form was opened with.
	 * @return array{status:string,entry:string}
	 */
	public function apply( string $kind, int $id, array $wanted, string $revision ): array {
		if ( ! $this->can_edit( $kind, $id ) ) {
			return array(
				'status' => 'denied',
				'entry'  => '',
			);
		}
		$before = $this->read( $kind, $id );
		if ( ! hash_equals( self::revision( $before ), $revision ) ) {
			return array(
				'status' => 'stale',
				'entry'  => '',
			);
		}

		$changes = array();
		foreach ( self::FIELDS as $field => $key ) {
			if ( ! array_key_exists( $field, $wanted ) || $wanted[ $field ] === $before[ $field ] ) {
				continue;
			}
			$this->write( $kind, $id, $key, $wanted[ $field ] );
			$changes[ $field ] = array( 'before' => $before[ $field ] );
		}
		if ( array() === $changes ) {
			return array(
				'status' => 'unchanged',
				'entry'  => '',
			);
		}

		// The journal keeps what was actually stored, after any sanitizing filter.
		$after = $this->read( $kind, $id );
		foreach ( array_keys( $changes ) as $field ) {
			$changes[ $field ]['after'] = $after[ $field ];
		}

		$entry = bin2hex( random_bytes( 8 ) );
		$this->record(
			array(
				'id'      => $entry,
				'kind'    => $kind,
				'object'  => $id,
				'user'    => get_current_user_id(),
				'time'    => time(),
				'changes' => $changes,
				'undone'  => 0,
			)
		);
		return array(
			'status' => 'saved',
			'entry'  => $entry,
		);
	}

	/**
	 * Undo one saved change while its fields still hold what it wrote.
	 *
	 * @param string $entry_id Journal entry ID.
	 * @return string Status: undone, conflict, denied, missing or already.
	 */
	public function undo( string $entry_id ): string {
		$journal = $this->journal();
		foreach ( $journal as $index => $entry ) {
			if ( $entry['id'] !== $entry_id ) {
				continue;
			}
			if ( $entry['undone'] > 0 ) {
				return 'already';
			}
			if ( ! $this->can_edit( $entry['kind'], $entry['object'] ) ) {
				return 'denied';
			}
			$current = $this->read( $entry['kind'], $entry['object'] );
			foreach ( $entry['changes'] as $field => $change ) {
				if ( ( $current[ $field ] ?? null ) !== $change['after'] ) {
					return 'conflict';
				}
			}
			foreach ( $entry['changes'] as $field => $change ) {
				$this->write( $entry['kind'], $entry['object'], self::FIELDS[ $field ], $change['before'] );
			}
			$journal[ $index ]['undone'] = time();
			update_option( self::JOURNAL, $journal, false );
			return 'undone';
		}
		return 'missing';
	}

	/**
	 * Journal entries, newest first.
	 *
	 * @return array<int,array{id:string,kind:string,object:int,user:int,time:int,changes:array<string,array{before:string|null,after:string|null}>,undone:int}>
	 */
	public function journal(): array {
		$stored = get_option( self::JOURNAL, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$entries = array();
		foreach ( $stored as $entry ) {
			if (
				is_array( $entry )
				&& isset( $entry['id'], $entry['kind'], $entry['object'], $entry['changes'] )
				&& is_string( $entry['id'] )
				&& self::valid_kind( (string) $entry['kind'] )
				&& is_array( $entry['changes'] )
			) {
				$changes = array();
				foreach ( $entry['changes'] as $field => $change ) {
					if ( isset( self::FIELDS[ $field ] ) && is_array( $change ) && array_key_exists( 'before', $change ) && array_key_exists( 'after', $change ) ) {
						$changes[ (string) $field ] = array(
							'before' => null === $change['before'] ? null : (string) $change['before'],
							'after'  => null === $change['after'] ? null : (string) $change['after'],
						);
					}
				}
				$entries[] = array(
					'id'      => $entry['id'],
					'kind'    => (string) $entry['kind'],
					'object'  => (int) $entry['object'],
					'user'    => (int) ( $entry['user'] ?? 0 ),
					'time'    => (int) ( $entry['time'] ?? 0 ),
					'changes' => $changes,
					'undone'  => (int) ( $entry['undone'] ?? 0 ),
				);
			}
		}
		return $entries;
	}

	/**
	 * Store or remove one field.
	 *
	 * @param string      $kind  Entity kind.
	 * @param int         $id    Entity ID.
	 * @param string      $key   Meta key.
	 * @param string|null $value Value; null removes it.
	 * @return void
	 */
	private function write( string $kind, int $id, string $key, ?string $value ): void {
		$type = self::KINDS[ $kind ];
		if ( null === $value ) {
			delete_metadata( $type, $id, $key );
			return;
		}
		update_metadata( $type, $id, $key, wp_slash( $value ) );
	}

	/**
	 * Add an entry to the front of the bounded journal.
	 *
	 * @param array<string,mixed> $entry Entry.
	 * @return void
	 */
	private function record( array $entry ): void {
		$journal = $this->journal();
		array_unshift( $journal, $entry );
		update_option( self::JOURNAL, array_slice( $journal, 0, self::JOURNAL_MAX ), false );
	}
}
