<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Scribunto;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class LuaLibrary extends LibraryBase {

	private const MAX_ROWS = 5000;
	private const MAX_THUMB_WIDTH = 2048;

	/**
	 * @inheritDoc
	 */
	public function register(): array {
		$lib = [
			'render' => [ $this, 'render' ],
			'thumb' => [ $this, 'thumb' ],
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

	/**
	 * Resolve a File title + width into a thumbnail descriptor for a rich image cell.
	 *
	 * Resolution happens server-side during the parse so the client renderer stays a
	 * pure value->DOM builder. The file is registered on ParserOutput so LinksUpdate
	 * reparses the page (re-resolving the URL) when the file changes, moves or is
	 * deleted — matching [[File:...]] behaviour.
	 *
	 * @param mixed $file File title (e.g. "File:Aurora.jpg").
	 * @param mixed $width Thumbnail width in px.
	 * @param mixed $opts Table: { link = <page title>, alt = <string> }.
	 * @return array Single-element [ descriptor ] on success, or [] (Lua nil) if missing.
	 * @throws LuaError
	 */
	public function thumb( $file = null, $width = null, $opts = null ): array {
		$this->checkType( 'mw.ext.aggrid.thumb', 1, $file, 'string' );
		$this->checkType( 'mw.ext.aggrid.thumb', 2, $width, 'number' );
		if ( $opts === null ) {
			$opts = [];
		}
		$this->checkType( 'mw.ext.aggrid.thumb', 3, $opts, 'table' );

		$width = (int)$width;
		if ( $width < 1 || $width > self::MAX_THUMB_WIDTH ) {
			throw new LuaError(
				'mw.ext.aggrid.thumb: width must be between 1 and ' . self::MAX_THUMB_WIDTH
			);
		}

		$services = MediaWikiServices::getInstance();
		$title = Title::newFromText( (string)$file, NS_FILE );
		if ( !$title || $title->getNamespace() !== NS_FILE ) {
			throw new LuaError( 'mw.ext.aggrid.thumb: "' . $file . '" is not a valid File title' );
		}

		$parserOutput = $this->getParser()->getOutput();
		$repoFile = $services->getRepoGroup()->findFile( $title );

		// Track the dependency regardless of existence (missing files become tracked
		// "wanted files" so the page refreshes when one is uploaded).
		$parserOutput->addImage(
			$title->getDBkey(),
			$repoFile ? $repoFile->getTimestamp() : false,
			$repoFile ? $repoFile->getSha1() : false
		);

		if ( !$repoFile || !$repoFile->exists() ) {
			return [];
		}

		$thumb = $repoFile->transform( [ 'width' => $width ] );
		if ( !$thumb || $thumb->isError() ) {
			return [];
		}

		$descriptor = [
			'src' => $thumb->getUrl(),
			'width' => $thumb->getWidth(),
			'alt' => isset( $opts['alt'] ) ? (string)$opts['alt'] : $title->getText(),
		];

		if ( isset( $opts['link'] ) ) {
			$linkTitle = Title::newFromText( (string)$opts['link'] );
			if ( $linkTitle ) {
				$descriptor['href'] = $linkTitle->getLocalURL();
			}
		}

		return [ $descriptor ];
	}
}
