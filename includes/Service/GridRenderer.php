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
 */
final class GridRenderer {

	/**
	 * Prefix for per-grid extension-data keys. Each grid's rows are stored under
	 * EXT_DATA_KEY . $index, written exactly once, so the ParserOutput
	 * "identical value per key" contract holds even with multiple grids per page.
	 * LinksUpdateHandler reads them by probing indices 0,1,2,… until null.
	 */
	public const EXT_DATA_KEY = 'ext-aggrid-grid-';

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
			return $this->buildPlaceholder( $gridOptions, null );
		}

		$rows = $gridOptions['rowData'] ?? [];
		unset( $gridOptions['rowData'] );

		// Find the next free per-grid key; each key is written exactly once.
		$index = 0;
		while ( $parserOutput->getExtensionData( self::EXT_DATA_KEY . $index ) !== null ) {
			$index++;
		}
		$parserOutput->setExtensionData( self::EXT_DATA_KEY . $index, [
			'rows' => $rows,
			'hash' => sha1( (string)json_encode( $rows ) ),
		] );

		return $this->buildPlaceholder( $gridOptions, [
			'pageid' => $pageId,
			'rev' => $revId,
			'index' => $index,
		] );
	}

	/**
	 * @param array $viewConfig gridOptions for the attribute (rowData omitted when fetched).
	 * @param array|null $handle [ 'pageid' => int, 'rev' => int, 'index' => int ] or null to embed.
	 * @return string
	 */
	private function buildPlaceholder( array $viewConfig, ?array $handle ): string {
		$json = json_encode( $viewConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		return $this->templateParser->processTemplate( 'grid', [
			'options' => $json,
			'handle' => $handle,
		] );
	}
}
