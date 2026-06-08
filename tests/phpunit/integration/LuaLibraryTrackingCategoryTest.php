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
class LuaLibraryTrackingCategoryTest extends MediaWikiIntegrationTestCase {

	public function testSuccessfulRenderTagsTheUsageCategory(): void {
		$parser = $this->newStartedParser();

		$result = $this->newLibrary( $parser )->render( [
			'columnDefs' => [ [ 'field' => 'name' ] ],
			'rowData' => [ [ 'name' => 'Alice' ] ],
		] );

		$this->assertCount( 1, $result, 'render returns a strip-marked HTML string' );
		$this->assertContains(
			$this->usageCategoryDBkey(),
			$parser->getOutput()->getCategoryNames(),
			'a successful render adds the page to the usage tracking category'
		);
	}

	public function testFailedRenderDoesNotTagTheUsageCategory(): void {
		$parser = $this->newStartedParser();
		$library = $this->newLibrary( $parser );

		try {
			// Missing rowData throws before the page is tagged.
			$library->render( [ 'columnDefs' => [ [ 'field' => 'name' ] ] ] );
			$this->fail( 'render should throw a LuaError when rowData is missing' );
		} catch ( LuaError ) {
			// expected — fall through to the assertion below.
		}

		$this->assertNotContains(
			$this->usageCategoryDBkey(),
			$parser->getOutput()->getCategoryNames(),
			'a failed render leaves the page out of the usage tracking category'
		);
	}

	/**
	 * The category DB key the usage tracking category resolves to. The message takes
	 * no parameters and is page-independent, so the content-language text matches what
	 * TrackingCategories::resolveTrackingCategory produces for any page.
	 */
	private function usageCategoryDBkey(): string {
		$name = wfMessage( 'aggrid-tracking-category' )->inContentLanguage()->text();
		return Title::makeTitleSafe( NS_CATEGORY, $name )->getDBkey();
	}

	/**
	 * A parser started on an unsaved title, so the inline render path embeds rowData
	 * in the placeholder (pageId/revId resolve to null) rather than queuing a store.
	 */
	private function newStartedParser(): Parser {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			Title::newFromText( 'AGGridTrackingCategoryTest' ),
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
