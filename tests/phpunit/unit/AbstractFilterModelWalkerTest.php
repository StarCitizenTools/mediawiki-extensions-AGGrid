<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\DataSource\AbstractFilterModelWalker;
use MediaWiki\Extension\AGGrid\DataSource\FilterOperator;
use MediaWikiUnitTestCase;

/**
 * Pure unit coverage of the backend-agnostic filter-model descent the base owns: the
 * set/combined/simple per-column dispatch, the combined drop-null/collapse-single/group
 * shape, and the 6-key operator table. A string-emitting fixture subclass stands in for a
 * real backend so the descent is asserted without SMW/Bucket.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\AbstractFilterModelWalker
 * @covers \MediaWiki\Extension\AGGrid\DataSource\FilterOperator
 */
class AbstractFilterModelWalkerTest extends MediaWikiUnitTestCase {

	private function walker(): AbstractFilterModelWalker {
		return new class extends AbstractFilterModelWalker {
			protected function emitSet( string $col, array $model ): mixed {
				return 'SET(' . $col . ':' . implode( ',', $model['values'] ?? [] ) . ')';
			}

			protected function emitSimple( string $col, array $condition, string $family ): mixed {
				// null marks an inexpressible leaf, to exercise drop-null.
				if ( ( $condition['drop'] ?? false ) || $family === 'none' ) {
					return null;
				}
				return 'S(' . $col . ':' . ( $condition['type'] ?? '?' ) . ':' . $family . ')';
			}

			protected function combine( string $operator, array $parts ): mixed {
				return $operator . '[' . implode( ',', $parts ) . ']';
			}

			public function run( string $col, array $model, string $family ): mixed {
				return $this->walkColumn( $col, $model, $family );
			}

			public function op( string $type ): ?FilterOperator {
				return $this->operatorFor( $type );
			}
		};
	}

	public function testDispatchesSetWhenValuesPresent(): void {
		$out = $this->walker()->run( 'c', [ 'values' => [ 'a', 'b' ] ], 'set' );
		$this->assertSame( 'SET(c:a,b)', $out );
	}

	public function testDispatchesSimpleByDefault(): void {
		$out = $this->walker()->run( 'c', [ 'type' => 'equals', 'filter' => 5 ], 'number' );
		$this->assertSame( 'S(c:equals:number)', $out );
	}

	public function testSimpleReturnsNullForUnhandledFamily(): void {
		$this->assertNull( $this->walker()->run( 'c', [ 'type' => 'equals' ], 'none' ) );
	}

	public function testCombinedCollapsesSingleSurvivor(): void {
		$out = $this->walker()->run( 'c', [
			'operator' => 'AND',
			'conditions' => [ [ 'type' => 'x' ] ],
		], 'number' );
		// Single survivor returns bare — no group wrapper.
		$this->assertSame( 'S(c:x:number)', $out );
	}

	public function testCombinedDropsNullConditions(): void {
		$out = $this->walker()->run( 'c', [
			'operator' => 'AND',
			'conditions' => [ [ 'drop' => true ], [ 'type' => 'y' ] ],
		], 'number' );
		$this->assertSame( 'S(c:y:number)', $out );
	}

	public function testCombinedGroupsWithOr(): void {
		$out = $this->walker()->run( 'c', [
			'operator' => 'OR',
			'conditions' => [ [ 'type' => 'a' ], [ 'type' => 'b' ] ],
		], 'number' );
		$this->assertSame( 'OR[S(c:a:number),S(c:b:number)]', $out );
	}

	public function testCombinedDefaultsToAndForNonOrOperator(): void {
		$out = $this->walker()->run( 'c', [
			'operator' => 'AND',
			'conditions' => [ [ 'type' => 'a' ], [ 'type' => 'b' ] ],
		], 'number' );
		$this->assertSame( 'AND[S(c:a:number),S(c:b:number)]', $out );
	}

	public function testCombinedReturnsNullWhenAllConditionsDrop(): void {
		$out = $this->walker()->run( 'c', [
			'operator' => 'OR',
			'conditions' => [ [ 'drop' => true ], [ 'drop' => true ] ],
		], 'number' );
		$this->assertNull( $out );
	}

	public function testOperatorForMapsAllSixComparators(): void {
		$w = $this->walker();
		$this->assertSame( FilterOperator::Equals, $w->op( 'equals' ) );
		$this->assertSame( FilterOperator::NotEqual, $w->op( 'notEqual' ) );
		$this->assertSame( FilterOperator::GreaterThan, $w->op( 'greaterThan' ) );
		$this->assertSame( FilterOperator::GreaterThanOrEqual, $w->op( 'greaterThanOrEqual' ) );
		$this->assertSame( FilterOperator::LessThan, $w->op( 'lessThan' ) );
		$this->assertSame( FilterOperator::LessThanOrEqual, $w->op( 'lessThanOrEqual' ) );
	}

	public function testOperatorForReturnsNullForUnknownType(): void {
		$this->assertNull( $this->walker()->op( 'contains' ) );
		$this->assertNull( $this->walker()->op( 'inRange' ) );
	}
}
