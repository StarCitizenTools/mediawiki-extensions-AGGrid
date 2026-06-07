<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use InvalidArgumentException;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\DataSource\DataSourceRegistry;
use MediaWiki\Extension\AGGrid\DataSource\GridPage;
use MediaWiki\Extension\AGGrid\Rest\GridPageHandler;
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
	 */
	private function newHandler(
		?array $source,
		?BackendDataSource $dataSource,
		bool $anonCanRead = true,
		bool $titleExists = true
	): GridPageHandler {
		$title = $this->createMock( Title::class );
		$title->method( 'getPrefixedText' )->willReturn( 'AGGridPageFixture' );

		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'newFromID' )->willReturn( $titleExists ? $title : null );

		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( $source );

		$registry = $this->createMock( DataSourceRegistry::class );
		if ( $dataSource === null ) {
			$registry->method( 'get' )->willThrowException(
				new InvalidArgumentException( 'No data source registered for key: bogus' )
			);
		} else {
			$registry->method( 'get' )->willReturn( $dataSource );
		}

		$permissionManager = $this->createMock( PermissionManager::class );
		$permissionManager->method( 'userCan' )->willReturn( $anonCanRead );

		$userFactory = $this->createMock( UserFactory::class );
		$userFactory->method( 'newAnonymous' )->willReturn(
			$this->createMock( User::class )
		);

		return new GridPageHandler(
			$registry,
			$specStore,
			$permissionManager,
			$titleFactory,
			$userFactory
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
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
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
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 200 )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'size' => 5000 ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testSizeIsClampedToMinimum(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 1 )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'size' => 0 ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testOffsetSortFilterArePassedThrough(): void {
		$sort = [ [ 'colId' => 'name', 'sort' => 'asc' ] ];
		$filter = [ 'name' => [ 'type' => 'contains', 'filter' => 'Au' ] ];

		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 50, $sort, $filter, 25 )
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

	public function testNegativeOffsetIsClampedToZero(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 50 )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [ 'offset' => -5 ] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}

	public function testMalformedSortFallsBackToEmptyArray(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getPage' )
			->with( self::PAGE_ID, 0, 0, [], [], 50 )
			->willReturn( new GridPage( [], 0 ) );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( [
			'sort' => 'not-json{',
			'filter' => '"a string not an array"',
		] ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}
}
