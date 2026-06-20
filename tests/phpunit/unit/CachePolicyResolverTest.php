<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\DataSource\CachePolicyResolver;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\CachePolicyResolver
 * @covers \MediaWiki\Extension\AGGrid\DataSource\CachePolicy
 */
class CachePolicyResolverTest extends MediaWikiUnitTestCase {

	private function resolver(): CachePolicyResolver {
		return new CachePolicyResolver( [
			'inline' => [ 'maxAge' => 86400, 'staleWhileRevalidate' => 604800 ],
			'default' => [ 'maxAge' => 600 ],
			'smw' => [ 'maxAge' => 300, 'staleWhileRevalidate' => 1200 ],
		] );
	}

	public function testResolvesInline(): void {
		$p = $this->resolver()->forSource( 'inline' );
		$this->assertSame( 86400, $p->getMaxAge() );
		$this->assertSame( 604800, $p->getStaleWhileRevalidate() );
	}

	public function testResolvesPerSourceOverride(): void {
		$p = $this->resolver()->forSource( 'smw' );
		$this->assertSame( 300, $p->getMaxAge() );
		$this->assertSame( 1200, $p->getStaleWhileRevalidate() );
	}

	public function testUnknownKeyFallsBackToDefault(): void {
		$p = $this->resolver()->forSource( 'bucket' );
		$this->assertSame( 600, $p->getMaxAge() );
		$this->assertSame( 0, $p->getStaleWhileRevalidate(), 'missing swr defaults to 0' );
	}

	public function testFloorWhenDefaultMissing(): void {
		$p = ( new CachePolicyResolver( [] ) )->forSource( 'whatever' );
		$this->assertSame( 600, $p->getMaxAge() );
		$this->assertSame( 0, $p->getStaleWhileRevalidate() );
	}

	public function testMatchedEntryWithoutMaxAgeFallsBackToDefault(): void {
		$resolver = new CachePolicyResolver( [
			'default' => [ 'maxAge' => 600 ],
			'swronly' => [ 'staleWhileRevalidate' => 1200 ],
		] );
		$p = $resolver->forSource( 'swronly' );
		$this->assertSame( 600, $p->getMaxAge(), 'maxAge absent on the matched entry → fallback' );
		$this->assertSame( 1200, $p->getStaleWhileRevalidate() );
	}

	public function testDefaultEntryWithoutMaxAgeFallsBackToDefault(): void {
		$resolver = new CachePolicyResolver( [ 'default' => [ 'staleWhileRevalidate' => 50 ] ] );
		$p = $resolver->forSource( 'anything' );
		$this->assertSame( 600, $p->getMaxAge() );
		$this->assertSame( 50, $p->getStaleWhileRevalidate() );
	}
}
