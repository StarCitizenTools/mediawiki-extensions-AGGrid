<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Service;

use MediaWiki\Extension\AGGrid\LuaSequence;
use MediaWiki\Html\TemplateParser;
use MediaWiki\Parser\ParserOutput;

/**
 * Builds the client-side placeholder for an AG Grid instance.
 *
 * On a canonical parse the grid's rowData is pulled out of the page HTML and
 * queued in ParserOutput extension data (flushed to aggrid_data by
 * LinksUpdateHandler); the placeholder carries only a handle the client uses to
 * fetch the rows. On preview the rows are embedded inline so unsaved edits still
 * render via the client-side row model.
 *
 * For backend (e.g. SMW) source grids, a query spec is queued instead of rows
 * (flushed to aggrid_source by LinksUpdateHandler in a separate pass); the
 * placeholder carries a data-mw-aggrid-source attribute plus the same handle.
 */
final class GridRenderer {

	/**
	 * Prefix for per-grid extension-data keys. Each grid's rows are stored under
	 * EXT_DATA_KEY . $index, written exactly once, so the ParserOutput
	 * "identical value per key" contract holds even with multiple grids per page.
	 * LinksUpdateHandler reads them by probing indices 0,1,2,… until null.
	 */
	public const EXT_DATA_KEY = 'ext-aggrid-grid-';

	/**
	 * Prefix for per-source-grid extension-data keys. Probed independently of
	 * EXT_DATA_KEY so inline and backend grids never collide on index numbering.
	 */
	public const SOURCE_EXT_DATA_KEY = 'ext-aggrid-source-';

	public function __construct(
		private readonly TemplateParser $templateParser
	) {
	}

	/**
	 * @param array $gridOptions AG Grid gridOptions (columnDefs + rowData required).
	 * @param ParserOutput $parserOutput Parse to queue rows into.
	 * @param int|null $pageId Page id, or null when unknown (new/unsaved page).
	 * @param int|null $revId Revision id, or null when unknown.
	 * @param bool $isPreview Whether this is a preview parse.
	 * @return string HTML placeholder.
	 */
	public function render(
		array $gridOptions,
		ParserOutput $parserOutput,
		?int $pageId,
		?int $revId,
		bool $isPreview
	): string {
		$gridOptions = LuaSequence::normalize( $gridOptions );

		// Preview / unknown page: embed inline so the (possibly unsaved) data renders.
		if ( $isPreview || !$pageId || !$revId ) {
			return $this->buildPlaceholder( $gridOptions, null, null );
		}

		$rows = $gridOptions['rowData'] ?? [];
		unset( $gridOptions['rowData'] );

		// Find the next free per-grid key; each key is written exactly once.
		$index = 0;
		while ( $parserOutput->getExtensionData( self::EXT_DATA_KEY . $index ) !== null ) {
			$index++;
		}
		$hash = sha1( (string)json_encode( $rows ) );
		$parserOutput->setExtensionData( self::EXT_DATA_KEY . $index, [
			'rows' => $rows,
			'hash' => $hash,
		] );

		return $this->buildPlaceholder( $gridOptions, [
			'pageid' => $pageId,
			'token' => $hash,
			'index' => $index,
		], null );
	}

	/**
	 * Render a placeholder for a backend-source grid (e.g. SMW query).
	 *
	 * On a canonical parse the query spec is queued in ParserOutput extension
	 * data under SOURCE_EXT_DATA_KEY; the placeholder carries data-mw-aggrid-source
	 * plus a handle. On preview (or unknown page) only the source attribute is
	 * emitted — the client shows an unsupported overlay since there is no stored
	 * spec to fetch.
	 *
	 * @param array $viewConfig AG Grid gridOptions without rowData (columnDefs etc.)
	 * @param array $spec Opaque query spec (e.g. { query, printouts, mainlabel }).
	 * @param ParserOutput $parserOutput Parse to queue spec into.
	 * @param int|null $pageId Page id, or null when unknown (new/unsaved page).
	 * @param int|null $revId Revision id, or null when unknown.
	 * @param bool $isPreview Whether this is a preview parse.
	 * @param string $source Source identifier (default: 'smw').
	 * @return string HTML placeholder.
	 */
	public function renderSource(
		array $viewConfig,
		array $spec,
		ParserOutput $parserOutput,
		?int $pageId,
		?int $revId,
		bool $isPreview,
		string $source = 'smw'
	): string {
		$viewConfig = LuaSequence::normalize( $viewConfig );

		// Preview / unknown page: no stored spec to fetch; emit source attr but no handle.
		if ( $isPreview || !$pageId || !$revId ) {
			return $this->buildPlaceholder( $viewConfig, null, $source );
		}

		// Find the next free source-grid key; probed independently of EXT_DATA_KEY.
		$index = 0;
		while ( $parserOutput->getExtensionData( self::SOURCE_EXT_DATA_KEY . $index ) !== null ) {
			$index++;
		}
		$parserOutput->setExtensionData( self::SOURCE_EXT_DATA_KEY . $index, [
			'source' => $source,
			'spec' => $spec,
			'hash' => sha1( (string)json_encode( $spec ) ),
		] );

		return $this->buildPlaceholder( $viewConfig, [
			'pageid' => $pageId,
			'token' => $revId,
			'index' => $index,
		], $source );
	}

	/**
	 * @param array $viewConfig gridOptions for the attribute (rowData omitted when fetched).
	 * @param array|null $handle [ 'pageid' => int, 'token' => string, 'index' => int ] or null.
	 * @param string|null $source Source identifier, or null for inline grids.
	 * @return string
	 */
	private function buildPlaceholder( array $viewConfig, ?array $handle, ?string $source ): string {
		$json = json_encode( $viewConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return $this->templateParser->processTemplate( 'grid', [
			'options' => $json,
			'handle' => $handle,
			'source' => $source,
		] );
	}

	/**
	 * Collect the inline-grid rows queued on a parse, keyed by grid index.
	 *
	 * Each grid's rows are written under EXT_DATA_KEY . $index, contiguous from 0
	 * (see render()). Shared by LinksUpdateHandler (the edit/links-update flush) and
	 * GridDataPopulator (the lazy REST cache-miss / maintenance flush) so both read
	 * the queue identically.
	 *
	 * @param ParserOutput $parserOutput
	 * @return array<int,array{rows: array, hash: string}>
	 */
	public static function extractInlineGrids( ParserOutput $parserOutput ): array {
		return self::probeQueue( $parserOutput, self::EXT_DATA_KEY );
	}

	/**
	 * Collect the backend query specs queued on a parse, keyed by grid index
	 * (probed independently of the inline grids — see SOURCE_EXT_DATA_KEY).
	 *
	 * @param ParserOutput $parserOutput
	 * @return array<int,array{source: string, spec: array, hash: string}>
	 */
	public static function extractSourceGrids( ParserOutput $parserOutput ): array {
		return self::probeQueue( $parserOutput, self::SOURCE_EXT_DATA_KEY );
	}

	/**
	 * Probe contiguous per-grid extension-data keys ($prefix . 0, 1, …) until the
	 * first gap, returning index => queued value.
	 *
	 * @param ParserOutput $parserOutput
	 * @param string $prefix
	 * @return array<int,array>
	 */
	private static function probeQueue( ParserOutput $parserOutput, string $prefix ): array {
		$grids = [];
		for ( $index = 0; ; $index++ ) {
			$grid = $parserOutput->getExtensionData( $prefix . $index );
			if ( $grid === null ) {
				break;
			}
			$grids[$index] = $grid;
		}
		return $grids;
	}
}
