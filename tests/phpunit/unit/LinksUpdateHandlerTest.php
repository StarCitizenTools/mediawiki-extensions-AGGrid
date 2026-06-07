<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Deferred\LinksUpdate\LinksUpdate;
use MediaWiki\Extension\AGGrid\Hooks\LinksUpdateHandler;
use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Parser\ParserOutput;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Hooks\LinksUpdateHandler
 */
class LinksUpdateHandlerTest extends MediaWikiUnitTestCase {

	public function testFlushesAllQueuedGridsToStore(): void {
		$po = new ParserOutput();
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '0', [ 'rows' => [ [ 'a' => 1 ] ], 'hash' => 'h0' ] );
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '1', [ 'rows' => [ [ 'b' => 2 ] ], 'hash' => 'h1' ] );

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getPageId' )->willReturn( 100 );
		$linksUpdate->method( 'getParserOutput' )->willReturn( $po );

		$store = $this->createMock( GridDataStore::class );
		$store->expects( $this->once() )->method( 'replaceForPage' )->with(
			100,
			[
				0 => [ 'rows' => [ [ 'a' => 1 ] ], 'hash' => 'h0' ],
				1 => [ 'rows' => [ [ 'b' => 2 ] ], 'hash' => 'h1' ],
			]
		);

		$sourceStore = $this->createMock( SourceSpecStore::class );
		$sourceStore->expects( $this->once() )->method( 'replaceForPage' )->with( 100, [] );

		( new LinksUpdateHandler( $store, $sourceStore ) )->onLinksUpdateComplete( $linksUpdate, false );
	}

	public function testNoGridsStillClearsThePage(): void {
		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getPageId' )->willReturn( 100 );
		$linksUpdate->method( 'getParserOutput' )->willReturn( new ParserOutput() );

		$store = $this->createMock( GridDataStore::class );
		$store->expects( $this->once() )->method( 'replaceForPage' )->with( 100, [] );

		$sourceStore = $this->createMock( SourceSpecStore::class );
		$sourceStore->expects( $this->once() )->method( 'replaceForPage' )->with( 100, [] );

		( new LinksUpdateHandler( $store, $sourceStore ) )->onLinksUpdateComplete( $linksUpdate, false );
	}

	public function testSkipsPagesWithNoId(): void {
		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getPageId' )->willReturn( 0 );

		$store = $this->createMock( GridDataStore::class );
		$store->expects( $this->never() )->method( 'replaceForPage' );

		$sourceStore = $this->createMock( SourceSpecStore::class );
		$sourceStore->expects( $this->never() )->method( 'replaceForPage' );

		( new LinksUpdateHandler( $store, $sourceStore ) )->onLinksUpdateComplete( $linksUpdate, false );
	}

	public function testFlushesSourceGridsToSourceStore(): void {
		$sourceGrid = [ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ], 'hash' => 'sh0' ];

		$po = new ParserOutput();
		$po->setExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0', $sourceGrid );

		$linksUpdate = $this->createMock( LinksUpdate::class );
		$linksUpdate->method( 'getPageId' )->willReturn( 200 );
		$linksUpdate->method( 'getParserOutput' )->willReturn( $po );

		$store = $this->createMock( GridDataStore::class );
		$store->expects( $this->once() )->method( 'replaceForPage' )->with( 200, [] );

		$sourceStore = $this->createMock( SourceSpecStore::class );
		$sourceStore->expects( $this->once() )->method( 'replaceForPage' )->with(
			200,
			[ 0 => $sourceGrid ]
		);

		( new LinksUpdateHandler( $store, $sourceStore ) )->onLinksUpdateComplete( $linksUpdate, false );
	}
}
