<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use MediaWiki\Extension\AGGrid\DataSource\ColumnDescriptor;

/**
 * Maps a Semantic MediaWiki property datatype id to an AG Grid columnDef.
 *
 * This class is pure: it depends only on the type id string and has no
 * dependency on SMW classes or a running wiki instance.
 */
class TypeColumnMapper {

	/**
	 * Per-type {@see ColumnDescriptor} fields, keyed by SMW datatype id.
	 *
	 * @var array<string, array{type: string|null, filter: string|false, family: string}>
	 */
	private const TYPE_MAP = [
		// Page
		'_wpg'  => [ 'type' => 'aggridLink', 'filter' => 'aggridSet', 'family' => 'set' ],
		// Text / Code / Keyword
		'_txt'  => [ 'type' => null, 'filter' => 'agTextColumnFilter', 'family' => 'text' ],
		'_cod'  => [ 'type' => null, 'filter' => 'agTextColumnFilter', 'family' => 'text' ],
		'_keyw' => [ 'type' => null, 'filter' => 'agTextColumnFilter', 'family' => 'text' ],
		// Number / Quantity / Temperature
		'_num'  => [ 'type' => 'numericColumn', 'filter' => 'agNumberColumnFilter', 'family' => 'number' ],
		'_qty'  => [ 'type' => 'numericColumn', 'filter' => 'agNumberColumnFilter', 'family' => 'number' ],
		'_tem'  => [ 'type' => 'numericColumn', 'filter' => 'agNumberColumnFilter', 'family' => 'number' ],
		// Date / time
		'_dat'  => [ 'type' => null, 'filter' => 'agDateColumnFilter', 'family' => 'date' ],
		// Boolean
		'_boo'  => [ 'type' => null, 'filter' => 'aggridSet', 'family' => 'boolean' ],
		// URL / Email / Telephone
		'_uri'  => [ 'type' => 'aggridLink', 'filter' => 'agTextColumnFilter', 'family' => 'text' ],
		'_ema'  => [ 'type' => 'aggridLink', 'filter' => 'agTextColumnFilter', 'family' => 'text' ],
		'_tel'  => [ 'type' => 'aggridLink', 'filter' => 'agTextColumnFilter', 'family' => 'text' ],
		// Geographic coordinate — no filter, no special renderer
		'_geo'  => [ 'type' => null, 'filter' => false, 'family' => 'none' ],
	];

	/**
	 * Quick-search kind per searchable SMW type id; ids absent from the map are
	 * not searched (an explicit allowlist — substring/exact matching is only
	 * meaningful for these datatypes).
	 *
	 * @var array<string,string>
	 */
	private const SEARCH_MAP = [
		// Page → substring LIKE on the page name.
		'_wpg'  => 'like-page',
		// Text / Code / Keyword → substring LIKE on the blob store.
		'_txt'  => 'like-text',
		'_cod'  => 'like-text',
		'_keyw' => 'like-text',
		// Date → the ~ comparator, expanded by SMW into a precision range.
		'_dat'  => 'like-date',
		// Number / Temperature → exact match (both parse a bare number).
		'_num'  => 'eq-number',
		'_tem'  => 'eq-number',
		// Deliberately absent: _uri (SMW anchors the pattern to the scheme, e.g.
		// ~http://*foo*), _ema / _tel (their query builders reject a ~*substring* and
		// return a match-everything ThingDescription), and _qty (a bare number is not a
		// valid quantity without a unit). Substring/exact search on these silently
		// matches nothing, so the box does not offer them.
	];

	/**
	 * Resolve the {@see ColumnDescriptor} for a given type id.
	 */
	private function descriptor( string $typeId ): ColumnDescriptor {
		// _rec itself and any compound id that embeds _rec (e.g. _mlt_rec, _ref_rec)
		if ( str_contains( $typeId, '_rec' ) ) {
			return ColumnDescriptor::fallback();
		}

		$row = self::TYPE_MAP[$typeId] ?? null;
		if ( $row === null ) {
			return ColumnDescriptor::fallback();
		}

		return new ColumnDescriptor( $row['type'], $row['filter'], $row['family'] );
	}

	/**
	 * Build an AG Grid columnDef array for the given field, header, and SMW type id.
	 *
	 * @param string $field AG Grid field key (property name).
	 * @param string $header Human-readable column header.
	 * @param string $typeId SMW datatype id (e.g. '_wpg', '_num').
	 * @return array<string, mixed> Partial AG Grid columnDef.
	 */
	public function mapColumn( string $field, string $header, string $typeId ): array {
		return $this->descriptor( $typeId )->toColumnDef( $field, $header );
	}

	/**
	 * Resolve the AG Grid filter component for an SMW type id.
	 *
	 * Returns the component name (e.g. 'aggridSet'), or false when the datatype
	 * does not support filtering.
	 */
	public function filterComponent( string $typeId ): string|false {
		return $this->descriptor( $typeId )->filter;
	}

	/**
	 * Classify an SMW type id into a filter family.
	 *
	 * Returns one of: 'text' | 'number' | 'date' | 'boolean' | 'set' | 'none'.
	 *
	 * @param string $typeId SMW datatype id.
	 * @return string Filter family name.
	 */
	public function filterFamily( string $typeId ): string {
		return $this->descriptor( $typeId )->family;
	}

	/**
	 * Classify how a column of the given SMW type participates in quick search.
	 *
	 * Returns how the column's quick-search condition is built, or null when the
	 * datatype is not searched:
	 *  - 'like-text' — substring LIKE on the blob store (text, code, keyword);
	 *  - 'like-page' — substring LIKE on the page name (page values);
	 *  - 'like-date' — the ~ comparator, expanded by SMW into a precision range;
	 *  - 'eq-number' — exact match (number, temperature).
	 *
	 * URI, email, telephone, quantity, boolean, geographic, record/compound, and
	 * unknown types are not searched — substring/exact matching is unreliable or
	 * meaningless for them (see SEARCH_MAP for why each is excluded).
	 *
	 * @param string $typeId SMW datatype id.
	 * @return string|null One of like-text|like-page|like-date|eq-number, or null.
	 */
	public function searchKind( string $typeId ): ?string {
		return self::SEARCH_MAP[$typeId] ?? null;
	}
}
