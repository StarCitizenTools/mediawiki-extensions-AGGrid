<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use File;
use MediaTransformOutput;
use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\MainConfigNames;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutputLinkTypes;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RepoGroup;

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
		// getImages() is deprecated since MW 1.43; read the media link list instead.
		$media = $parser->getOutput()->getLinkList( ParserOutputLinkTypes::MEDIA );
		$tracked = array_map( static fn ( array $entry ) => $entry['link']->getDBkey(), $media );
		$this->assertContains(
			'Definitely-missing-aggrid.png',
			$tracked,
			'missing file is still tracked for LinksUpdate'
		);
	}

	public function testInvalidTitleThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->thumb( '<<<bad', 120, [] );
	}

	public function testNonFileNamespaceTitleThrows(): void {
		// An explicit non-File namespace prefix overrides the NS_FILE default and must
		// hit the namespace guard. (A bare "Main Page" would resolve to File:Main Page.)
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->thumb( 'Category:Demo', 120, [] );
	}

	public function testWidthBelowMinThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->thumb( 'File:X.png', 0, [] );
	}

	public function testWidthAboveMaxThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->thumb( 'File:X.png', 100000, [] );
	}

	public function testResolvableFileReturnsDescriptorAndHonoursOpts(): void {
		$thumb = $this->createMock( MediaTransformOutput::class );
		$thumb->method( 'isError' )->willReturn( false );
		$thumb->method( 'getUrl' )->willReturn( '/w/images/thumb/x/120px-X.png' );
		$thumb->method( 'getWidth' )->willReturn( 120 );

		$file = $this->createMock( File::class );
		$file->method( 'exists' )->willReturn( true );
		$file->method( 'getTimestamp' )->willReturn( '20240101000000' );
		$file->method( 'getSha1' )->willReturn( 'deadbeef' );
		$file->method( 'transform' )->willReturn( $thumb );

		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFile' )->willReturn( $file );
		$this->setService( 'RepoGroup', $repoGroup );

		$library = $this->newLibrary( $this->newStartedParser() );

		// Default alt is the file's page text; link adds an href.
		$result = $library->thumb( 'File:X.png', 120, [ 'link' => 'Project:Sandbox' ] );
		$this->assertCount( 1, $result );
		$descriptor = $result[0];
		$this->assertSame( '/w/images/thumb/x/120px-X.png', $descriptor['src'] );
		$this->assertSame( 120, $descriptor['width'] );
		$this->assertSame( 'X.png', $descriptor['alt'] );
		$this->assertArrayHasKey( 'href', $descriptor );
		$this->assertNotSame( '', $descriptor['href'] );

		// alt override is honoured.
		$withAlt = $library->thumb( 'File:X.png', 120, [ 'alt' => 'Custom alt' ] );
		$this->assertSame( 'Custom alt', $withAlt[0]['alt'] );
		$this->assertArrayNotHasKey( 'href', $withAlt[0] );
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
		// Mock the engine instead of constructing a real one: the engine's concrete
		// factory API has changed across MW versions, and thumb() only needs the
		// parser (for ParserOutput) plus checkType(), which uses pure type inspection.
		$engine = $this->createMock( LuaEngine::class );
		$engine->method( 'getParser' )->willReturn( $parser );
		return new LuaLibrary( $engine );
	}
}
