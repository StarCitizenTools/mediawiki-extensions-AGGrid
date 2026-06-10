<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use InvalidArgumentException;
use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\BackendRegistry;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\Rest\GridValuesHandler;
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
 * @covers \MediaWiki\Extension\AGGrid\Rest\GridValuesHandler
 */
class GridValuesHandlerTest extends MediaWikiIntegrationTestCase {
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
	): GridValuesHandler {
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

		return new GridValuesHandler(
			$registry,
			$specStore,
			$permissionManager,
			$titleFactory,
			$userFactory
		);
	}

	/**
	 * Build a stub BackendDataSource whose getColumnValues returns the standard fixture.
	 */
	private function fakeDataSource( bool $throws = false ): BackendDataSource {
		$ds = $this->createMock( BackendDataSource::class );
		if ( $throws ) {
			$ds->method( 'getColumnValues' )->willThrowException(
				new RuntimeException( 'secret internal detail' )
			);
		} else {
			$ds->method( 'getColumnValues' )->willReturn( [
				'values' => [
					[ 'key' => 'A', 'label' => 'A' ],
					[ 'key' => 'B', 'label' => 'B' ],
				],
				'partial' => true,
			] );
		}
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		return $ds;
	}

	private function request( string $column = 'myCol', int $index = 0 ): RequestData {
		return new RequestData( [
			'pathParams' => [ 'pageid' => self::PAGE_ID, 'rev' => 1, 'index' => $index ],
			'queryParams' => [ 'column' => $column ],
		] );
	}

	private function smwSpec(): array {
		return [ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ] ];
	}

	public function testReturnsValuesBodyAndPublicCache(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource() );
		$response = $this->executeHandler( $handler, $this->request() );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame(
			[ [ 'key' => 'A', 'label' => 'A' ], [ 'key' => 'B', 'label' => 'B' ] ],
			$body['values']
		);
		$this->assertTrue( $body['partial'] );
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
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource( true ) );
		try {
			$this->executeHandler( $handler, $this->request() );
			$this->fail( 'Expected a 400 to be thrown' );
		} catch ( LocalizedHttpException $e ) {
			$this->assertSame( 400, $e->getCode() );
			$this->assertStringNotContainsString( 'secret internal detail', $e->getMessage() );
		}
	}

	public function testMissingColumnParamIsValidationError(): void {
		$handler = $this->newHandler( $this->smwSpec(), $this->fakeDataSource() );
		// Omit the required 'column' query parameter — expect a 400-level validation error.
		$requestWithoutColumn = new RequestData( [
			'pathParams' => [ 'pageid' => self::PAGE_ID, 'rev' => 1, 'index' => 0 ],
			'queryParams' => [],
		] );
		$this->expectExceptionCode( 400 );
		$this->executeHandler( $handler, $requestWithoutColumn );
	}

	public function testColumnIsPassedToDataSource(): void {
		$ds = $this->createMock( BackendDataSource::class );
		$ds->method( 'getCachePolicy' )->willReturn( new CachePolicy( true, self::CACHE_MAX_AGE ) );
		$ds->expects( $this->once() )
			->method( 'getColumnValues' )
			->with( self::PAGE_ID, 0, 'mySpecificCol' )
			->willReturn( [ 'values' => [], 'partial' => false ] );

		$handler = $this->newHandler( $this->smwSpec(), $ds );
		$response = $this->executeHandler( $handler, $this->request( 'mySpecificCol' ) );
		$this->assertSame( 200, $response->getStatusCode() );
	}
}
