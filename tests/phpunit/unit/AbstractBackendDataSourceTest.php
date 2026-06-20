<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\DataSource\AbstractBackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWikiUnitTestCase;
use RuntimeException;

/**
 * Pure unit coverage of the contract plumbing the abstract base owns (spec resolution,
 * getPage assembly, getColumnValues dedup/partial envelope, cache policy). A fixture
 * subclass stubs the four backend hooks via public callables; no SMW/Bucket or DB.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\AbstractBackendDataSource
 */
class AbstractBackendDataSourceTest extends MediaWikiUnitTestCase {

	private const CACHE_MAX_AGE = 120;
	private const MAX_VALUES = 5;

	/**
	 * @param array|null $stored Value returned by the stubbed SourceSpecStore::getSource.
	 */
	private function newSource( ?array $stored ): AbstractBackendDataSource {
		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( $stored );

		$policy = new CachePolicy( self::CACHE_MAX_AGE );
		return new class( $specStore, $policy, self::MAX_VALUES ) extends AbstractBackendDataSource {
			/** @var callable */
			public $executeQueryFn;
			/** @var callable */
			public $countTotalFn;
			/** @var callable */
			public $mapRowFn;
			/** @var callable */
			public $fetchFn;

			public function __construct( $specStore, CachePolicy $cachePolicy, int $maxValues ) {
				parent::__construct( $specStore, $cachePolicy, $maxValues );
				$this->executeQueryFn = static fn (): array => [];
				$this->countTotalFn = static fn (): int => 0;
				$this->mapRowFn = static fn ( array $r ): array => $r;
				$this->fetchFn = static fn (): array => [ 'entries' => [], 'rowCount' => 0 ];
			}

			protected function specSentinelKey(): string {
				return 'query';
			}

			protected function backendName(): string {
				return 'Test';
			}

			protected function executeQuery(
				array $spec, int $offset, int $size,
				array $sortModel, array $filterModel, string $quickSearch
			): iterable {
				return ( $this->executeQueryFn )( $spec, $offset, $size, $sortModel, $filterModel, $quickSearch );
			}

			protected function countTotal( array $spec, array $filterModel, string $quickSearch ): int {
				return ( $this->countTotalFn )( $spec, $filterModel, $quickSearch );
			}

			protected function mapRow( array $rawRow, array $spec ): array {
				return ( $this->mapRowFn )( $rawRow, $spec );
			}

			protected function fetchColumnValueRows( array $spec, string $column, int $maxValues ): array {
				return ( $this->fetchFn )( $spec, $column, $maxValues );
			}
		};
	}

	public function testGetCachePolicyUsesConfiguredMaxAge(): void {
		$policy = $this->newSource( [ 'spec' => [ 'query' => 'X' ] ] )->getCachePolicy();
		$this->assertSame( self::CACHE_MAX_AGE, $policy->getMaxAge() );
		$this->assertSame( 0, $policy->getStaleWhileRevalidate() );
	}

	public function testGetPageMapsEachRowAndPairsWithTotal(): void {
		$src = $this->newSource( [ 'spec' => [ 'query' => 'X' ] ] );
		$src->executeQueryFn = static fn (): array => [ [ 'raw' => 1 ], [ 'raw' => 2 ] ];
		$src->mapRowFn = static fn ( array $r ): array => [ 'mapped' => $r['raw'] ];
		$src->countTotalFn = static fn (): int => 42;

		$page = $src->getPage( 1, 0, 0, [], [], 50, '' );

		$this->assertSame( [ [ 'mapped' => 1 ], [ 'mapped' => 2 ] ], $page->getRows() );
		$this->assertSame( 42, $page->getTotal() );
	}

