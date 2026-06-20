<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use InvalidArgumentException;
use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\BackendRegistry;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\DataSource\GridPage;
use MediaWiki\Extension\AGGrid\Rest\GridPageHandler;
use MediaWiki\Extension\AGGrid\Service\GridDataPopulator;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\RequestData;
use MediaWiki\Tests\Rest\Handler\HandlerTestTrait;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\AGGrid\Rest\GridPageHandler
 */
class GridPageHandlerTest extends MediaWikiIntegrationTestCase {
	use HandlerTestTrait;
	use MockAuthorityTrait;

	private const PAGE_ID = 42;
	private const CACHE_MAX_AGE = 120;

	/**
	 * @param array|null $source Source spec returned by the stub SourceSpecStore.
	 * @param BackendDataSource|null $dataSource Backend source returned by registry->get(),
	 *   or null to make registry->get() throw InvalidArgumentException.
	 * @param bool $anonCanRead Value returned by PermissionManager::userCan for the anon user.
	 * @param bool $titleExists When false, TitleFactory::newFromID returns null.
	 * @param array|null $populated Value returned by GridDataPopulator::populateFromParse
	 *   (the store-miss fallback); null means the parse yielded no grid for this page.
	 */
	private function newHandler(
		?array $source,
		?BackendDataSource $dataSource,
		bool $anonCanRead = true,
		bool $titleExists = true,
		?array $populated = null
	): GridPageHandler {
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedText' )->willReturn( 'AGGridPageFixture' );

		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromID' )->willReturn( $titleExists ? $title : null );

		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( $source );

		$registry = $this->createMock( BackendRegistry::class );
		if ( $dataSource === null ) {
			$registry->method( 'get' )->willThrowException(
				new InvalidArgumentException( 'No backend registered for type: bogus' )
			);
		} else {
			$backend = $this->createMock( Backend::class );
			$backend->method( 'getDataSource' )->willReturn( $dataSource );
			$registry->method( 'get' )->willReturn( $backend );
		}

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'userCan' )->willReturn( $anonCanRead );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newAnonymous' )->willReturn(
			$this->createMock( User::class )
		);

		$populator = $this->createMock( GridDataPopulator::class );
		$populator->method( 'populateFromParse' )->willReturn( $populated );

		return new GridPageHandler(
			$registry,
			$specStore,
			$permissionManager,
			$titleFactory,
			$userFactory,
			$populator
		);
	}

	private function fakeDataSource(
		?GridPage $page = null,
		bool $throws = false
	): BackendDataSource {
		$ds = $this->createMock( BackendDataSource::class );
		if ( $throws ) {
			$ds->method( 'getPage' )->willThrowException( new RuntimeException( 'secret internal detail' ) );
		} else {
			$ds->method( 'getPage' )->willReturn(
				$page ?? new GridPage( [ [ 'name' => 'Aurora' ] ], 137 )
			);
		}
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		return $ds;
	}

	private function request( array $query = [], int $index = 0 ): RequestData {
		return new RequestData( [
			'pathParams' => [ 'pageid' => self::PAGE_ID, 'rev' => 1, 'index' => $index ],
			'queryParams' => $query,
		] );
	}

	private function smwSpec(): array {
		return [ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ] ];
	}

	public function testReturnsPageBodyAndPublicCache(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource() );
		$response = $this->executeHandler( $handler, $this->request() );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $body['rows'] );
		$this->assertSame( 137, $body['total'] );
		$this->assertArrayNotHasKey( 'nextCursor', $body );
		$this->assertSame(
			'public, max-age=' . self::CACHE_MAX_AGE,
			$response->getHeaderLine( 'Cache-Control' )
		);
	}

	public function testPrivateCacheWhenAnonCannotRead(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource(), false );
		$response = $this->executeHandler( $handler, $this->request() );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame( 'private, max-age=0', $response->getHeaderLine( 'Cache-Control' ) );
	}

	public function testPublicCacheIncludesStaleWhileRevalidateWhenSet(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( 600, 1200 ) );
		$ds->method( 'getPage' )->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request() );

		$this->assertSame( 200, $response->getStatusCode() );
		$this->assertSame(
			'public, max-age=600, stale-while-revalidate=1200',
			$response->getHeaderLine( 'Cache-Control' )
		);
	}

	public function testForbiddenWhenRequesterCannotRead(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource() );
		$this->expectExceptionCode( 403 );
		$this->executeHandler(
			$handler,
			$this->request(),
			[],
			[],
			[],
			[],
			$this->mockAnonNullAuthority()
		);
	}

	public function testUnknownPageIs404(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource(), true, false );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, $this->request() );
	}

	public function testUnknownHandleIs404(): void {
		$handler = $this->newHandler( null, $this->fakeDataSource() );
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, $this->request() );
	}

	public function testStoreMissPopulatesSpecFromParse(): void {
		// aggrid_source has no spec (store miss, e.g. a backend grid added via a
		// transcluded template), but the page's current parse carries it — issue #31.
		$handler = $this->newHandler(
			null,
			$this->fakeDataSource(),
			true,
			true,
			[
				'inline' => [],
				'source' => [
					0 => [ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ], 'hash' => 'h0' ],
				],
			]
		);
		$response = $this->executeHandler( $handler, $this->request() );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $body['rows'] );
	}

	public function testStoreMissWithNoGridAtIndexIs404(): void {
		// Store miss and the parse has no grid at the requested index → genuine 404.
		$handler = $this->newHandler(
			null,
			$this->fakeDataSource(),
			true,
			true,
			[ 'inline' => [], 'source' => [] ]
		);
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, $this->request() );
	}

	public function testResolvedSpecIsThreadedToGetPage(): void {
		// Regression guard for the backend self-heal: on a store miss the handler must pass
		// the populator-resolved spec to getPage so the data source serves from it rather
		// than re-reading the still-deferred store (the 400 the live e2e caught — the other
		// tests miss it because they don't constrain the spec argument).
		$spec = [ 'query' => '[[Category:FromParse]]' ];
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 50, '', $spec )
			->willReturn( new GridPage( [ [ 'name' => 'Aurora' ] ], 1 ) );

		$handler = $this->newHandler(
			null,
			$ds,
			true,
			true,
			[ 'inline' => [], 'source' => [ 0 => [ 'source' => 'smw', 'spec' => $spec, 'hash' => 'h' ] ] ]
		);
		$response = $this->executeHandler( $handler, $this->request() );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testUnknownSourceTypeIs404(): void {
		$handler = $this->newHandler(
			[ 'source' => 'bogus', 'spec' => [] ],
			null
		);
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $handler, $this->request() );
	}

	public function testBackendQueryErrorIs400WithoutLeak(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource( null, true ) );
		try {
			$this->executeHandler( $handler, $this->request() );
			$this->fail( 'Expected a 400 to be thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertStringNotContainsString( 'secret internal detail', $e->getMessage() );
		}
	}

	public function testSizeIsClampedToMaximum(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 200, '' )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'size' => 5000 ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testSizeIsClampedToMinimum(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 1, '' )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'size' => 0 ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testOffsetSortFilterArePassedThrough(): void {
		$sort = [ [ 'colId' => 'name', 'sort' => 'asc' ] ];
		$filter = [ 'name' => [ 'type' => 'contains', 'filter' => 'Au' ] ];

		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 50, $sort, $filter, 25, '' )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [
			'offset' => 50,
			'sort' => json_encode( $sort ),
			'filter' => json_encode( $filter ),
			'size' => 25,
		] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testQuickSearchParamIsPassedThrough(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 50, 'berlin' )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'q' => 'berlin' ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testNegativeOffsetIsClampedToZero(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 50, '' )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'offset' => -5 ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testMalformedSortFallsBackToEmptyArray(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 50, '' )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [
			'sort' => 'not-json{',
			'filter' => '"a string not an array"',
		] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}
}
