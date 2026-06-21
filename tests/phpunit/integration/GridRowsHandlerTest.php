<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Rest\GridRowsHandler;
use MediaWiki\Extension\AGGrid\Service\GridDataPopulator;
use MediaWiki\Extension\AGGrid\Service\InlineDataStore;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Rest\GridRowsHandler
 * @group Database
 */
class GridRowsHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockAuthorityTrait;

	protected function setUp(): void {
		parent::setUp();
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Bucket' ) ) {
			// When Bucket is also installed (a combined dev environment), saving the fixture
			// page otherwise fires Bucket's writePuts against bucket_pages through its
			// restricted DB user, which cannot reach the cloned, prefixed test tables. The
			// inline /rows path under test doesn't depend on that post-save flush.
			$this->clearHook( 'LinksUpdateComplete' );
		}
	}

	/**
	 * @inheritDoc
	 */
	protected function getSchemaOverrides( IMaintainableDatabase $db ) {
		// Editing a page fires LinksUpdateComplete, which flushes both the inline
		// (aggrid_data) and backend (aggrid_source) stores, so both tables must exist.
		$dir = dirname( __DIR__, 3 ) . '/sql/' . $db->getType();
		return [
			'create' => [ 'aggrid_data', 'aggrid_source' ],
			'scripts' => [ "$dir/tables-generated.sql", "$dir/patch-aggrid_source.sql" ],
		];
	}

	/**
	 * @param array|null $populated Value returned by GridDataPopulator::populateFromParse
	 *   (the store-miss fallback); null means the parse yielded no grid for this page.
	 */
	private function newHandler( ?array $populated = null ): GridRowsHandler {
		$services = $this->getServiceContainer();
		$populator = $this->createMock( GridDataPopulator::class );
		$populator->method( 'populateFromParse' )->willReturn( $populated );
		return new GridRowsHandler(
			$services->getService( 'AGGrid.InlineDataStore' ),
			$services->getPermissionManager(),
			$services->getTitleFactory(),
			$services->getUserFactory(),
			$populator,
			$services->getService( 'AGGrid.CachePolicyResolver' )
		);
	}

	private function seed(): int {
		$page = $this->getExistingTestPage( 'AGGridRestFixture' );
		$pageId = $page->getId();
		/** @var InlineDataStore $store */
		$store = $this->getServiceContainer()->getService( 'AGGrid.InlineDataStore' );
		$store->replaceForPage( $pageId, [
			0 => [ 'rows' => [ [ 'name' => 'Aurora' ] ], 'hash' => sha1( '[{"name":"Aurora"}]' ) ],
		] );
		return $pageId;
	}

	private function request( int $pageId, int $index, string $token = '1' ): RequestData {
		return new RequestData( [
			'pathParams' => [ 'pageid' => $pageId, 'token' => $token, 'index' => $index ],
		] );
	}

	public function testReturnsRowsAndPublicCacheOnHashMatch(): void {
		$pageId = $this->seed();
		$token = sha1( '[{"name":"Aurora"}]' );
		$response = $this->executeHandler( $this->newHandler(), $this->request( $pageId, 0, $token ) );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $body['rows'] );
		$cc = $response->getHeaderLine( 'Cache-Control' );
		$this->assertStringContainsString( 'public', $cc );
		$this->assertStringContainsString( 'max-age=86400', $cc );
		$this->assertStringContainsString( 'stale-while-revalidate=604800', $cc );
		$this->assertStringNotContainsString( 'immutable', $cc );
	}

	public function testMismatchedTokenServesNoStore(): void {
		// Store has Aurora and the parse yields nothing, so we serve the stored rows under no-store (never cached).
		$pageId = $this->seed();
		$response = $this->executeHandler(
			$this->newHandler(),
			$this->request( $pageId, 0, 'stale-token' )
		);
		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $body['rows'] );
		$cc = $response->getHeaderLine( 'Cache-Control' );
		$this->assertStringContainsString( 'no-store', $cc );
		$this->assertStringNotContainsString( 'public', $cc );
		$this->assertStringNotContainsString( 'max-age', $cc );
	}

	public function testStaleStoreReDerivesFreshRowsFromParse(): void {
		// Store has Aurora; URL token is Borealis's hash; the current parse (populator) yields
		// Borealis. The handler re-derives, the served hash now equals the token → match + cache.
		$pageId = $this->seed();
		$borealis = [ [ 'name' => 'Borealis' ] ];
		$token = sha1( (string)json_encode( $borealis ) );
		$handler = $this->newHandler( [
			'inline' => [ 0 => [ 'rows' => $borealis, 'hash' => $token ] ],
			'source' => [],
		] );
		$response = $this->executeHandler( $handler, $this->request( $pageId, 0, $token ) );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( $borealis, $body['rows'] );
		$this->assertStringContainsString( 'max-age=86400', $response->getHeaderLine( 'Cache-Control' ) );
	}

	public function testUnknownHandleIs404(): void {
		$pageId = $this->seed();
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $this->newHandler(), $this->request( $pageId, 5 ) );
	}

	public function testStoreMissPopulatesRowsFromParse(): void {
		// Page exists but aggrid_data was never written (grid added via a transcluded
		// template, no per-page edit) — issue #31. The populator returns the grid read
		// from the page's current parse and the handler serves it in this response.
		$pageId = $this->getExistingTestPage( 'AGGridLazyFixture' )->getId();
		$handler = $this->newHandler( [
			'inline' => [ 0 => [ 'rows' => [ [ 'name' => 'Luna' ] ], 'hash' => 'h0' ] ],
			'source' => [],
		] );
		$response = $this->executeHandler( $handler, $this->request( $pageId, 0 ) );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [ [ 'name' => 'Luna' ] ], $body['rows'] );
	}

	public function testStoreMissServesZeroRowGrid(): void {
		// A real grid authored with zero rows must serve [] (not 404): the populate
		// path keys on index presence, not row-array truthiness.
		$pageId = $this->getExistingTestPage( 'AGGridZeroRowFixture' )->getId();
		$handler = $this->newHandler( [
			'inline' => [ 0 => [ 'rows' => [], 'hash' => 'h0' ] ],
			'source' => [],
		] );
		$response = $this->executeHandler( $handler, $this->request( $pageId, 0 ) );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [], $body['rows'] );
	}

	public function testStoreMissWithNoGridAtIndexIs404(): void {
		// Store miss and the parse has no grid at the requested index → genuine 404.
		$pageId = $this->getExistingTestPage( 'AGGridNoGridFixture' )->getId();
		$handler = $this->newHandler( [ 'inline' => [], 'source' => [] ] );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, $this->request( $pageId, 0 ) );
	}

	public function testForbiddenWhenRequesterCannotRead(): void {
		$pageId = $this->seed();
		$this->expectExceptionCode( 403 );
		$this->executeHandler(
			$this->newHandler(),
			$this->request( $pageId, 0 ),
			[],
			[],
			[],
			[],
			$this->mockAnonNullAuthority()
		);
	}

	public function testUnknownPageIs404(): void {
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $this->newHandler(), $this->request( 999999, 0 ) );
	}

	public function testPrivateCacheWhenAnonCannotRead(): void {
		$pageId = $this->seed();
		$this->setGroupPermissions( '*', 'read', false );

		$response = $this->executeHandler(
			$this->newHandler(),
			$this->request( $pageId, 0 ),
			[],
			[],
			[],
			[],
			$this->mockRegisteredAuthorityWithPermissions( [ 'read' ] )
		);

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertStringContainsString( 'private', $response->getHeaderLine( 'Cache-Control' ) );
	}
}
