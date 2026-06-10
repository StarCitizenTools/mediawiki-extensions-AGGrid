<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Scribunto;

use MediaWiki\Extension\AGGrid\DataSource\Smw\TypeColumnMapper;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use RuntimeException;
use SMW\DIProperty;

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
	 * Dispatches by `source.type` to a per-backend builder that produces type-aware
	 * columnDefs and the stored query spec, then queues the spec (not rows) via
	 * GridRenderer::renderSource(). Supported types: 'smw' and 'bucket'.
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

		[ $columnDefs, $spec ] = $this->buildSource( $type, $source );

		// Pass through any AG-Grid options the author set alongside `source`, then
		// override columnDefs with the auto-built array (and drop rowData entirely).
		$viewConfig = $gridOptions;
		unset( $viewConfig['source'], $viewConfig['columnDefs'], $viewConfig['rowData'] );
		$viewConfig['columnDefs'] = $columnDefs;

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
				$type
			);

		return [ $parser->insertStripItem( $html ) ];
	}

	/**
	 * Dispatch a source descriptor to its per-backend builder, returning
	 * [ columnDefs, spec ]. Each backend requires its backing extension to be loaded.
	 *
	 * @param string $type Source type ('smw' | 'bucket').
	 * @param array $source The `source` descriptor table.
	 * @return array{0: array, 1: array} [ columnDefs, spec ]
	 * @throws LuaError If the type is unknown or its extension is unavailable.
	 */
	private function buildSource( string $type, array $source ): array {
		if ( $type === 'smw' ) {
			if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
				throw new LuaError(
					'mw.ext.aggrid.render: source type "smw" requires the Semantic MediaWiki extension'
				);
			}
			return $this->buildSmwSource( $source );
		}
		if ( $type === 'bucket' ) {
			if ( !ExtensionRegistry::getInstance()->isLoaded( 'Bucket' ) ) {
				throw new LuaError(
					'mw.ext.aggrid.render: source type "bucket" requires the Bucket extension'
				);
			}
			return MediaWikiServices::getInstance()
				->getService( 'AGGrid.BucketSourceCompiler' )
				->compile( $source );
		}
		throw new LuaError( 'mw.ext.aggrid.render: unknown source type "' . $type . '"' );
	}

	/**
	 * Build type-aware columnDefs and the stored spec for an SMW `source` descriptor.
	 *
	 * Auto-builds columnDefs from the SMW property datatypes. The columnDef `field`
	 * for each printout equals the SMW PrintRequest label that SmwDataSource emits,
	 * and the subject column uses the reserved `_subject` key.
	 *
	 * @param array $source The `source` descriptor table.
	 * @return array{0: array, 1: array} [ columnDefs, spec ]
	 * @throws LuaError If the descriptor is invalid.
	 */
	private function buildSmwSource( array $source ): array {
		$queryString = $this->buildQueryString( $source['query'] ?? null );
		$printouts = $this->parsePrintouts( $source['printouts'] ?? null );

		$mapper = new TypeColumnMapper();
		$columnDefs = [];
		$canonicalPrintouts = [];
		// field (the AG Grid colId / PrintRequest label) -> real SMW property name.
		// SmwDataSource resolves a column's property through this map so an aliased
		// printout ('Has population=Pop') sorts/filters on the property, not the alias.
		$fieldToProp = [];
		// field -> facet property label (issue #20): the property the column's filter
		// lists and matches, while display/sort stay on the column's own property.
		$facets = [];

		foreach ( $printouts as [ $prop, $label, $options ] ) {
			// newFromUserLabel returns null on some malformed labels and throws on
			// others (e.g. a bare underscore); treat both as an invalid property.
			try {
				$property = DIProperty::newFromUserLabel( $prop );
			} catch ( RuntimeException ) {
				throw new LuaError( 'mw.ext.aggrid.render: invalid property "' . $prop . '"' );
			}
			if ( $property === null ) {
				throw new LuaError( 'mw.ext.aggrid.render: invalid property "' . $prop . '"' );
			}
			$typeId = $property->findPropertyValueType();
			// field MUST equal the column key SmwDataSource emits (the PrintRequest label).
			$colDef = $mapper->mapColumn( $label, $label, $typeId );
			// Merge author presentation keys over the datatype-derived colDef. `type`
			// overrides the derived renderer; cellRendererParams/format are additive.
			// All are plain serializable data and ride in the placeholder attribute.
			if ( isset( $options['type'] ) ) {
				$colDef['type'] = $options['type'];
			}
			if ( isset( $options['cellRendererParams'] ) ) {
				$colDef['cellRendererParams'] = $options['cellRendererParams'];
			}
			if ( isset( $options['format'] ) ) {
				$colDef['format'] = $options['format'];
			}
			$facetProperty = $this->resolveFacetProperty( $options['filterProp'] ?? null, $property );
			if ( $facetProperty !== null ) {
				$filter = $mapper->filterComponent( $facetProperty->findPropertyValueType() );
				if ( $filter === false ) {
					throw new LuaError(
						'mw.ext.aggrid.render: filter property "' . $options['filterProp'] .
						'" has a datatype that cannot be filtered'
					);
				}
				// The whole filter follows the facet: the component here, the value
				// list and query conditions server-side via the spec's facets map.
				$colDef['filter'] = $filter;
				$facets[$label] = $facetProperty->getLabel();
			}
			$columnDefs[] = $colDef;
			// Stored printout must yield the same label: "prop=label" unless label === prop.
			$canonicalPrintouts[] = $label !== $prop ? $prop . '=' . $label : $prop;
			// Record the field->property mapping (label == prop for non-aliased columns).
			$fieldToProp[$label] = $prop;
		}

		// Subject (page) column, prepended unless mainlabel suppresses it ('-').
		$mainlabel = $source['mainlabel'] ?? null;
		$mainlabelIsString = is_string( $mainlabel );
		if ( !$mainlabelIsString || $mainlabel !== '-' ) {
			$header = ( $mainlabelIsString && trim( $mainlabel ) !== '' && $mainlabel !== '-' )
				? $mainlabel
				: 'Page';
			array_unshift( $columnDefs, [
				'field' => '_subject',
				'headerName' => $header,
				'type' => 'aggridLink',
				// The subject is not a filterable property in v1.
				'filter' => false,
			] );
		}

		$spec = [
			'query' => $queryString,
			'printouts' => $canonicalPrintouts,
			'mainlabel' => $mainlabelIsString ? $mainlabel : null,
			'fields' => $fieldToProp,
		];
		// Omitted when empty so facet-free grids keep byte-identical specs (no
		// spurious aggrid_source rewrites under the hash compare).
		if ( $facets !== [] ) {
			$spec['facets'] = $facets;
		}

		return [ $columnDefs, $spec ];
	}

	/**
	 * Normalize a query descriptor into a single trimmed query string.
	 *
	 * Accepts a string or a Lua sequence of condition fragments (joined by spaces).
	 *
	 * @param mixed $query
	 * @return string
	 * @throws LuaError If the result is empty.
	 */
	private function buildQueryString( $query ): string {
		if ( is_string( $query ) ) {
			$queryString = trim( $query );
		} elseif ( is_array( $query ) ) {
			$fragments = [];
			foreach ( $query as $fragment ) {
				if ( is_string( $fragment ) && trim( $fragment ) !== '' ) {
					$fragments[] = trim( $fragment );
				}
			}
			$queryString = implode( ' ', $fragments );
		} else {
			$queryString = '';
		}

		if ( $queryString === '' ) {
			throw new LuaError(
				'mw.ext.aggrid.render: source.query must be a non-empty string or list of fragments'
			);
		}

		return $queryString;
	}

	/**
	 * Parse the printouts descriptor into a list of [ prop, label, options ] triples.
	 *
	 * Entries may be a plain string ('Population'), a 'prop=label' string, or a table
	 * { prop = ..., label = ..., type = ..., cellRendererParams = ..., format = ...,
	 * filterProp = ... }. The display-only presentation keys (type, cellRendererParams,
	 * format) ride in the placeholder's gridOptions attribute and are not part of the
	 * stored query spec. filterProp is different: it feeds the stored query spec — it
	 * declares the property the column's filter lists and matches.
	 *
	 * @param mixed $printouts
	 * @return array<int, array{0: string, 1: string, 2: array}> Options bag keys:
	 *   type?, cellRendererParams?, format? (display-only), filterProp? (feeds the spec)
	 * @throws LuaError If empty or an entry lacks a property name.
	 */
	private function parsePrintouts( $printouts ): array {
		if ( !is_array( $printouts ) || $printouts === [] ) {
			throw new LuaError(
				'mw.ext.aggrid.render: source.printouts must list at least one property'
			);
		}

		$parsed = [];
		foreach ( $printouts as $entry ) {
			$options = [];
			if ( is_string( $entry ) ) {
				if ( strpos( $entry, '=' ) !== false ) {
					[ $prop, $label ] = explode( '=', $entry, 2 );
					$prop = trim( $prop );
					$label = trim( $label );
				} else {
					$prop = trim( $entry );
					$label = $prop;
				}
			} elseif ( is_array( $entry ) ) {
				$prop = isset( $entry['prop'] ) ? trim( (string)$entry['prop'] ) : '';
				$label = isset( $entry['label'] ) ? trim( (string)$entry['label'] ) : $prop;
				// Display-only presentation keys, copied only when well-typed. These ride
				// in the placeholder's gridOptions attribute; they are not part of the
				// stored query spec (the client interprets them at mount).
				if ( isset( $entry['type'] ) && is_string( $entry['type'] ) ) {
					$options['type'] = $entry['type'];
				}
				if ( isset( $entry['cellRendererParams'] ) && is_array( $entry['cellRendererParams'] ) ) {
					$options['cellRendererParams'] = $entry['cellRendererParams'];
				}
				if ( isset( $entry['format'] ) && is_array( $entry['format'] ) ) {
					$options['format'] = $entry['format'];
				}
				// Filter facet (issue #20): unlike the display-only keys above, this DOES
				// feed the stored query spec — the server lists and filters on it. Silent
				// type-gating only degrades presentation for the keys above; a silently
				// dropped facet would change filtering semantics, so reject it here.
				if ( isset( $entry['filterProp'] ) ) {
					if ( !is_string( $entry['filterProp'] ) ) {
						throw new LuaError(
							'mw.ext.aggrid.render: filterProp must be a string property name'
						);
					}
					$options['filterProp'] = trim( $entry['filterProp'] );
				}
			} else {
				$prop = '';
				$label = '';
			}

			if ( $prop === '' ) {
				throw new LuaError( 'mw.ext.aggrid.render: each printout needs a property name' );
			}
			if ( $label === '' ) {
				$label = $prop;
			}
			$parsed[] = [ $prop, $label, $options ];
		}

		return $parsed;
	}

	/**
	 * Validate a printout's filterProp into a DIProperty, or null when absent or
	 * identical to the display property (a redundant facet adds nothing and would
	 * only churn the stored spec).
	 *
	 * Parse time is the only author-feedback point — request-time failures surface
	 * as opaque 400s — so an invalid facet must die here as a LuaError.
	 *
	 * @throws LuaError If the facet property is invalid.
	 */
	private function resolveFacetProperty( ?string $facetProp, DIProperty $displayProperty ): ?DIProperty {
		if ( $facetProp === null ) {
			return null;
		}
		try {
			$property = DIProperty::newFromUserLabel( $facetProp );
		} catch ( RuntimeException ) {
			throw new LuaError( 'mw.ext.aggrid.render: invalid filter property "' . $facetProp . '"' );
		}
		// Defensive parity with renderSource's printout loop: some SMW versions
		// return null on malformed labels even though the current signature is `self`.
		// @phan-suppress-next-line PhanImpossibleTypeComparison
		if ( $property === null ) {
			throw new LuaError( 'mw.ext.aggrid.render: invalid filter property "' . $facetProp . '"' );
		}
		if ( $property->getKey() === $displayProperty->getKey() ) {
			return null;
		}
		// Label-less predefined properties (e.g. '_SKEY') construct fine but have no
		// user label; letting one through would store a degenerate '' facet label.
		if ( $property->getLabel() === '' ) {
			throw new LuaError( 'mw.ext.aggrid.render: invalid filter property "' . $facetProp . '"' );
		}
		return $property;
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
