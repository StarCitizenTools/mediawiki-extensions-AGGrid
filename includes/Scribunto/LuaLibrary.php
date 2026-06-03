<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Scribunto;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\MediaWikiServices;

class LuaLibrary extends LibraryBase {

	private const MAX_ROWS = 5000;

	/**
	 * @inheritDoc
	 */
	public function register(): array {
		$lib = [
			'render' => [ $this, 'render' ],
		];

		return $this->getEngine()->registerInterface(
			__DIR__ . DIRECTORY_SEPARATOR . 'mw.ext.aggrid.lua',
			$lib,
			[]
		);
	}

	/**
	 * Render an AG Grid placeholder from a Lua gridOptions table.
	 *
	 * @param mixed $gridOptions AG Grid gridOptions (table) from Lua.
	 * @return array
	 * @throws LuaError If the gridOptions are invalid.
	 */
	public function render( $gridOptions = null ): array {
		$this->checkType( 'mw.ext.aggrid.render', 1, $gridOptions, 'table' );

		if ( !isset( $gridOptions['columnDefs'] ) || !is_array( $gridOptions['columnDefs'] ) ) {
			throw new LuaError( 'mw.ext.aggrid.render: "columnDefs" must be a table' );
		}
		if ( !isset( $gridOptions['rowData'] ) || !is_array( $gridOptions['rowData'] ) ) {
			throw new LuaError( 'mw.ext.aggrid.render: "rowData" must be a table' );
		}
		$rowCount = count( $gridOptions['rowData'] );
		if ( $rowCount > self::MAX_ROWS ) {
			throw new LuaError(
				"mw.ext.aggrid.render: too many rows ($rowCount); the inline limit is " .
				self::MAX_ROWS . '. Use a structured data backend such as Bucket or Cargo ' .
				'for larger datasets.'
			);
		}

		$parser = $this->getParser();
		$parserOutput = $parser->getOutput();
		$parserOutput->addModules( [ 'ext.aggrid' ] );
		// Load the placeholder/skeleton styles render-blocking (in <head>) so the
		// grid's space is reserved at first paint — avoids layout shift (CLS) from
		// the async JS module's styles arriving late.
		$parserOutput->addModuleStyles( [ 'ext.aggrid.styles' ] );

		$title = $parser->getTitle();
		$pageId = $title->getArticleID();
		// Canonical parses carry a revision id; the getLatestRevID() fallback only
		// matters for API parses without an oldid. A 0/missing id becomes null
		// below, routing to the inline-embed branch.
		$revId = $parser->getRevisionId() ?: $title->getLatestRevID();
		$isPreview = $parser->getOptions()->getIsPreview();

		$html = MediaWikiServices::getInstance()
			->getService( 'AGGrid.GridRenderer' )
			->render( $gridOptions, $parserOutput, $pageId ?: null, $revId ?: null, $isPreview );

		// Strip marker keeps the parser from reprocessing the raw HTML.
		return [ $parser->insertStripItem( $html ) ];
	}
}
