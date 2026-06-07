<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Rest\GridRowsHandler;
use MediaWiki\Extension\AGGrid\Service\InlineDataStore;
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

	private function newHandler(): GridRowsHandler {
		$services = $this->getServiceContainer();
		return new GridRowsHandler(
			$services->getService( 'AGGrid.InlineDataStore' ),
			$services->getPermissionManager(),
			$services->getTitleFactory(),
			$services->getUserFactory()
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

	private function request( int $pageId, int $index ): RequestData {
		return new RequestData( [
			'pathParams' => [ 'pageid' => $pageId, 'rev' => 1, 'index' => $index ],
		] );
	}

	public function testReturnsRowsAndPublicCache(): void {
		$pageId = $this->seed();
		$response = $this->executeHandler( $this->newHandler(), $this->request( $pageId, 0 ) );

		$this->assertSame( 200, $response->getStatusCode() );
		$body = json_decode( (string)$response->getBody(), true );
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $body['rows'] );
		$this->assertStringContainsString( 'public', $response->getHeaderLine( 'Cache-Control' ) );
		$this->assertStringContainsString( 'immutable', $response->getHeaderLine( 'Cache-Control' ) );
	}

	public function testUnknownHandleIs404(): void {
		$pageId = $this->seed();
		$this->expectExceptionCode( 404 );
		$this->executeHandler( $this->newHandler(), $this->request( $pageId, 5 ) );
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
