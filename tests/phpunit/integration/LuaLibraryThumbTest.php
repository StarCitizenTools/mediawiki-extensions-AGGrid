<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Extension\Scribunto\Scribunto;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary
 * @group Database
 */
class LuaLibraryThumbTest extends MediaWikiIntegrationTestCase {

	public function testMissingFileReturnsNilButTracksDependency(): void {
		// Disable foreign repos so RepoGroup::findFile does not make HTTP calls.
		$this->overrideConfigValue( MainConfigNames::ForeignFileRepos, [] );

		$parser = $this->newStartedParser();
		$library = $this->newLibrary( $parser );

		$result = $library->thumb( 'File:Definitely-missing-aggrid.png', 120, [] );

		$this->assertSame( [], $result, 'missing file resolves to Lua nil' );
		$this->assertArrayHasKey(
			'Definitely-missing-aggrid.png',
			$parser->getOutput()->getImages(),
			'missing file is still tracked for LinksUpdate'
		);
	}

	public function testInvalidTitleThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->thumb( '<<<bad', 120, [] );
	}

	public function testWidthOutOfRangeThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->thumb( 'File:X.png', 0, [] );
	}

	private function newStartedParser(): Parser {
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			Title::makeTitle( NS_MAIN, 'AGGridThumbTest' ),
			ParserOptions::newFromAnon(),
			Parser::OT_HTML,
			true
		);
		return $parser;
	}

	private function newLibrary( Parser $parser ): LuaLibrary {
		$engine = Scribunto::newDefaultEngine( [ 'parser' => $parser ] );
		$engine->setTitle( $parser->getTitle() );
		return new LuaLibrary( $engine );
	}
}
