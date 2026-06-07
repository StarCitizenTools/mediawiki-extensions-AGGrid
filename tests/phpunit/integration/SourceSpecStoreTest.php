<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Service\SourceSpecStore
 * @group Database
 */
class SourceSpecStoreTest extends MediaWikiIntegrationTestCase {

	/**
	 * @inheritDoc
	 */
	protected function getSchemaOverrides( IMaintainableDatabase $db ) {
		return [
			'create' => [ 'aggrid_source' ],
			'scripts' => [ dirname( __DIR__, 3 ) . '/sql/' . $db->getType() . '/patch-aggrid_source.sql' ],
		];
	}

	private function store(): SourceSpecStore {
		return $this->getServiceContainer()->getService( 'AGGrid.SourceSpecStore' );
	}

	private function grid( string $source, array $spec ): array {
		return [ 'source' => $source, 'spec' => $spec, 'hash' => sha1( (string)json_encode( $spec ) ) ];
	}

	public function testReplaceAndRead(): void {
		$store = $this->store();
		$spec = [ 'query' => '[[Category:Foo]]', 'printouts' => [ 'Name' ] ];
		$store->replaceForPage( 100, [ 0 => $this->grid( 'smw', $spec ) ] );

		$result = $store->getSource( 100, 0 );
		$this->assertIsArray( $result );
		$this->assertSame( 'smw', $result['source'] );
		$this->assertSame( $spec, $result['spec'] );

		$this->assertNull( $store->getSource( 100, 1 ), 'unknown index → null' );
		$this->assertNull( $store->getSource( 999, 0 ), 'unknown page → null' );
	}

	public function testUnchangedReplaceIsNoOp(): void {
		$store = $this->store();
		$spec = [ 'query' => '[[Category:Foo]]' ];
		$grids = [ 0 => $this->grid( 'smw', $spec ) ];
		$store->replaceForPage( 100, $grids );

		// Call again with same hash but different spec content — if hash-skip works,
		// the original spec should still be returned (no rewrite occurred).
		$differentSpec = [ 'query' => '[[Category:Bar]]' ];
		$sameHashGrid = [
			'source' => 'smw',
			'spec' => $differentSpec,
			'hash' => sha1( (string)json_encode( $spec ) ),
		];
		$store->replaceForPage( 100, [ 0 => $sameHashGrid ] );

		$result = $store->getSource( 100, 0 );
		$this->assertIsArray( $result );
		$this->assertSame( $spec, $result['spec'], 'Original spec preserved — write was skipped' );
	}

	public function testDeleteForPage(): void {
		$store = $this->store();
		$store->replaceForPage( 100, [ 0 => $this->grid( 'smw', [ 'query' => '[[Category:Foo]]' ] ) ] );
		$store->deleteForPage( 100 );

		$this->assertNull( $store->getSource( 100, 0 ) );
	}

	public function testReplaceWithNewData(): void {
		$store = $this->store();
		$store->replaceForPage( 100, [ 0 => $this->grid( 'smw', [ 'query' => '[[Category:Old]]' ] ) ] );
		$newSpec = [ 'query' => '[[Category:New]]' ];
		$store->replaceForPage( 100, [ 0 => $this->grid( 'smw', $newSpec ) ] );

		$result = $store->getSource( 100, 0 );
		$this->assertIsArray( $result );
		$this->assertSame( $newSpec, $result['spec'] );
	}

	public function testReplaceEmptyGridsDeletesRows(): void {
		$store = $this->store();
		$store->replaceForPage( 100, [ 0 => $this->grid( 'smw', [ 'query' => '[[Category:Foo]]' ] ) ] );
		$store->replaceForPage( 100, [] );

		$this->assertNull( $store->getSource( 100, 0 ) );
	}
}
