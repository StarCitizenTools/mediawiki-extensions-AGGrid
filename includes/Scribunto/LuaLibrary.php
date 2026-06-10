<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Scribunto;

use InvalidArgumentException;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

class LuaLibrary extends LibraryBase {

	private const MAX_ROWS = 5000;
	private const MAX_THUMB_WIDTH = 2048;

	/**
	 * Tracking category for pages that successfully render a grid. Registered in
	 * extension.json's TrackingCategories; surfaced on Special:TrackingCategories.
	 */
	private const USAGE_TRACKING_CATEGORY = 'aggrid-tracking-category';

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

		$this->validateQuickSearch( $gridOptions['quickSearch'] ?? null );

		// A `source` descriptor routes to the backend path (query stored, not rows).
		if ( isset( $gridOptions['source'] ) ) {
			return $this->renderSource( $gridOptions );
		}

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
		// Tag the page as using AG Grid (only reached once validation has passed).
		$parser->addTrackingCategory( self::USAGE_TRACKING_CATEGORY );

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
	 * Validate the quickSearch gridOption (issue #21): boolean, or a table with
	 * optional `placeholder` (string) and `debounceMs` (non-negative number) keys.
	 * Unknown table keys are rejected to catch typos.
	 *
	 * Called before the source branch so both paths share one feedback point, and a
	 * bad shape never survives into a parser-cached placeholder. The option itself
	 * is a pure client concern: it rides the options JSON and mountGrid consumes it.
	 * Unlike the silently type-gated display keys in parsePrintouts, this throws:
	 * it is the extension's own top-level option, and silent gating would make a
	 * mistyped quickSearch vanish with zero feedback.
	 * An empty table is indistinguishable from an empty list and is allowed (the
	 * client treats it as `true`).
	 *
	 * @param mixed $quickSearch
	 * @throws LuaError If the shape is invalid.
	 */
	private function validateQuickSearch( $quickSearch ): void {
		if ( $quickSearch === null || is_bool( $quickSearch ) ) {
			return;
		}
		if ( !is_array( $quickSearch ) ) {
			throw new LuaError(
				'mw.ext.aggrid.render: quickSearch must be a boolean or a table'
			);
		}
		foreach ( $quickSearch as $key => $value ) {
			if ( $key === 'placeholder' ) {
				if ( !is_string( $value ) ) {
					throw new LuaError(
						'mw.ext.aggrid.render: quickSearch.placeholder must be a string'
					);
				}
			} elseif ( $key === 'debounceMs' ) {
				if (
					( !is_int( $value ) && !is_float( $value ) ) ||
					!is_finite( $value ) ||
					$value < 0
				) {
					throw new LuaError(
						'mw.ext.aggrid.render: quickSearch.debounceMs must be a non-negative number'
					);
				}
			} else {
				throw new LuaError(
					'mw.ext.aggrid.render: unknown quickSearch key "' . $key . '"'
				);
			}
		}
	}

	/**
	 * Render a backend source grid from a `source` descriptor.
	 *
	 * Resolves the backend for `source.type` via the BackendRegistry (the single dispatch
	 * seam that also gates each backend on its required extension), asks it to compile the
	 * descriptor into type-aware columnDefs and the stored query spec, then queues the spec
	 * (not rows) via GridRenderer::renderSource().
	 *
	 * @param array $gridOptions gridOptions table carrying a `source` descriptor.
	 * @return array
	 * @throws LuaError If the descriptor is invalid or the backing extension is unavailable.
	 */
	private function renderSource( array $gridOptions ): array {
		$source = $gridOptions['source'];
		if ( !is_array( $source ) ) {
			throw new LuaError( 'mw.ext.aggrid.render: source.type must be a non-empty string' );
		}

		$type = $source['type'] ?? null;
		if ( !is_string( $type ) || trim( $type ) === '' ) {
			throw new LuaError( 'mw.ext.aggrid.render: source.type must be a non-empty string' );
		}
		$type = trim( $type );

		try {
			$backend = MediaWikiServices::getInstance()
				->getService( 'AGGrid.BackendRegistry' )
				->get( $type );
		} catch ( InvalidArgumentException $e ) {
			// Unknown type and unavailable-extension both surface here with the same wording.
			throw new LuaError( 'mw.ext.aggrid.render: ' . $e->getMessage() );
		}

		[ $columnDefs, $spec ] = $backend->compileSource( $source );

		// Pass through any AG-Grid options the author set alongside `source`, then
		// override columnDefs with the auto-built array (and drop rowData entirely).
		$viewConfig = $gridOptions;
		unset( $viewConfig['source'], $viewConfig['columnDefs'], $viewConfig['rowData'] );
		$viewConfig['columnDefs'] = $columnDefs;
		if ( !$backend->supportsQuickSearch() ) {
			// The backend has no server-side substring search, so the quick-search box
			// would be inert — drop it rather than render a dead control.
			unset( $viewConfig['quickSearch'] );
		}

		$parser = $this->getParser();
		$parserOutput = $parser->getOutput();
		$parserOutput->addModules( [ 'ext.aggrid' ] );
		$parserOutput->addModuleStyles( [ 'ext.aggrid.styles' ] );
		// Tag the page as using AG Grid (only reached once the source is valid).
		$parser->addTrackingCategory( self::USAGE_TRACKING_CATEGORY );

		$title = $parser->getTitle();
		$pageId = $title->getArticleID();
		$revId = $parser->getRevisionId() ?: $title->getLatestRevID();
		$isPreview = $parser->getOptions()->getIsPreview();

		$html = MediaWikiServices::getInstance()
			->getService( 'AGGrid.GridRenderer' )
			->renderSource(
				$viewConfig,
				$spec,
				$parserOutput,
				$pageId ?: null,
				$revId ?: null,
				$isPreview,
				$backend->getType()
			);

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
