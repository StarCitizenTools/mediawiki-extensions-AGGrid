<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDescriptor;
use MediaWiki\Extension\AGGrid\DataSource\BackendRegistry;
use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary
 * @group Database
 */
class LuaLibraryCsvExportTest extends MediaWikiIntegrationTestCase {

	private const BASE_OPTIONS = [
		'columnDefs' => [ [ 'field' => 'name' ] ],
		'rowData' => [ [ 'name' => 'Alice' ] ],
	];

	public function testBooleanAndAbsentCsvExportPass(): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->assertCount( 1, $library->render( self::BASE_OPTIONS ) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'csvExport' => true ]
		) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'csvExport' => false ]
		) );
	}

	public function testTableFormsPass(): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		// An empty Lua table is indistinguishable from an empty list; allowed.
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'csvExport' => [] ]
		) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'csvExport' => [ 'label' => 'Get the data' ] ]
		) );
	}

	/**
	 * @dataProvider provideInvalidCsvExport
	 */
	public function testInvalidShapesThrow( mixed $csvExport, string $messagePart ): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		try {
			$library->render( self::BASE_OPTIONS + [ 'csvExport' => $csvExport ] );
			$this->fail( 'render should throw a LuaError for an invalid csvExport' );
		} catch ( LuaError $e ) {
			$this->assertStringContainsString( $messagePart, $e->getMessage() );
		}
	}

	public static function provideInvalidCsvExport(): array {
		return [
			'string' => [ 'yes', 'csvExport must be a boolean or a table' ],
			'number' => [ 1, 'csvExport must be a boolean or a table' ],
			'non-string label' => [
				[ 'label' => 5 ], 'csvExport.label must be a string',
			],
			'unknown key (typo)' => [
				[ 'labl' => 'CSV' ], 'unknown csvExport key "labl"',
			],
			'list form' => [
				[ 'x' ], 'unknown csvExport key',
			],
		];
	}

	public function testDroppedFromSourceGrids(): void {
		// A stub backend, so this runs without SMW or Bucket installed.
		$backend = $this->createMock( Backend::class );
		$backend->method( 'getType' )->willReturn( 'stub' );
		$backend->method( 'compileSource' )->willReturn( [ [ [ 'field' => 'n' ] ], [ 'q' => 'x' ] ] );
		$backend->method( 'supportsQuickSearch' )->willReturn( true );
		$this->setService( 'AGGrid.BackendRegistry', new BackendRegistry(
			[ new BackendDescriptor( 'stub', null, static fn (): Backend => $backend ) ],
			static fn (): bool => true
		) );

		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [ 'type' => 'stub' ],
			'csvExport' => true,
			'expand' => true,
		] );
		$viewConfig = $this->viewConfigFromPlaceholder( $parser, $result[0] );

		$this->assertArrayNotHasKey( 'csvExport', $viewConfig );
		$this->assertTrue( $viewConfig['expand'] );
	}

	/**
	 * Unstrip the placeholder HTML behind the render() strip marker and decode the
	 * gridOptions JSON the template emits into data-mw-aggrid-options.
	 */
	private function viewConfigFromPlaceholder( Parser $parser, string $stripped ): array {
		$html = $parser->getStripState()->unstripBoth( $stripped );
		$this->assertMatchesRegularExpression( '/data-mw-aggrid-options="(.+?)"/s', $html );
		preg_match( '/data-mw-aggrid-options="(.+?)"/s', $html, $m );
		$decoded = json_decode( htmlspecialchars_decode( $m[1], ENT_QUOTES ), true );
		$this->assertIsArray( $decoded, 'placeholder carries decodable gridOptions JSON' );
		return $decoded;
	}

	/**
	 * A parser started on an unsaved title, so the inline render path embeds rowData
	 * in the placeholder (pageId/revId resolve to null) rather than queuing a store.
	 */
	private function newStartedParser(): Parser {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			Title::newFromText( 'AGGridCsvExportTest' ),
			ParserOptions::newFromAnon(),
			Parser::OT_HTML,
			true
		);
		return $parser;
	}

	private function newLibrary( Parser $parser ): LuaLibrary {
		$engine = $this->createMock( LuaEngine::class );
		$engine->method( 'getParser' )->willReturn( $parser );
		return new LuaLibrary( $engine );
	}
}
