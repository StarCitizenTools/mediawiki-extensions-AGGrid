<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

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
class LuaLibraryQuickSearchTest extends MediaWikiIntegrationTestCase {

	private const BASE_OPTIONS = [
		'columnDefs' => [ [ 'field' => 'name' ] ],
		'rowData' => [ [ 'name' => 'Alice' ] ],
	];

	public function testBooleanAndAbsentQuickSearchPass(): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->assertCount( 1, $library->render( self::BASE_OPTIONS ) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'quickSearch' => true ]
		) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'quickSearch' => false ]
		) );
	}

	public function testTableFormsPass(): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		// An empty Lua table is indistinguishable from an empty list; allowed.
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'quickSearch' => [] ]
		) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'quickSearch' => [
				'placeholder' => 'Find ships…',
				'debounceMs' => 300,
			] ]
		) );
		// Scribunto numbers may arrive as floats.
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'quickSearch' => [ 'debounceMs' => 300.0 ] ]
		) );
	}

	/**
	 * @dataProvider provideInvalidQuickSearch
	 */
	public function testInvalidShapesThrow( mixed $quickSearch, string $messagePart ): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		try {
			$library->render( self::BASE_OPTIONS + [ 'quickSearch' => $quickSearch ] );
			$this->fail( 'render should throw a LuaError for an invalid quickSearch' );
		} catch ( LuaError $e ) {
			$this->assertStringContainsString( $messagePart, $e->getMessage() );
		}
	}

	public static function provideInvalidQuickSearch(): array {
		return [
			'string' => [ 'yes', 'quickSearch must be a boolean or a table' ],
			'number' => [ 1, 'quickSearch must be a boolean or a table' ],
			'non-string placeholder' => [
				[ 'placeholder' => 5 ], 'quickSearch.placeholder must be a string',
			],
			'non-numeric debounceMs' => [
				[ 'debounceMs' => 'fast' ], 'quickSearch.debounceMs must be a non-negative number',
			],
			'negative debounceMs' => [
				[ 'debounceMs' => -1 ], 'quickSearch.debounceMs must be a non-negative number',
			],
			'unknown key (typo)' => [
				[ 'debouceMs' => 200 ], 'unknown quickSearch key "debouceMs"',
			],
			'list form' => [
				[ 'x' ], 'unknown quickSearch key',
			],
			'NAN debounceMs (Lua 0/0)' => [
				[ 'debounceMs' => NAN ], 'quickSearch.debounceMs must be a non-negative number',
			],
			'INF debounceMs (Lua 1/0)' => [
				[ 'debounceMs' => INF ], 'quickSearch.debounceMs must be a non-negative number',
			],
		];
	}

	public function testValidationRunsBeforeSourceProcessing(): void {
		// A bad shape on a source grid must throw before the source branch — i.e.
		// without requiring Semantic MediaWiki to be loaded.
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'quickSearch must be a boolean or a table' );
		$library->render( [
			'source' => [ 'type' => 'smw', 'query' => '[[Category:X]]' ],
			'quickSearch' => 'yes',
		] );
	}

	public function testValidationPrecedesSourceShapeChecks(): void {
		// Even a malformed source (non-table) must not mask a quickSearch error:
		// renderSource() throws on its first statement for this shape, so the
		// quickSearch message can only surface if validation runs first.
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'quickSearch must be a boolean or a table' );
		$library->render( [
			'source' => 'x',
			'quickSearch' => 'yes',
		] );
	}

	/**
	 * A parser started on an unsaved title, so the inline render path embeds rowData
	 * in the placeholder (pageId/revId resolve to null) rather than queuing a store.
	 */
	private function newStartedParser(): Parser {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			Title::newFromText( 'AGGridQuickSearchTest' ),
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