	public function testGetPagePassesRequestParamsToHooks(): void {
		$src = $this->newSource( [ 'spec' => [ 'query' => 'X', 'extra' => true ] ] );
		$seenExec = null;
		$seenCount = null;
		$src->executeQueryFn = static function (
			$spec, $offset, $size, $sortModel, $filterModel, $quickSearch
		) use ( &$seenExec ): array {
			$seenExec = [
				'spec' => $spec,
				'offset' => $offset,
				'size' => $size,
				'sortModel' => $sortModel,
				'filterModel' => $filterModel,
				'quickSearch' => $quickSearch,
			];
			return [];
		};
		$src->countTotalFn = static function ( $spec, $filterModel, $quickSearch ) use ( &$seenCount ): int {
			$seenCount = [ 'filterModel' => $filterModel, 'quickSearch' => $quickSearch ];
			return 0;
		};

		$src->getPage( 7, 2, 100, [ [ 'colId' => 'a', 'sort' => 'asc' ] ], [ 'a' => [ 'x' => 1 ] ], 25, 'dragon' );

		// The inner spec ($source['spec']) is what reaches the hooks, not the outer wrapper.
		$this->assertSame( [ 'query' => 'X', 'extra' => true ], $seenExec['spec'] );
		$this->assertSame( 100, $seenExec['offset'] );
		$this->assertSame( 25, $seenExec['size'] );
		$this->assertSame( [ [ 'colId' => 'a', 'sort' => 'asc' ] ], $seenExec['sortModel'] );
		$this->assertSame( [ 'a' => [ 'x' => 1 ] ], $seenExec['filterModel'] );
		$this->assertSame( 'dragon', $seenExec['quickSearch'] );
		// countTotal gets the filter + quick search (so the total mirrors the rows), not paging.
		$this->assertSame( [ 'a' => [ 'x' => 1 ] ], $seenCount['filterModel'] );
		$this->assertSame( 'dragon', $seenCount['quickSearch'] );
	}

	public function testGetColumnValuesDedupesByKeyPreservingFirstOrder(): void {
		$src = $this->newSource( [ 'spec' => [ 'query' => 'X' ] ] );
		$src->fetchFn = static fn (): array => [
			'entries' => [
				[ 'key' => 'b', 'label' => 'B' ],
				[ 'key' => 'a', 'label' => 'A' ],
				[ 'key' => 'b', 'label' => 'B again' ],
			],
			'rowCount' => 2,
		];

		$result = $src->getColumnValues( 1, 0, 'col' );

		$this->assertSame( [
			[ 'key' => 'b', 'label' => 'B again' ],
			[ 'key' => 'a', 'label' => 'A' ],
		], $result['values'] );
		$this->assertFalse( $result['partial'] );
	}

	public function testGetColumnValuesPartialWhenRowCountReachesMax(): void {
		$src = $this->newSource( [ 'spec' => [ 'query' => 'X' ] ] );
		$src->fetchFn = static fn (): array => [ 'entries' => [], 'rowCount' => self::MAX_VALUES ];
		$this->assertTrue( $src->getColumnValues( 1, 0, 'col' )['partial'] );

		$src->fetchFn = static fn (): array => [ 'entries' => [], 'rowCount' => self::MAX_VALUES - 1 ];
		$this->assertFalse( $src->getColumnValues( 1, 0, 'col' )['partial'] );
	}

	public function testGetColumnValuesPassesMaxValuesToHook(): void {
		$src = $this->newSource( [ 'spec' => [ 'query' => 'X' ] ] );
		$seenMax = null;
		$src->fetchFn = static function ( $spec, $column, $maxValues ) use ( &$seenMax ): array {
			$seenMax = $maxValues;
			return [ 'entries' => [], 'rowCount' => 0 ];
		};
		$src->getColumnValues( 1, 0, 'col' );
		$this->assertSame( self::MAX_VALUES, $seenMax );
	}

	public function testResolveSpecMissThrowsWithBackendName(): void {
		$src = $this->newSource( null );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'AGGrid: no Test source spec for page 9 grid 3' );
		$src->getPage( 9, 3, 0, [], [], 50, '' );
	}

	public function testResolveSpecWrongSentinelKeyThrows(): void {
		$src = $this->newSource( [ 'spec' => [ 'somethingElse' => 1 ] ] );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'AGGrid: no Test source spec for page 1 grid 0' );
		$src->getColumnValues( 1, 0, 'col' );
	}
}
