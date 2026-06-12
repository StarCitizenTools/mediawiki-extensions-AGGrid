<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Contract for offset-paged backend data sources (e.g. SMW, Cargo).
 *
 * Unlike the inline {@see DataSource}, which returns all rows in one shot for the
 * client-side row model, a BackendDataSource serves blocks requested by the AG Grid
 * server-side row model and declares a cache policy for HTTP caching.
 */
interface BackendDataSource {

	/**
	 * Fetch one page of rows by offset, plus the total matching count.
	 *
	 * @param int $pageId Wiki page ID that owns the grid.
	 * @param int $gridIndex Zero-based index of the grid on that page.
	 * @param int $offset Zero-based index of the first row to return.
	 * @param array $sortModel AG Grid sort model: list of { colId, sort } objects.
	 * @param array $filterModel AG Grid filter model: map of colId → filter descriptor.
	 * @param int $size Maximum number of rows to return.
	 * @param string $quickSearch Free-text quick-search term, ANDed onto the query and
	 *   matched across the subject and the searchable columns; '' applies no constraint.
	 * @param array|null $spec Pre-resolved inner source spec. When provided it is used
	 *   directly instead of re-reading the store by (pageId, gridIndex) — this lets a
	 *   caller that just resolved or lazily repopulated the spec (issue #31) serve the
	 *   request without a redundant, possibly replica-lagged or not-yet-committed re-read.
	 * @return GridPage Carries the page rows and the total count of all matching rows
	 *   (across every page) so the client can render its native pagination bar.
	 */
	public function getPage(
		int $pageId,
		int $gridIndex,
		int $offset,
		array $sortModel,
		array $filterModel,
		int $size,
		string $quickSearch = '',
		?array $spec = null
	): GridPage;

	/**
	 * Return the distinct values for a column (used by the server-side set filter).
	 *
	 * @param int $pageId Wiki page ID that owns the grid.
	 * @param int $gridIndex Zero-based index of the grid on that page.
	 * @param string $column Column identifier.
	 * @param array|null $spec Pre-resolved inner source spec; used directly instead of
	 *   re-reading the store when provided (see {@see getPage()}).
	 * @return array Shape: [ 'values' => array, 'partial' => bool ]
	 *   - values: list of { key: string, label: string } objects
	 *   - partial: true when the value list was truncated
	 */
	public function getColumnValues( int $pageId, int $gridIndex, string $column, ?array $spec = null ): array;

	/**
	 * Declare the HTTP cache behaviour for responses produced by this source.
	 */
	public function getCachePolicy(): CachePolicy;
}
