<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\Bucket\Bucket;
use MediaWiki\Extension\Bucket\BucketException;
use MediaWiki\Extension\Bucket\BucketQuery;

/**
 * Thin adapter over Bucket's query API. Isolates the (static) coupling to the Bucket
 * extension so {@see BucketDataSource} stays decoupled and unit-testable behind a mock.
 *
 * Only ever instantiated when the Bucket extension is loaded (the data-source factory
 * is gated on it in ServiceWiring).
 *
 * Bucket signals query-construction problems (a dropped bucket/field, an invalid join
 * — possible when a stored spec drifts from the live schema) with BucketException, and
 * a missing DB account with ConfigException; both extend LogicException. This adapter
 * rethrows them as {@see BucketQueryException} (a RuntimeException) so the REST handlers'
 * RuntimeException catch maps them to a clean 400 instead of leaking a 500.
 */
class BucketRunner {

	/**
	 * Run a Bucket SELECT and return the result rows.
	 *
	 * @param array $data Bucket query-input array (bucketName, selects, joins, wheres,
	 *   orderBy?, limit_arg?, offset_arg?).
	 * @return array<int, array<string, mixed>> Rows keyed by select string, values cast
	 *   to their Bucket type (Page/Text -> string, Integer -> int, Double -> float,
	 *   Boolean -> bool, repeated -> array).
	 */
	public function select( array $data ): array {
		try {
			[ $rows ] = Bucket::runSelect( $data, null );
		} catch ( BucketException | ConfigException $e ) {
			throw new BucketQueryException( 'AGGrid: Bucket query failed: ' . $e->getMessage(), 0, $e );
		}
		return $rows;
	}

	/**
	 * Count the rows matching a Bucket query-input array.
	 *
	 * Uses SelectQueryBuilder::fetchRowCount(), which requires the query to select at
	 * most one field — so callers must reduce the count query to a single non-null
	 * column (see {@see BucketDataSource}). The total is bounded by the query's LIMIT,
	 * so callers raise limit_arg to {@see BucketQuery::MAX_LIMIT} for the count query.
	 *
	 * @param array $data Bucket query-input array selecting exactly one non-null column.
	 * @return int
	 */
	public function count( array $data ): int {
		try {
			return ( new BucketQuery( $data ) )->getSelectQueryBuilder()->fetchRowCount();
		} catch ( BucketException | ConfigException $e ) {
			throw new BucketQueryException( 'AGGrid: Bucket count failed: ' . $e->getMessage(), 0, $e );
		}
	}
}
