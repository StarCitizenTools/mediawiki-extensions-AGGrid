<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Immutable description of how a backend column maps to an AG Grid columnDef:
 * its AG Grid column type, filter component, and filter family.
 *
 * Produced by the per-backend column mappers ({@see Smw\TypeColumnMapper},
 * {@see Bucket\BucketColumnMapper}) and consumed by the source compilers (for the
 * columnDef) and the filter translators (the family). Pure: no SMW/Bucket or
 * running-wiki dependency.
 */
final class ColumnDescriptor {

	/**
	 * @param string|null $type AG Grid column type string, or null to omit the key entirely.
	 * @param string|false $filter AG Grid filter component name, or false to disable filtering.
	 * @param string $family Filter family ('text'|'number'|'date'|'boolean'|'set'|'none').
	 */
	public function __construct(
		public readonly ?string $type,
		public readonly string|false $filter,
		public readonly string $family
	) {
	}

	/**
	 * Descriptor for an unknown/untyped column: shown, but with no AG Grid type and
	 * no filter.
	 */
	public static function fallback(): self {
		return new self( null, false, 'none' );
	}

	/**
	 * Assemble the partial AG Grid columnDef for a resolved column. The 'type' key is
	 * added only when non-null (AG Grid omits the type entirely rather than seeing null).
	 *
	 * @param string $field AG Grid field key (colId).
	 * @param string $header Human-readable column header.
	 * @return array<string, mixed>
	 */
	public function toColumnDef( string $field, string $header ): array {
		$colDef = [
			'field'      => $field,
			'headerName' => $header,
			'filter'     => $this->filter,
		];
		if ( $this->type !== null ) {
			$colDef['type'] = $this->type;
		}
		return $colDef;
	}
}
