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
		string $quickSearch = ''
	): GridPage;

	/**
	 * Return the distinct values for a column (used by the server-side set filter).
	 *
	 * @param int $pageId Wiki page ID that owns the grid.
	 * @param int $gridIndex Zero-based index of the grid on that page.
	 * @param string $column Column identifier.
	 * @return array Shape: [ 'values' => array, 'partial' => bool ]
	 *   - values: list of { key: string, label: string } objects
	 *   - partial: true when the value list was truncated
	 */
	public function getColumnValues( int $pageId, int $gridIndex, string $column ): array;

	/**
	 * Declare the HTTP cache behaviour for responses produced by this source.
	 */
	public function getCachePolicy(): CachePolicy;
}
