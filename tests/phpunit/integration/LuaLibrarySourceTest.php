<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary
 * @group Database
 */
class LuaLibrarySourceTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
			$this->markTestSkipped( 'Semantic MediaWiki is required for the source path tests.' );
		}
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Bucket' ) ) {
			// When Bucket is also installed (a combined dev environment), saving the fixture
			// page otherwise fires Bucket's writePuts against bucket_pages through its
			// restricted DB user, which cannot reach the cloned, prefixed test tables. The
			// SMW source path under test doesn't depend on that post-save flush.
			$this->clearHook( 'LinksUpdateComplete' );
		}
	}

	protected function getSchemaOverrides( IMaintainableDatabase $db ) {
		// Saving a page fires LinksUpdateComplete, which flushes both the inline
		// (aggrid_data) and backend (aggrid_source) stores, so both tables must exist.
		$dir = dirname( __DIR__, 3 ) . '/sql/' . $db->getType();
		return [
			'create' => [ 'aggrid_data', 'aggrid_source' ],
			'scripts' => [ "$dir/tables-generated.sql", "$dir/patch-aggrid_source.sql" ],
		];
	}

	public function testValidSmwSourceQueuesSpecAndBuildsColumns(): void {
		$parser = $this->newStartedParser();
		$library = $this->newLibrary( $parser );

		$result = $library->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ 'Has population', 'Has mayor' ],
			],
		] );

		$this->assertCount( 1, $result );
		$this->assertIsString( $result[0], 'render returns a strip-marked HTML string' );

		$parserOutput = $parser->getOutput();
		$entry = $parserOutput->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' );
		$this->assertIsArray( $entry, 'a source spec is queued under index 0' );
		$this->assertSame( 'smw', $entry['source'] );

		$spec = $entry['spec'];
		$this->assertSame( '[[Category:City]]', $spec['query'] );
		$this->assertSame( [ 'Has population', 'Has mayor' ], $spec['printouts'] );
		$this->assertNull( $spec['mainlabel'] );

		// No rowData anywhere in the queued data.
		$this->assertArrayNotHasKey( 'rows', $entry );

		$viewConfig = $this->viewConfigFromPlaceholder( $parser, $result[0] );
		$this->assertArrayNotHasKey( 'rowData', $viewConfig, 'viewConfig carries no rowData' );

		$columnDefs = $viewConfig['columnDefs'];
		// Subject column is prepended.
		$this->assertSame( '_subject', $columnDefs[0]['field'] );
		$this->assertSame( 'Page', $columnDefs[0]['headerName'] );
		$this->assertSame( 'aggridLink', $columnDefs[0]['type'] );
		$this->assertFalse( $columnDefs[0]['filter'], 'subject column is not filterable' );

		// Printout columns: field == label, defaults derived from the (page) datatype.
		$this->assertSame( 'Has population', $columnDefs[1]['field'] );
		$this->assertSame( 'Has population', $columnDefs[1]['headerName'] );
		$this->assertSame( 'Has mayor', $columnDefs[2]['field'] );

		// A successful source render tags the page with the usage tracking category.
		$catName = wfMessage( 'aggrid-tracking-category' )->inContentLanguage()->text();
		$this->assertContains(
			Title::makeTitleSafe( NS_CATEGORY, $catName )->getDBkey(),
			$parserOutput->getCategoryNames(),
			'the SMW source render path adds the usage tracking category'
		);
	}

	public function testQueryFragmentListJoinsWithSpaces(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				// Lua sequence of fragments; empties are dropped.
				'query' => [ '[[Category:City]]', '', '[[Population::>1000]]' ],
				'printouts' => [ 'Has population' ],
			],
		] );
		$this->assertCount( 1, $result );

		$spec = $parser->getOutput()
			->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertSame( '[[Category:City]] [[Population::>1000]]', $spec['query'] );
	}

	public function testPrintoutWithExplicitLabel(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ 'Has population=Pop' ],
			],
		] );
		$this->assertCount( 1, $result );

		$parserOutput = $parser->getOutput();
		$spec = $parserOutput->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		// Canonical printout preserves prop=label so SMW's PrintRequest label == field.
		$this->assertSame( [ 'Has population=Pop' ], $spec['printouts'] );
		// The fields map lets SmwDataSource resolve the alias field back to the property.
		$this->assertSame( [ 'Pop' => 'Has population' ], $spec['fields'] );

		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		// Index 1 (after the subject column): field is the LABEL, not the property.
		$this->assertSame( 'Pop', $columnDefs[1]['field'] );
		$this->assertSame( 'Pop', $columnDefs[1]['headerName'] );
	}

	public function testPrintoutTableEntryAndMainlabel(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'prop' => 'Has population', 'label' => 'Pop' ] ],
				'mainlabel' => 'City',
			],
		] );
		$this->assertCount( 1, $result );

		$parserOutput = $parser->getOutput();
		$spec = $parserOutput->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertSame( [ 'Has population=Pop' ], $spec['printouts'] );
		$this->assertSame( 'City', $spec['mainlabel'] );

		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		$this->assertSame( '_subject', $columnDefs[0]['field'] );
		$this->assertSame( 'City', $columnDefs[0]['headerName'], 'mainlabel sets the subject header' );
		$this->assertSame( 'Pop', $columnDefs[1]['field'] );
	}

	public function testMainlabelDashSuppressesSubjectColumn(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ 'Has population' ],
				'mainlabel' => '-',
			],
		] );

		$parserOutput = $parser->getOutput();
		$spec = $parserOutput->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertSame( '-', $spec['mainlabel'] );

		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		// No subject column; first column is the printout.
		$this->assertSame( 'Has population', $columnDefs[0]['field'] );
		foreach ( $columnDefs as $col ) {
			$this->assertNotSame( '_subject', $col['field'] );
		}
	}

	public function testUnknownSourceTypeThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'type' => 'cargo', 'query' => 'x', 'printouts' => [ 'A' ] ],
		] );
	}

	public function testMissingSourceTypeThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'query' => 'x', 'printouts' => [ 'A' ] ],
		] );
	}

	public function testEmptyQueryThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'type' => 'smw', 'query' => '   ', 'printouts' => [ 'A' ] ],
		] );
	}

	public function testEmptyFragmentListQueryThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'type' => 'smw', 'query' => [ '', '  ' ], 'printouts' => [ 'A' ] ],
		] );
	}

	public function testNoPrintoutsThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'type' => 'smw', 'query' => '[[Category:City]]', 'printouts' => [] ],
		] );
	}

	public function testPrintoutWithoutPropertyNameThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'label' => 'Pop' ] ],
			],
		] );
	}

	public function testInvalidPropertyThrows(): void {
		// A bare underscore is rejected by DIProperty::newFromUserLabel.
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ '_' ],
			],
		] );
	}

	public function testPrintoutCarriesPresentationKeysIntoColumnDef(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [
					[
						'prop' => 'Has population',
						'label' => 'State',
						// An arbitrary column-type name: the plumbing passes it through
						// verbatim so a wiki can target a hook-registered renderer.
						'type' => 'wikiStatusBadge',
						'cellRendererParams' => [ 'variantMap' => [ 'Flyable' => 'success' ] ],
					],
					[
						'prop' => 'Has mayor',
						'label' => 'Length',
						'format' => [ 'style' => 'number', 'suffix' => ' m' ],
					],
				],
			],
		] );
		$this->assertCount( 1, $result );

		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		// columnDefs[0] is the subject column; [1] State, [2] Length.
		$state = $columnDefs[1];
		$this->assertSame( 'State', $state['field'] );
		$this->assertSame( 'wikiStatusBadge', $state['type'], 'author type overrides the derived type' );
		$this->assertSame(
			[ 'variantMap' => [ 'Flyable' => 'success' ] ],
			$state['cellRendererParams']
		);

		$length = $columnDefs[2];
		$this->assertSame( 'Length', $length['field'] );
		$this->assertSame( [ 'style' => 'number', 'suffix' => ' m' ], $length['format'] );

		// Presentation keys are display-only: they live on the colDef (placeholder
		// attribute), never in the stored query spec that feeds the cacheable REST path.
		// Printouts are stored as canonical "prop=label" strings, so there is no slot for
		// presentation keys to leak into the spec at all.
		$spec = $parser->getOutput()
			->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertSame( [ 'Has population=State', 'Has mayor=Length' ], $spec['printouts'] );
		$this->assertArrayNotHasKey( 'type', $spec );
		$this->assertArrayNotHasKey( 'cellRendererParams', $spec );
		$this->assertArrayNotHasKey( 'format', $spec );
	}

	public function testNonPresentationPrintoutHasNoExtraKeys(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ 'Has population' ],
			],
		] );
		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		$this->assertArrayNotHasKey( 'cellRendererParams', $columnDefs[1] );
		$this->assertArrayNotHasKey( 'format', $columnDefs[1] );
	}

	public function testFilterPropStoresFacetAndOverridesFilterComponent(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:Ship]]',
				'printouts' => [
					[ 'prop' => 'Has name', 'label' => 'Name', 'filterProp' => 'Has manufacturer' ],
					'Has length',
				],
			],
		] );
		$this->assertCount( 1, $result );

		$spec = $parser->getOutput()
			->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		// Only the faceted column appears in the map; the canonical facet label is stored.
		$this->assertSame( [ 'Name' => 'Has manufacturer' ], $spec['facets'] );

		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		// Undeclared properties default to the Page datatype (_wpg) on the test wiki, so
		// the facet-derived component is the set filter. (A facet whose datatype differs
		// from the display property's is exercised in the browser smoke task, where real
		// typed properties exist.)
		$this->assertSame( 'aggridSet', $columnDefs[1]['filter'] );
		// The facet is a stored-spec concern; it must not leak into client colDef JSON.
		$this->assertArrayNotHasKey( 'filterProp', $columnDefs[1] );
	}

	public function testNoFacetsOmitsSpecKey(): void {
		$parser = $this->newStartedParser();
		$this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ 'Has population' ],
			],
		] );
		$spec = $parser->getOutput()
			->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertArrayNotHasKey(
			'facets',
			$spec,
			'facet-free grids keep byte-identical specs (no spurious aggrid_source rewrites)'
		);
	}

	public function testRedundantFilterPropIsNormalizedAway(): void {
		$parser = $this->newStartedParser();
		$this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'prop' => 'Has name', 'filterProp' => 'Has name' ] ],
			],
		] );
		$spec = $parser->getOutput()
			->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertArrayNotHasKey( 'facets', $spec, 'a facet equal to the display property is a no-op' );
	}

	public function testInvalidFilterPropThrows(): void {
		// A bare underscore is rejected by DIProperty::newFromUserLabel.
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'prop' => 'Has name', 'filterProp' => '_' ] ],
			],
		] );
	}

	public function testNonStringFilterPropThrows(): void {
		// A silently dropped facet would change filtering semantics (the column would
		// quietly filter on the display property), so a non-string filterProp must die
		// at parse time — the only author-feedback point.
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'prop' => 'Has name', 'filterProp' => 123 ] ],
			],
		] );
	}

	public function testBlankFilterPropThrows(): void {
		// Whitespace trims to '', and DIProperty::newFromUserLabel( '' ) throws
		// PropertyLabelNotResolvedException (a RuntimeException) -> LuaError.
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'prop' => 'Has name', 'filterProp' => '  ' ] ],
			],
		] );
	}

	public function testLabelLessPredefinedFilterPropThrows(): void {
		// _SKEY constructs fine (a registered predefined property) but its
		// getLabel() is '' — a degenerate facet label that must not reach the spec.
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [
				'type' => 'smw',
				'query' => '[[Category:City]]',
				'printouts' => [ [ 'prop' => 'Has name', 'filterProp' => '_SKEY' ] ],
			],
		] );
	}

	public function testInlineRenderStillWorks(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'columnDefs' => [ [ 'field' => 'a' ] ],
			'rowData' => [ [ 'a' => 1 ] ],
		] );

		$this->assertCount( 1, $result );
		$this->assertIsString( $result[0] );
		// Inline grids do NOT queue a source spec.
		$this->assertNull(
			$parser->getOutput()->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )
		);
	}

	/**
	 * Unstrip the placeholder HTML behind the render() strip marker and decode the
	 * gridOptions JSON the template emits into data-mw-aggrid-options.
	 */
	private function viewConfigFromPlaceholder( Parser $parser, string $stripped ): array {
		$html = $parser->getStripState()->unstripBoth( $stripped );
		$this->assertMatchesRegularExpression(
			'/data-mw-aggrid-options="(.+?)" data-mw-aggrid-source="smw"/s',
			$html
		);
		preg_match( '/data-mw-aggrid-options="(.+?)" data-mw-aggrid-source="smw"/s', $html, $m );
		$json = htmlspecialchars_decode( $m[1], ENT_QUOTES );
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded, 'placeholder carries decodable gridOptions JSON' );
		return $decoded;
	}

	/**
	 * A parser started against a real saved page so the source path resolves a
	 * non-null pageId + revId (and thus queues the spec rather than embedding it).
	 */
	private function newStartedParser(): Parser {
		$status = $this->insertPage( 'AGGridSourceTest_' . wfRandomString( 8 ) );
		$title = $status['title'];

		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			$title,
			ParserOptions::newFromAnon(),
			Parser::OT_HTML,
			true
		);
		// pageId comes from the saved page; revId falls back to the latest saved
		// revision; isPreview is false for an anon parse — so the canonical
		// (queue-the-spec) branch runs instead of the inline-embed fallback.
		return $parser;
	}

	private function newLibrary( Parser $parser ): LuaLibrary {
		$engine = $this->createMock( LuaEngine::class );
		$engine->method( 'getParser' )->willReturn( $parser );
		return new LuaLibrary( $engine );
	}
}
