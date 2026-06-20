<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use RuntimeException;

/**
 * Template-method base for backend data sources, owning the contract plumbing both
 * backends ({@see Smw\SmwDataSource}, {@see Bucket\BucketDataSource}) duplicated:
 * stored-spec resolution, the offset-paged getPage() assembly, the getColumnValues()
 * dedup-by-key + partial-flag envelope, and the cache policy.
 *
 * Backends supply the four query hooks (executeQuery / countTotal / mapRow /
 * fetchColumnValueRows) plus the spec sentinel key and backend name. Every backend
 * divergence stays below this line: SMW's withRaisedLimits and MODE_COUNT, Bucket's
 * fetchRowCount, the per-row cell shaping, and quick-search support (SMW threads it,
 * Bucket ignores it) all live in the hook bodies.
 */
abstract class AbstractBackendDataSource implements BackendDataSource {

	public function __construct(
		protected readonly SourceSpecStore $specStore,
		protected readonly CachePolicy $cachePolicy,
		protected readonly int $maxValues
	) {
	}

	/**
	 * @inheritDoc
	 */
	final public function getCachePolicy(): CachePolicy {
		return $this->cachePolicy;
	}

	/**
	 * @inheritDoc
	 */
	final public function getPage(
		int $pageId,
		int $gridIndex,
		int $offset,
		array $sortModel,
		array $filterModel,
		int $size,
		string $quickSearch = '',
		?array $spec = null
	): GridPage {
		$spec = $this->resolveSpec( $pageId, $gridIndex, $spec );

		$rows = [];
		foreach ( $this->executeQuery( $spec, $offset, $size, $sortModel, $filterModel, $quickSearch ) as $rawRow ) {
			$rows[] = $this->mapRow( $rawRow, $spec );
		}

		// The total mirrors the same filter + quick search (not the page's offset/limit),
		// so the client's pagination bar matches the rows actually returned.
		$total = $this->countTotal( $spec, $filterModel, $quickSearch );

		return new GridPage( $rows, $total );
	}

	/**
	 * @inheritDoc
	 */
	final public function getColumnValues( int $pageId, int $gridIndex, string $column, ?array $spec = null ): array {
		$spec = $this->resolveSpec( $pageId, $gridIndex, $spec );
		$result = $this->fetchColumnValueRows( $spec, $column, $this->maxValues );

		$values = [];
		foreach ( $result['entries'] as $entry ) {
			// Dedupe by value key, preserving first-seen order (later duplicates only
			// refresh the label). Backends emit every occurrence, including all values of
			// a multi-valued row, so set-filter entries stay coherently de-selectable.
			$values[ $entry['key'] ] = $entry;
		}

		return [
			'values' => array_values( $values ),
			// Row-based: the value list was truncated when the scan hit the row cap.
			'partial' => $result['rowCount'] >= $this->maxValues,
		];
	}

	/**
	 * Resolve and validate the source spec for a grid, returning the inner spec.
	 *
	 * When $resolvedSpec is supplied (the caller already loaded or lazily repopulated it)
	 * it is validated and used directly; otherwise the stored spec is read by
	 * (pageId, gridIndex). Passing it lets a self-heal request (issue #31) serve without a
	 * store re-read that could miss a not-yet-committed or replica-lagged write.
	 *
	 * @throws RuntimeException When no spec is available for (pageId, gridIndex), or the
	 *   spec lacks the backend's required sentinel key.
	 */
	protected function resolveSpec( int $pageId, int $gridIndex, ?array $resolvedSpec = null ): array {
		if ( $resolvedSpec !== null ) {
			$spec = $resolvedSpec;
		} else {
			$source = $this->specStore->getSource( $pageId, $gridIndex );
			$spec = $source['spec'] ?? null;
		}
		if ( $spec === null || !isset( $spec[ $this->specSentinelKey() ] ) ) {
			throw new RuntimeException( sprintf(
				'AGGrid: no %s source spec for page %d grid %d',
				$this->backendName(), $pageId, $gridIndex
			) );
		}
		return $spec;
	}

	/**
	 * The required key whose presence in the stored spec marks it as this backend's
	 * (e.g. 'query' for SMW, 'bucket' for Bucket).
	 */
	abstract protected function specSentinelKey(): string;

	/**
	 * Human-readable backend name for the "no source spec" error.
	 */
	abstract protected function backendName(): string;

	/**
	 * Run the page query and return the raw backend rows (each passed to {@see mapRow}).
	 *
	 * Implementations that manipulate process-wide state for the duration of the query
	 * (e.g. SMW's raised structural limits) MUST materialise the rows eagerly within that
	 * scope — the base iterates the returned value after this method returns.
	 *
	 * @return iterable<array> Raw rows.
	 */
	abstract protected function executeQuery(
		array $spec,
		int $offset,
		int $size,
		array $sortModel,
		array $filterModel,
		string $quickSearch
	): iterable;

	/**
	 * The total number of rows matching the spec query plus the active filter and quick
	 * search, independent of the current page's offset/limit/sort.
	 */
	abstract protected function countTotal( array $spec, array $filterModel, string $quickSearch ): int;

	/**
	 * Map one raw backend row to an AG Grid row keyed by colId.
	 *
	 * @param array $rawRow One element of {@see executeQuery}'s result.
	 * @return array<string, mixed>
	 */
	abstract protected function mapRow( array $rawRow, array $spec ): array;

	/**
	 * Fetch the distinct-value entries for a column's set filter, scanning up to
	 * $maxValues rows. The base dedupes the entries by key and derives the partial flag
	 * from rowCount, so implementations return every occurrence (including all values of
	 * a multi-valued row) plus the number of rows scanned.
	 *
	 * @return array{entries: list<array{key: string, label: string}>, rowCount: int}
	 */
	abstract protected function fetchColumnValueRows( array $spec, string $column, int $maxValues ): array;
}
