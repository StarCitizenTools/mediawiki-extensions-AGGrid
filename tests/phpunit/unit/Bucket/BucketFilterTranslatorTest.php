<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketFilterTranslator;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketFilterTranslator
 */
class BucketFilterTranslatorTest extends MediaWikiUnitTestCase {

	private BucketFilterTranslator $translator;

	/** colId -> select string (identity by default; a qualified mapping for joins). */
	private \Closure $selectOf;

	protected function setUp(): void {
		parent::setUp();
		$this->translator = new BucketFilterTranslator();
		$this->selectOf = static fn ( string $colId ): string => $colId === 'skill__category'
			? 'skill.category'
			: $colId;
	}

	/** A familyOf resolver from a fixed colId -> family map. */
	private function familyMap( array $map ): \Closure {
		return static fn ( string $colId ): string => $map[$colId] ?? 'none';
	}

	// -------------------------------------------------------------------------
	// toOperands — number family
	// -------------------------------------------------------------------------

	public function testNumberEquals(): void {
		$ops = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'equals', 'filter' => 5 ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame( [ [ 'value', '=', 5 ] ], $ops );
	}

	public function testNumberComparators(): void {
		foreach ( [
			'notEqual' => '!=',
			'greaterThan' => '>',
			'greaterThanOrEqual' => '>=',
			'lessThan' => '<',
			'lessThanOrEqual' => '<=',
		] as $type => $op ) {
			$ops = $this->translator->toOperands(
				[ 'value' => [ 'type' => $type, 'filter' => 3 ] ],
				$this->selectOf,
				$this->familyMap( [ 'value' => 'number' ] )
			);
			$this->assertSame( [ [ 'value', $op, 3 ] ], $ops, "type $type" );
		}
	}

