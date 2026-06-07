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
use MediaWikiIntegrationTestCase;

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
