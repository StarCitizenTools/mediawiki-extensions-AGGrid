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
class LuaLibraryExpandTest extends MediaWikiIntegrationTestCase {

	private const BASE_OPTIONS = [
		'columnDefs' => [ [ 'field' => 'name' ] ],
		'rowData' => [ [ 'name' => 'Alice' ] ],
	];

	public function testBooleanAndAbsentExpandPass(): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->assertCount( 1, $library->render( self::BASE_OPTIONS ) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'expand' => true ]
		) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'expand' => false ]
		) );
	}

	public function testTableFormsPass(): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		// An empty Lua table is indistinguishable from an empty list; allowed.
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'expand' => [] ]
		) );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'expand' => [ 'label' => 'Open full width' ] ]
		) );
	}

	/**
	 * @dataProvider provideInvalidExpand
	 */
	public function testInvalidShapesThrow( mixed $expand, string $messagePart ): void {
		$library = $this->newLibrary( $this->newStartedParser() );
		try {
			$library->render( self::BASE_OPTIONS + [ 'expand' => $expand ] );
			$this->fail( 'render should throw a LuaError for an invalid expand' );
		} catch ( LuaError $e ) {
			$this->assertStringContainsString( $messagePart, $e->getMessage() );
		}
	}

	public static function provideInvalidExpand(): array {
		return [
			'string' => [ 'yes', 'expand must be a boolean or a table' ],
			'number' => [ 1, 'expand must be a boolean or a table' ],
			'non-string label' => [
				[ 'label' => 5 ], 'expand.label must be a string',
			],
			'unknown key (typo)' => [
				[ 'labl' => 'Wide' ], 'unknown expand key "labl"',
			],
			'list form' => [
				[ 'x' ], 'unknown expand key',
			],
		];
	}

	public function testValidationRunsBeforeSourceProcessing(): void {
		// A bad shape on a source grid must throw before the source branch — i.e.
		// without requiring Semantic MediaWiki to be loaded.
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'expand must be a boolean or a table' );
		$library->render( [
			'source' => [ 'type' => 'smw', 'query' => '[[Category:X]]' ],
			'expand' => 'yes',
		] );
	}

	public function testExpandAndQuickSearchCoexist(): void {
		// Both of the extension's own gridOptions on one grid: neither validator may
		// reject the other's key. (That expand is never stripped for a backend that
		// cannot search — unlike quickSearch — is asserted client-side in
		// tests/vitest/ext.aggrid/mountGrid.test.js, where the backend mount path can
		// be exercised without Semantic MediaWiki installed.)
		$library = $this->newLibrary( $this->newStartedParser() );
		$this->assertCount( 1, $library->render(
			self::BASE_OPTIONS + [ 'expand' => true, 'quickSearch' => true ]
		) );
	}

	/**
	 * A parser started on an unsaved title, so the inline render path embeds rowData
	 * in the placeholder (pageId/revId resolve to null) rather than queuing a store.
	 */
	private function newStartedParser(): Parser {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			Title::newFromText( 'AGGridExpandTest' ),
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
