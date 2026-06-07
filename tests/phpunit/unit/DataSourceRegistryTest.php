<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use InvalidArgumentException;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\DataSourceRegistry;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\DataSourceRegistry
 */
class DataSourceRegistryTest extends MediaWikiUnitTestCase {

	public function testResolvesRegisteredSource(): void {
		$fake = $this->createMock( BackendDataSource::class );
		$registry = new DataSourceRegistry( [
			'smw' => static function () use ( $fake ): BackendDataSource {
				return $fake;
			},
		] );

		$this->assertSame( $fake, $registry->get( 'smw' ) );
	}

	public function testMemoizes(): void {
		$callCount = 0;
		$fake = $this->createMock( BackendDataSource::class );
		$registry = new DataSourceRegistry( [
			'smw' => static function () use ( $fake, &$callCount ): BackendDataSource {
				$callCount++;
				return $fake;
			},
		] );

		$first = $registry->get( 'smw' );
		$second = $registry->get( 'smw' );

		$this->assertSame( $first, $second );
		$this->assertSame( 1, $callCount );
	}

	public function testUnknownThrows(): void {
		$registry = new DataSourceRegistry( [] );

		$this->expectException( InvalidArgumentException::class );
		$registry->get( 'nope' );
	}
}