	public function testNumberInRange(): void {
		$ops = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'inRange', 'filter' => 1, 'filterTo' => 10 ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'AND', 'operands' => [ [ 'value', '>=', 1 ], [ 'value', '<=', 10 ] ] ] ],
			$ops
		);
	}

	public function testNumberInRangeOpenEnded(): void {
		$low = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'inRange', 'filter' => 1 ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame( [ [ 'value', '>=', 1 ] ], $low );

		$high = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'inRange', 'filterTo' => 10 ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame( [ [ 'value', '<=', 10 ] ], $high );
	}

	public function testNumberBlankAndNotBlank(): void {
		$blank = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'blank' ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame( [ [ 'value', '=', '&&NULL&&' ] ], $blank );

		$notBlank = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'notBlank' ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame( [ [ 'value', '!=', '&&NULL&&' ] ], $notBlank );
	}

	public function testNumberMissingValueDropped(): void {
		$ops = $this->translator->toOperands(
			[ 'value' => [ 'type' => 'equals' ] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame( [], $ops );
	}

	public function testNumberCombined(): void {
		$ops = $this->translator->toOperands(
			[ 'value' => [
				'operator' => 'OR',
				'conditions' => [
					[ 'type' => 'lessThan', 'filter' => 5 ],
					[ 'type' => 'greaterThan', 'filter' => 10 ],
				],
			] ],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'OR', 'operands' => [ [ 'value', '<', 5 ], [ 'value', '>', 10 ] ] ] ],
			$ops
		);
	}

	// -------------------------------------------------------------------------
	// toOperands — set family (always wrapped, no single-value collapse)
	// -------------------------------------------------------------------------

	public function testSetInclude(): void {
		$ops = $this->translator->toOperands(
			[ 'item_type' => [ 'values' => [ 'sword', 'shield' ] ] ],
			$this->selectOf,
			$this->familyMap( [ 'item_type' => 'set' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'OR', 'operands' => [
				[ 'item_type', '=', 'sword' ],
				[ 'item_type', '=', 'shield' ],
			] ] ],
			$ops
		);
	}

	public function testSetIncludeSingleStaysWrapped(): void {
		// A single value must stay inside the OR group so a repeated-field condition
		// still routes through Bucket's subquery path (a bare condition would not).
		$ops = $this->translator->toOperands(
			[ 'tags' => [ 'values' => [ 'melee' ] ] ],
			$this->selectOf,
			$this->familyMap( [ 'tags' => 'set' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'OR', 'operands' => [ [ 'tags', '=', 'melee' ] ] ] ],
			$ops
		);
	}

	public function testSetExclude(): void {
		$ops = $this->translator->toOperands(
			[ 'item_type' => [ 'values' => [ 'sword' ], 'exclude' => true ] ],
			$this->selectOf,
			$this->familyMap( [ 'item_type' => 'set' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'AND', 'operands' => [ [ 'item_type', '!=', 'sword' ] ] ] ],
			$ops
		);
	}

	public function testSetEmptyValuesDropped(): void {
		$ops = $this->translator->toOperands(
			[ 'item_type' => [ 'values' => [] ] ],
			$this->selectOf,
			$this->familyMap( [ 'item_type' => 'set' ] )
		);
		$this->assertSame( [], $ops );
	}

	public function testSetCoercesValuesToStrings(): void {
		// Boolean set filters send '1'/'0' keys; other set values are page/text strings.
		$ops = $this->translator->toOperands(
			[ 'members' => [ 'values' => [ '1' ] ] ],
			$this->selectOf,
			$this->familyMap( [ 'members' => 'set' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'OR', 'operands' => [ [ 'members', '=', '1' ] ] ] ],
			$ops
		);
	}

	// -------------------------------------------------------------------------
	// toOperands — resolution + skipping
	// -------------------------------------------------------------------------

	public function testResolvesQualifiedColIdToSelectString(): void {
		$ops = $this->translator->toOperands(
			[ 'skill__category' => [ 'values' => [ 'Combat' ] ] ],
			$this->selectOf,
			$this->familyMap( [ 'skill__category' => 'set' ] )
		);
		$this->assertSame(
			[ [ 'op' => 'OR', 'operands' => [ [ 'skill.category', '=', 'Combat' ] ] ] ],
			$ops
		);
	}

	public function testNoneFamilyDropped(): void {
		$ops = $this->translator->toOperands(
			[ 'geo' => [ 'values' => [ 'x' ] ] ],
			$this->selectOf,
			$this->familyMap( [ 'geo' => 'none' ] )
		);
		$this->assertSame( [], $ops );
	}

	public function testMultipleColumnsProduceMultipleOperands(): void {
		$ops = $this->translator->toOperands(
			[
				'value' => [ 'type' => 'greaterThan', 'filter' => 100 ],
				'item_type' => [ 'values' => [ 'sword' ] ],
			],
			$this->selectOf,
			$this->familyMap( [ 'value' => 'number', 'item_type' => 'set' ] )
		);
		$this->assertCount( 2, $ops );
		$this->assertContains( [ 'value', '>', 100 ], $ops );
	}

	// -------------------------------------------------------------------------
	// toOrderBy
	// -------------------------------------------------------------------------

	public function testToOrderByFirstEntry(): void {
		$order = $this->translator->toOrderBy(
			[ [ 'colId' => 'value', 'sort' => 'desc' ] ],
			$this->selectOf
		);
		$this->assertSame( [ 'fieldName' => 'value', 'direction' => 'DESC' ], $order );
	}

	public function testToOrderByResolvesQualifiedAndDefaultsAsc(): void {
		$order = $this->translator->toOrderBy(
			[ [ 'colId' => 'skill__category', 'sort' => 'asc' ] ],
			$this->selectOf
		);
		$this->assertSame( [ 'fieldName' => 'skill.category', 'direction' => 'ASC' ], $order );
	}

	public function testToOrderByUsesOnlyFirstEntry(): void {
		$order = $this->translator->toOrderBy(
			[
				[ 'colId' => 'value', 'sort' => 'desc' ],
				[ 'colId' => 'item_type', 'sort' => 'asc' ],
			],
			$this->selectOf
		);
		$this->assertSame( [ 'fieldName' => 'value', 'direction' => 'DESC' ], $order );
	}

	public function testToOrderByEmptyReturnsNull(): void {
		$this->assertNull( $this->translator->toOrderBy( [], $this->selectOf ) );
	}
}
