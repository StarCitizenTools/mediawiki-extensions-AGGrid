<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * A source of grid rows for a stored or queryable grid handle. The inline source
 * is the only implementation today; backend sources (Bucket/Cargo/SMW) will
 * implement the same contract.
 */
interface DataSource {

	/**
	 * @param int $pageId
	 * @param int $gridIndex
	 * @return array|null Decoded rows, or null if the handle is unknown.
	 */
	public function getRows( int $pageId, int $gridIndex ): ?array;
}
