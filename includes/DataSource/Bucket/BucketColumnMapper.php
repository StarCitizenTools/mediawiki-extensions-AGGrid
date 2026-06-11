<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\ColumnDescriptor;

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
	 * Per-type {@see ColumnDescriptor} fields, keyed by Bucket value type id.
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
	 * Resolve the {@see ColumnDescriptor} for a given Bucket type id and repeated flag.
	 */
	private function descriptor( string $type, bool $repeated ): ColumnDescriptor {
		$row = self::TYPE_MAP[$type] ?? null;
		if ( $row === null ) {
			return ColumnDescriptor::fallback();
		}

		if ( $repeated ) {
			// Every mapped Bucket type is filterable ('set' or 'number'), so a repeated
			// field always switches to a set filter (see class doc). Page values keep a
			// link-list renderer; other repeated scalars have no special renderer and are
			// displayed comma-joined.
			return new ColumnDescriptor(
				$type === 'PAGE' ? 'aggridLinkList' : null,
				'aggridSet',
				'set'
			);
		}

		return new ColumnDescriptor( $row['type'], $row['filter'], $row['family'] );
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
		return $this->descriptor( $type, $repeated )->toColumnDef( $field, $header );
	}

	/**
	 * Resolve the AG Grid filter component for a Bucket type id, or false when the
	 * datatype does not support filtering.
	 */
	public function filterComponent( string $type, bool $repeated ): string|false {
		return $this->descriptor( $type, $repeated )->filter;
	}

	/**
	 * Classify a Bucket type id into a filter family: 'set' | 'number' | 'none'.
	 */
	public function filterFamily( string $type, bool $repeated ): string {
		return $this->descriptor( $type, $repeated )->family;
	}
}
