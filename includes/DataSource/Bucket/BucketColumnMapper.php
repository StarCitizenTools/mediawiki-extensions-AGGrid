<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

/**
 * Maps a Bucket field type (and its repeated flag) to an AG Grid columnDef and a
 * filter family.
 *
 * This class is pure: it depends only on the Bucket value-type string
 * ('PAGE'|'TEXT'|'INTEGER'|'DOUBLE'|'BOOLEAN') and has no dependency on the Bucket
 * extension or a running wiki instance.
 *
 * Bucket's query language has no LIKE/substring operator, so text columns get a set
 * filter (enumerated distinct values) rather than a text-contains filter, and numeric
 * columns get a range filter. Repeated fields always get a set filter: Bucket only
 * routes a repeated-field condition through its subquery path when the condition sits
 * inside an OR/AND group (which the set filter emits) — a range/bare condition would
 * instead hit the JSON-encoded main column.
 */
class BucketColumnMapper {

	/**
	 * Descriptor shape: [ 'type' => string|null, 'filter' => string|false, 'family' => string ]
	 *
	 * 'type'   — AG Grid column type string, or null to omit the key entirely.
	 * 'filter' — AG Grid filter component name, or false to disable filtering.
	 * 'family' — filter family for use by BucketFilterTranslator ('set'|'number'|'none').
	 *
	 * @var array<string, array{type: string|null, filter: string|false, family: string}>
	 */
	private const TYPE_MAP = [
		'PAGE'    => [ 'type' => 'aggridLink', 'filter' => 'aggridSet', 'family' => 'set' ],
		'TEXT'    => [ 'type' => null, 'filter' => 'aggridSet', 'family' => 'set' ],
		'BOOLEAN' => [ 'type' => null, 'filter' => 'aggridSet', 'family' => 'set' ],
		'INTEGER' => [ 'type' => 'numericColumn', 'filter' => 'agNumberColumnFilter', 'family' => 'number' ],
		'DOUBLE'  => [ 'type' => 'numericColumn', 'filter' => 'agNumberColumnFilter', 'family' => 'number' ],
	];

	/**
	 * Fallback descriptor for any unknown Bucket type id.
	 *
	 * @var array{type: null, filter: false, family: string}
	 */
	private const FALLBACK = [ 'type' => null, 'filter' => false, 'family' => 'none' ];

	/**
	 * Resolve the descriptor for a given Bucket type id and repeated flag.
	 *
	 * @return array{type: string|null, filter: string|false, family: string}
	 */
	private function descriptor( string $type, bool $repeated ): array {
		$desc = self::TYPE_MAP[$type] ?? self::FALLBACK;

		if ( $repeated && $desc['family'] !== 'none' ) {
			// Repeated fields always use a set filter (see class doc). Page values keep a
			// link-list renderer; other repeated scalars have no special renderer and are
			// displayed comma-joined.
			$desc['filter'] = 'aggridSet';
			$desc['family'] = 'set';
			$desc['type'] = $type === 'PAGE' ? 'aggridLinkList' : null;
		}

		return $desc;
	}

	/**
	 * Build an AG Grid columnDef array for the given field, header, Bucket type and
	 * repeated flag.
	 *
	 * @param string $field AG Grid field key (colId).
	 * @param string $header Human-readable column header.
	 * @param string $type Bucket value type id (e.g. 'PAGE', 'INTEGER').
	 * @param bool $repeated Whether the Bucket field is repeated (multi-valued).
	 * @return array<string, mixed> Partial AG Grid columnDef.
	 */
	public function mapColumn( string $field, string $header, string $type, bool $repeated ): array {
		$desc = $this->descriptor( $type, $repeated );

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
	 * Resolve the AG Grid filter component for a Bucket type id, or false when the
	 * datatype does not support filtering.
	 */
	public function filterComponent( string $type, bool $repeated ): string|false {
		return $this->descriptor( $type, $repeated )['filter'];
	}

	/**
	 * Classify a Bucket type id into a filter family: 'set' | 'number' | 'none'.
	 */
	public function filterFamily( string $type, bool $repeated ): string {
		return $this->descriptor( $type, $repeated )['family'];
	}
}
