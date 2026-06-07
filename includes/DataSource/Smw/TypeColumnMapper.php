<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

/**
 * Maps a Semantic MediaWiki property datatype id to an AG Grid columnDef.
 *
 * This class is pure: it depends only on the type id string and has no
 * dependency on SMW classes or a running wiki instance.
 */
class TypeColumnMapper {

	/**
	 * Descriptor shape: [ 'type' => string|null, 'filter' => string|false, 'family' => string ]
	 *
	 * 'type'   — AG Grid column type string, or null to omit the key entirely.
	 * 'filter' — AG Grid filter component name, or false to disable filtering.
	 * 'family' — filter family for use by FilterTranslator / SmwDataSource.
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
	 * Fallback descriptor used for _rec, any id containing '_rec', and any unknown id.
	 *
	 * @var array{type: null, filter: false, family: string}
	 */
	private const FALLBACK = [ 'type' => null, 'filter' => false, 'family' => 'none' ];

	/**
	 * Resolve the descriptor for a given type id.
	 *
	 * @return array{type: string|null, filter: string|false, family: string}
	 */
	private function descriptor( string $typeId ): array {
		// _rec itself and any compound id that embeds _rec (e.g. _mlt_rec, _ref_rec)
		if ( str_contains( $typeId, '_rec' ) ) {
			return self::FALLBACK;
		}

		return self::TYPE_MAP[$typeId] ?? self::FALLBACK;
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
		$desc = $this->descriptor( $typeId );

		$colDef = [
			'field'      => $field,
			'headerName' => $header,
			'filter'     => $desc['filter'],
		];

		if ( $desc['type'] !== null ) {
			$colDef['type'] = $desc['type'];
		}

		return $colDef;
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
		return $this->descriptor( $typeId )['family'];
	}
}
