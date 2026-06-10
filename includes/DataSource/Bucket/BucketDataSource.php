<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\DataSource\GridPage;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Title\Title;
use RuntimeException;

/**
 * Backend data source backed by the Bucket extension.
 *
 * Builds Bucket's query-input array from the stored spec plus the request's
 * sort/filter/offset and runs it through {@see BucketRunner} (which wraps Bucket's
 * sanctioned `runSelect`/count API). Result rows are mapped to AG Grid rows keyed by
 * colId, with Page values rendered as link cells, repeated Page values as link lists,
 * repeated scalars comma-joined, and booleans as 'true'/'false'.
 *
 * The data source has no compile-time dependency on the Bucket extension: the coupling
 * lives entirely in the injected BucketRunner. Field types are read from the stored
 * spec, so no schema read happens at request time.
 *
 * Bucket has no LIKE operator, so quick search is not supported — the $quickSearch
 * argument is accepted (the BackendDataSource contract) but ignored.
 */
class BucketDataSource implements BackendDataSource {

	/**
	 * Upper bound for the count query's LIMIT, mirroring BucketQuery::MAX_LIMIT. The
	 * reported total is capped at this value (matching the SMW backend's bounded count).
	 */
	private const COUNT_LIMIT = 5000;

	/**
	 * Single non-null column the count query selects. fetchRowCount() requires at most
	 * one field; page_name is a system Page field present and non-null on every primary
	 * row (left joins preserve it), so COUNT(page_name) equals the result row count.
	 */
	private const COUNT_FIELD = 'page_name';

