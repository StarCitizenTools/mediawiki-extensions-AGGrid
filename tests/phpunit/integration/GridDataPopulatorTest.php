<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Extension\AGGrid\Service\GridDataPopulator;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\AGGrid\Service\InlineDataStore;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Status\Status;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IMaintainableDatabase;
use Wikimedia\Rdbms\ReadOnlyMode;

/**
 * @covers \MediaWiki\Extension\AGGrid\Service\GridDataPopulator
 * @group Database
 */
class GridDataPopulatorTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Bucket' ) ) {
			// Saving the fixture page otherwise fires Bucket's writePuts against
			// bucket_pages through its restricted DB user, which cannot reach the cloned
			// test tables. The populator under test writes the stores directly.
			$this->clearHook( 'LinksUpdateComplete' );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getSchemaOverrides( IMaintainableDatabase $db ) {
		$dir = dirname( __DIR__, 3 ) . '/sql/' . $db->getType();
		return [
			'create' => [ 'aggrid_data', 'aggrid_source' ],
			'scripts' => [ "$dir/tables-generated.sql", "$dir/patch-aggrid_source.sql" ],
		];
	}

	private function inlineStore(): InlineDataStore {
		return $this->getServiceContainer()->getService( 'AGGrid.InlineDataStore' );
	}

	private function sourceStore(): SourceSpecStore {
		return $this->getServiceContainer()->getService( 'AGGrid.SourceSpecStore' );
	}

	/**
	 * A ParserOutput carrying one inline grid (index 0) and one backend grid (index 0).
	 */
	private function cannedParserOutput(): ParserOutput {
		$po = new ParserOutput();
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '0', [
			'rows' => [ [ 'name' => 'Aurora' ] ],
			'hash' => sha1( '[{"name":"Aurora"}]' ),
		] );
		$po->setExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0', [
			'source' => 'smw',
			'spec' => [ 'query' => '[[Category:Foo]]' ],
			'hash' => 'sh0',
		] );
		return $po;
	}

	/**
	 * @param ParserOutputAccess $poa
	 * @param ReadOnlyMode|null $readOnlyMode
	 */
	private function newPopulator(
		ParserOutputAccess $poa,
		?ReadOnlyMode $readOnlyMode = null
	): GridDataPopulator {
		$services = $this->getServiceContainer();
		return new GridDataPopulator(
			$services->getWikiPageFactory(),
			$poa,
			$this->inlineStore(),
			$this->sourceStore(),
			$readOnlyMode ?? $services->getReadOnlyMode()
		);
	}

	/** A ParserOutputAccess that serves $po from the parser cache (no parse). */
	private function cachedPoa( ParserOutput $po ): ParserOutputAccess {
		$poa = $this->createMock( ParserOutputAccess::class );
		$poa->method( 'getCachedParserOutput' )->willReturn( $po );
		return $poa;
	}

	private function newPageId( string $name ): int {
		return $this->getExistingTestPage( $name )->getId();
	}

	public function testRebuildWritesBothStoresFromTheParse(): void {
		$pageId = $this->newPageId( 'AGGridPopulatorRebuild' );
		$this->newPopulator( $this->cachedPoa( $this->cannedParserOutput() ) )->rebuild( $pageId );

		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $this->inlineStore()->getRows( $pageId, 0 ) );
		$this->assertSame(
			[ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ] ],
			$this->sourceStore()->getSource( $pageId, 0 )
		);
	}

	public function testRebuildReturnsTheExtractedGrids(): void {
		$pageId = $this->newPageId( 'AGGridPopulatorReturn' );
		$extracted = $this->newPopulator( $this->cachedPoa( $this->cannedParserOutput() ) )->rebuild( $pageId );

		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $extracted['inline'][0]['rows'] );
		$this->assertSame( 'smw', $extracted['source'][0]['source'] );
	}

	public function testCacheMissFallsBackToParse(): void {
		$po = $this->cannedParserOutput();
		$poa = $this->createMock( ParserOutputAccess::class );
		$poa->method( 'getCachedParserOutput' )->willReturn( null );
		$poa->expects( $this->once() )
			->method( 'getParserOutput' )
			->willReturn( Status::newGood( $po ) );

		$pageId = $this->newPageId( 'AGGridPopulatorParse' );
		$this->newPopulator( $poa )->rebuild( $pageId );

		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $this->inlineStore()->getRows( $pageId, 0 ) );
	}

	public function testFailedParseReturnsNullAndWritesNothing(): void {
		$poa = $this->createMock( ParserOutputAccess::class );
		$poa->method( 'getCachedParserOutput' )->willReturn( null );
		$poa->method( 'getParserOutput' )->willReturn( Status::newFatal( 'parser-output-error' ) );

		$pageId = $this->newPageId( 'AGGridPopulatorFailedParse' );
		$this->assertNull( $this->newPopulator( $poa )->rebuild( $pageId ) );
		$this->assertNull( $this->inlineStore()->getRows( $pageId, 0 ) );
	}

	public function testMissingPageReturnsNullWithoutTouchingTheParser(): void {
		$poa = $this->createMock( ParserOutputAccess::class );
		$poa->expects( $this->never() )->method( 'getCachedParserOutput' );
		$poa->expects( $this->never() )->method( 'getParserOutput' );

		$this->assertNull( $this->newPopulator( $poa )->rebuild( 9999999 ) );
	}

	public function testReadOnlyModeSkipsTheWriteButStillReturnsTheGrids(): void {
		$readOnly = $this->createMock( ReadOnlyMode::class );
		$readOnly->method( 'isReadOnly' )->willReturn( true );

		$pageId = $this->newPageId( 'AGGridPopulatorReadOnly' );
		$extracted = $this->newPopulator( $this->cachedPoa( $this->cannedParserOutput() ), $readOnly )
			->rebuild( $pageId );

		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $extracted['inline'][0]['rows'] );
		$this->assertNull( $this->inlineStore()->getRows( $pageId, 0 ) );
	}

	public function testPopulateFromParseServesInBandAndPersistsViaDeferredWrite(): void {
		$pageId = $this->newPageId( 'AGGridPopulatorDeferred' );
		$extracted = $this->newPopulator( $this->cachedPoa( $this->cannedParserOutput() ) )
			->populateFromParse( $pageId );

		// The requested grid is returned in-band for this request (the client mounts a
		// terminal error overlay on a 404 and does not retry, so the first request must
		// itself succeed without waiting on the store write).
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $extracted['inline'][0]['rows'] );

		// The store write is scheduled as a POSTSEND deferred update (so it never blocks
		// the GET response) that re-derives from the parse at flush time. This CLI test
		// harness has no post-send boundary and runs deferred updates eagerly, so we
		// cannot observe the un-written intermediate state here; flush any pending
		// updates and assert the rows are persisted for the next request.
		DeferredUpdates::doUpdates();
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $this->inlineStore()->getRows( $pageId, 0 ) );
	}
}
