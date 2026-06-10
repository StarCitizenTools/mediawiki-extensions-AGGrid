<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use InvalidArgumentException;
use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDescriptor;
use MediaWiki\Extension\AGGrid\DataSource\BackendRegistry;
use MediaWiki\Extension\AGGrid\DataSource\BackendUnavailableException;
use MediaWiki\Extension\AGGrid\DataSource\UnknownBackendException;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\BackendRegistry
 * @covers \MediaWiki\Extension\AGGrid\DataSource\BackendDescriptor
 */
class BackendRegistryTest extends MediaWikiUnitTestCase {

	/** Build a registry whose only "loaded" extensions are those in $loaded. */
	private function registry( array $descriptors, array $loaded = [] ): BackendRegistry {
		return new BackendRegistry(
			$descriptors,
			static fn ( string $ext ): bool => in_array( $ext, $loaded, true )
		);
	}

	private function descriptor( string $type, ?string $ext, Backend $backend ): BackendDescriptor {
		return new BackendDescriptor( $type, $ext, static fn (): Backend => $backend );
	}

	public function testResolvesAndMemoizes(): void {
		$backend = $this->createMock( Backend::class );
		$calls = 0;
		$descriptor = new BackendDescriptor( 'foo', null, static function () use ( $backend, &$calls ): Backend {
			$calls++;
			return $backend;
		} );
		$registry = $this->registry( [ $descriptor ] );

		$this->assertSame( $backend, $registry->get( 'foo' ) );
		$this->assertSame( $backend, $registry->get( 'foo' ) );
		$this->assertSame( 1, $calls, 'factory is invoked once and memoized' );
	}

	public function testResolvesWhenRequiredExtensionLoaded(): void {
		$backend = $this->createMock( Backend::class );
		$registry = $this->registry( [ $this->descriptor( 'foo', 'Ext', $backend ) ], [ 'Ext' ] );
		$this->assertSame( $backend, $registry->get( 'foo' ) );
	}

	public function testUnknownTypeThrows(): void {
		$this->expectException( UnknownBackendException::class );
		$this->registry( [] )->get( 'nope' );
	}

	public function testUnknownTypeIsInvalidArgumentException(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->registry( [] )->get( 'nope' );
	}

	public function testUnavailableExtensionThrowsWithName(): void {
		$backend = $this->createMock( Backend::class );
		$registry = $this->registry( [ $this->descriptor( 'foo', 'MissingExt', $backend ) ] );
		$this->expectException( BackendUnavailableException::class );
		$this->expectExceptionMessage( 'MissingExt' );
		$registry->get( 'foo' );
	}

	public function testUnavailableExtensionIsInvalidArgumentException(): void {
		$backend = $this->createMock( Backend::class );
		$registry = $this->registry( [ $this->descriptor( 'foo', 'MissingExt', $backend ) ] );
		$this->expectException( InvalidArgumentException::class );
		$registry->get( 'foo' );
	}

	public function testHas(): void {
		$backend = $this->createMock( Backend::class );
		$registry = $this->registry( [
			$this->descriptor( 'avail', null, $backend ),
			$this->descriptor( 'unavail', 'MissingExt', $backend ),
		] );
		$this->assertTrue( $registry->has( 'avail' ) );
		$this->assertFalse( $registry->has( 'unavail' ), 'registered but extension absent → not available' );
		$this->assertFalse( $registry->has( 'nope' ) );
	}
}
