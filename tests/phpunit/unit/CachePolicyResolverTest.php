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
		$this->assertSame( 600, $p->getMaxAge(),
			'maxAge absent on the matched entry → hardcoded DEFAULT_MAX_AGE (not the "default" map entry)' );
		$this->assertSame( 1200, $p->getStaleWhileRevalidate() );
	}

	public function testDefaultEntryWithoutMaxAgeFallsBackToDefault(): void {
		$resolver = new CachePolicyResolver( [ 'default' => [ 'staleWhileRevalidate' => 50 ] ] );
		$p = $resolver->forSource( 'anything' );
		$this->assertSame( 600, $p->getMaxAge() );
		$this->assertSame( 50, $p->getStaleWhileRevalidate() );
	}

	public function testNegativeValuesAreFlooredAtZero(): void {
		$resolver = new CachePolicyResolver( [
			'inline' => [ 'maxAge' => -5, 'staleWhileRevalidate' => -10 ],
		] );
		$p = $resolver->forSource( 'inline' );
		$this->assertSame( 0, $p->getMaxAge(), 'negative maxAge floored to 0' );
		$this->assertSame( 0, $p->getStaleWhileRevalidate(), 'negative swr floored to 0' );
		$this->assertSame( 'public, max-age=0', $p->toPublicCacheControl() );
	}

	public function testToPublicCacheControlWithSwr(): void {
		$this->assertSame(
			'public, max-age=86400, stale-while-revalidate=604800',
			( new CachePolicyResolver( [ 'inline' => [ 'maxAge' => 86400, 'staleWhileRevalidate' => 604800 ] ] ) )
				->forSource( 'inline' )->toPublicCacheControl()
		);
	}

	public function testToPublicCacheControlWithoutSwr(): void {
		$this->assertSame(
			'public, max-age=600',
			( new CachePolicyResolver( [ 'default' => [ 'maxAge' => 600 ] ] ) )
				->forSource( 'default' )->toPublicCacheControl()
		);
	}
}
