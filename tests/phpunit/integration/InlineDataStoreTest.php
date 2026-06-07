<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Service\InlineDataStore;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Service\InlineDataStore
 * @group Database
 */
class InlineDataStoreTest extends MediaWikiIntegrationTestCase {

	/**
	 * @inheritDoc
	 */
	protected function getSchemaOverrides( IMaintainableDatabase $db ) {
		return [
			'create' => [ 'aggrid_data' ],
			'scripts' => [ dirname( __DIR__, 3 ) . '/sql/' . $db->getType() . '/tables-generated.sql' ],
		];
	}

	private function store(): InlineDataStore {
		return $this->getServiceContainer()->getService( 'AGGrid.InlineDataStore' );
	}

	private function grid( array $rows ): array {
		return [ 'rows' => $rows, 'hash' => sha1( (string)json_encode( $rows ) ) ];
	}

	public function testReplaceAndRead(): void {
		$store = $this->store();
		$store->replaceForPage( 100, [ 0 => $this->grid( [ [ 'name' => 'Aurora' ] ] ) ] );

		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $store->getRows( 100, 0 ) );
		$this->assertNull( $store->getRows( 100, 1 ), 'unknown index → null' );
		$this->assertNull( $store->getRows( 999, 0 ), 'unknown page → null' );
	}

	public function testUnchangedReplaceIsNoOp(): void {
		$store = $this->store();
		$grids = [ 0 => $this->grid( [ [ 'name' => 'Aurora' ] ] ) ];
		$store->replaceForPage( 100, $grids );

		$db = $this->getDb();
		$before = $db->newSelectQueryBuilder()->select( 'agd_row_count' )
			->from( 'aggrid_data' )->where( [ 'agd_page_id' => 100 ] )
			->caller( __METHOD__ )->fetchField();

		$store->replaceForPage( 100, $grids );
		$after = $db->newSelectQueryBuilder()->select( 'agd_row_count' )
			->from( 'aggrid_data' )->where( [ 'agd_page_id' => 100 ] )
			->caller( __METHOD__ )->fetchField();

		$this->assertSame( $before, $after );
		$this->assertSame( [ [ 'name' => 'Aurora' ] ], $store->getRows( 100, 0 ) );
	}

	public function testReplaceWithNewDataAndDelete(): void {
		$store = $this->store();
		$store->replaceForPage( 100, [ 0 => $this->grid( [ [ 'name' => 'Aurora' ] ] ) ] );
		$store->replaceForPage( 100, [ 0 => $this->grid( [ [ 'name' => 'Borealis' ] ] ) ] );
		$this->assertSame( [ [ 'name' => 'Borealis' ] ], $store->getRows( 100, 0 ) );

		$store->deleteForPage( 100 );
		$this->assertNull( $store->getRows( 100, 0 ) );
	}
}
