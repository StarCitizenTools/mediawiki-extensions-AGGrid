<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\Bucket\Bucket;
use MediaWiki\Extension\Bucket\BucketQuery;

/**
 * Thin adapter over Bucket's query API. Isolates the (static) coupling to the Bucket
 * extension so {@see BucketDataSource} stays decoupled and unit-testable behind a mock.
 *
 * Only ever instantiated when the Bucket extension is loaded (the data-source factory
 * is gated on it in ServiceWiring).
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
		[ $rows ] = Bucket::runSelect( $data, null );
		return $rows;
	}

	/**
	 * Count the rows matching a Bucket query-input array.
	 *
	 * The total is bounded by the query's LIMIT, so callers raise limit_arg to
	 * {@see BucketQuery::MAX_LIMIT} for the count query.
	 *
	 * @param array $data Bucket query-input array.
	 * @return int
	 */
	public function count( array $data ): int {
		return ( new BucketQuery( $data ) )->getSelectQueryBuilder()->fetchRowCount();
	}
}
