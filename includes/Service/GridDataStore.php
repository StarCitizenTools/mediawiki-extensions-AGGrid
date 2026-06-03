<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Service;

use MediaWiki\Extension\AGGrid\DataSource\DataSource;

/**
 * Contract for reading and writing grid rows persisted for a page.
 */
interface GridDataStore extends DataSource {

	/**
	 * Replace all stored grids for a page. Implementations may no-op when the
	 * incoming data is unchanged.
	 *
	 * @param int $pageId
	 * @param array $grids index => [ 'rows' => array, 'hash' => string(sha1 hex) ]
	 */
	public function replaceForPage( int $pageId, array $grids ): void;

	/**
	 * Delete all stored grids for a page.
	 *
	 * @param int $pageId
	 */
	public function deleteForPage( int $pageId ): void;
}