	public function __construct(
		private readonly BucketRunner $runner,
		private readonly SourceSpecStore $specStore,
		private readonly BucketFilterTranslator $filterTranslator,
		private readonly BucketColumnMapper $columnMapper,
		private readonly int $cacheMaxAge,
		private readonly int $maxValues
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getPage(
		int $pageId,
		int $gridIndex,
		int $offset,
		array $sortModel,
		array $filterModel,
		int $size,
		string $quickSearch = ''
	): GridPage {
		$spec = $this->resolveSpec( $pageId, $gridIndex );

		$selectOf = $this->selectResolver( $spec );
		$familyOf = $this->familyResolver( $spec );

		$filterOperands = $this->filterTranslator->toOperands( $filterModel, $selectOf, $familyOf );
		$orderBy = $this->filterTranslator->toOrderBy( $sortModel, $selectOf )
			?? $this->defaultOrderBy( $spec );

		$data = $this->baseData( $spec, $filterOperands );
		$data['limit_arg'] = $size;
		$data['offset_arg'] = $offset;
		if ( $orderBy !== null ) {
			$data['orderBy'] = $orderBy;
		}

		$rows = [];
		foreach ( $this->runner->select( $data ) as $resultRow ) {
			$rows[] = $this->mapRow( $resultRow, $spec );
		}

		// fetchRowCount() requires a single selected field, so the count query selects
		// just the primary page_name (non-null) instead of the display columns. The join
		// and filters stay, so the count matches the rows query.
		$countData = $this->baseData( $spec, $filterOperands );
		$countData['selects'] = [ self::COUNT_FIELD ];
		$countData['limit_arg'] = self::COUNT_LIMIT;
		$total = $this->runner->count( $countData );

		return new GridPage( $rows, $total );
	}

	/**
	 * @inheritDoc
	 */
	public function getColumnValues( int $pageId, int $gridIndex, string $column ): array {
		$spec = $this->resolveSpec( $pageId, $gridIndex );
		$meta = $this->fieldMeta( $spec, $column );

		// Select only this column (plus the spec's joins, so a qualified column resolves),
		// scan up to maxValues rows, and dedupe in PHP.
		$data = $this->baseData( $spec, [] );
		$data['selects'] = [ $meta['select'] ];
		$data['limit_arg'] = $this->maxValues;

		$values = [];
		$rowCount = 0;
		foreach ( $this->runner->select( $data ) as $resultRow ) {
			$rowCount++;
			foreach ( $this->valueList( $resultRow[$meta['select']] ?? null, $meta ) as $entry ) {
				$values[$entry['key']] = $entry;
			}
		}

		return [
			'values' => array_values( $values ),
			'partial' => $rowCount >= $this->maxValues,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function getCachePolicy(): CachePolicy {
		// The PUBLIC flag is decided by the REST handler from anon-readability; the
		// source only supplies the configured maxAge default.
		return new CachePolicy( false, $this->cacheMaxAge );
	}

	// -------------------------------------------------------------------------
	// Query construction
	// -------------------------------------------------------------------------

	/**
	 * Build the base Bucket query-input from the spec plus the active filter operands.
	 *
	 * @param array $spec
	 * @param array $filterOperands
	 * @return array
	 */
	private function baseData( array $spec, array $filterOperands ): array {
		$operands = array_merge( $spec['where'] ?? [], $filterOperands );

		$joins = [];
		foreach ( $spec['joins'] ?? [] as $join ) {
			$joins[] = [ 'bucketName' => $join['bucket'], 'cond' => $join['on'] ];
		}

		return [
			'bucketName' => $spec['bucket'],
			'selects' => array_values( $spec['selects'] ),
			'joins' => $joins,
			'wheres' => [ 'op' => 'AND', 'operands' => array_values( $operands ) ],
		];
	}

	/**
	 * The spec's default orderBy as a Bucket orderBy, or null when none is declared.
	 *
	 * @param array $spec
	 * @return array{fieldName: string, direction: string}|null
	 */
	private function defaultOrderBy( array $spec ): ?array {
		if ( !isset( $spec['orderBy'] ) ) {
			return null;
		}
		return [
			'fieldName' => $spec['orderBy']['field'],
			'direction' => $spec['orderBy']['direction'],
		];
	}

	/**
	 * Build a colId -> select-string resolver over the spec's declared columns.
	 *
	 * Resolution is closed: an undeclared colId throws (-> 400 in the REST handlers),
	 * so a client cannot sort/filter on arbitrary bucket fields.
	 *
	 * @param array $spec
	 * @return callable Callable( string $colId ): string
	 */
	private function selectResolver( array $spec ): callable {
		$fields = $spec['fields'];
		return static function ( string $colId ) use ( $fields ): string {
			if ( !isset( $fields[$colId] ) ) {
				throw new RuntimeException( "AGGrid: undeclared column \"$colId\"" );
			}
			return $fields[$colId]['select'];
		};
	}

	/**
	 * Build a colId -> filter-family resolver over the spec's declared columns.
	 *
	 * @param array $spec
	 * @return callable Callable( string $colId ): string
	 */
	private function familyResolver( array $spec ): callable {
		$fields = $spec['fields'];
		$mapper = $this->columnMapper;
		return static function ( string $colId ) use ( $fields, $mapper ): string {
			if ( !isset( $fields[$colId] ) ) {
				throw new RuntimeException( "AGGrid: undeclared column \"$colId\"" );
			}
			return $mapper->filterFamily( $fields[$colId]['type'], $fields[$colId]['repeated'] );
		};
	}

	/**
	 * Resolve and validate the stored Bucket source spec for a grid.
	 *
	 * @param int $pageId
	 * @param int $gridIndex
	 * @return array
	 */
	private function resolveSpec( int $pageId, int $gridIndex ): array {
		$source = $this->specStore->getSource( $pageId, $gridIndex );
		if ( $source === null || !isset( $source['spec']['bucket'] ) ) {
			throw new RuntimeException(
				"AGGrid: no Bucket source spec for page $pageId grid $gridIndex"
			);
		}
		return $source['spec'];
	}

	/**
	 * Field metadata for a declared colId, or throw if undeclared.
	 *
	 * @param array $spec
	 * @param string $colId
	 * @return array{select: string, type: string, repeated: bool}
	 */
	private function fieldMeta( array $spec, string $colId ): array {
		if ( !isset( $spec['fields'][$colId] ) ) {
			throw new RuntimeException( "AGGrid: undeclared column \"$colId\"" );
		}
		return $spec['fields'][$colId];
	}

	// -------------------------------------------------------------------------
	// Row / cell mapping
	// -------------------------------------------------------------------------

	/**
	 * Map one Bucket result row (keyed by select string) to an AG Grid row keyed by colId.
	 *
	 * @param array<string, mixed> $resultRow
	 * @param array $spec
	 * @return array<string, mixed>
	 */
	private function mapRow( array $resultRow, array $spec ): array {
		$row = [];
		foreach ( $spec['fields'] as $colId => $meta ) {
			if ( !array_key_exists( $meta['select'], $resultRow ) ) {
				continue;
			}
			$cell = $this->cellFor( $resultRow[$meta['select']], $meta['type'], $meta['repeated'] );
			if ( $cell !== null ) {
				$row[$colId] = $cell;
			}
		}
		return $row;
	}

	/**
	 * Map a Bucket cell value to an AG Grid cell value.
	 *
	 * @param mixed $value
	 * @param string $type Bucket value type.
	 * @param bool $repeated
	 * @return array|string|int|float|null
	 */
	private function cellFor( $value, string $type, bool $repeated ) {
		if ( $value === null ) {
			return null;
		}

		if ( $repeated ) {
			$values = is_array( $value ) ? $value : [ $value ];
			if ( $type === 'PAGE' ) {
				$links = [];
				foreach ( $values as $single ) {
					$link = $this->pageLink( (string)$single );
					if ( $link !== null ) {
						$links[] = $link;
					}
				}
				return [ 'links' => $links ];
			}
			// Repeated scalars have no special renderer: comma-join their display text.
			$parts = [];
			foreach ( $values as $single ) {
				$parts[] = $this->scalarText( $single, $type );
			}
			return implode( ', ', $parts );
		}

		if ( $type === 'PAGE' ) {
			return $this->pageLink( (string)$value );
		}
		// Integer/Double stay numeric (runSelect already cast them); Text stays a string.
		if ( $type === 'BOOLEAN' ) {
			return $value ? 'true' : 'false';
		}
		return $value;
	}

	/**
	 * Build a { text, href } link cell for a page title string, or null when empty.
	 *
	 * @param string $name
	 * @return array{text: string, href: string}|null
	 */
	private function pageLink( string $name ): ?array {
		if ( $name === '' ) {
			return null;
		}
		$title = Title::newFromText( $name );
		if ( $title === null ) {
			return [ 'text' => $name, 'href' => '' ];
		}
		return [ 'text' => $title->getText(), 'href' => $title->getLocalURL() ];
	}

	/**
	 * Display text for a non-page scalar value.
	 *
	 * @param mixed $value
	 * @param string $type
	 * @return string
	 */
	private function scalarText( $value, string $type ): string {
		if ( $type === 'BOOLEAN' ) {
			return $value ? 'true' : 'false';
		}
		return (string)$value;
	}

	/**
	 * Enumerate a column's value(s) into a list of { key, label } entries for the set
	 * filter. Boolean values map to '1'/'0' keys (matching the stored DB value) with
	 * 'true'/'false' labels; other values use the raw string as both key and label.
	 * Empty (non-boolean) values are skipped.
	 *
	 * @param mixed $raw The cell's raw Bucket value (scalar or array for repeated).
	 * @param array{select: string, type: string, repeated: bool} $meta
	 * @return array<int, array{key: string, label: string}>
	 */
	private function valueList( $raw, array $meta ): array {
		if ( $raw === null ) {
			return [];
		}
		$values = $meta['repeated']
			? ( is_array( $raw ) ? $raw : [ $raw ] )
			: [ $raw ];

		$out = [];
		foreach ( $values as $value ) {
			if ( $meta['type'] === 'BOOLEAN' ) {
				$out[] = $value
					? [ 'key' => '1', 'label' => 'true' ]
					: [ 'key' => '0', 'label' => 'false' ];
				continue;
			}
			$str = (string)$value;
			if ( $str === '' ) {
				continue;
			}
			$out[] = [ 'key' => $str, 'label' => $str ];
		}
		return $out;
	}
}
