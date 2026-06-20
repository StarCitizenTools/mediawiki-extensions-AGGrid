<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketColumnMapper;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketFilterTranslator;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketRunner;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RuntimeException;

/**
 * Unit-style coverage for BucketDataSource using a mocked BucketRunner (so the row
 * mapping, filter translation, count plumbing and getColumnValues dedup are tested
 * without a live Bucket DB). The live BucketRunner + the real offset/filter round-trip
 * are validated end-to-end in Task 8. Uses the integration base only for Title.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketDataSource
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketQueryException
 */
class BucketDataSourceTest extends MediaWikiIntegrationTestCase {

	private const PAGE_ID = 4242;

	private function spec(): array {
		return [
			'bucket' => 'item',
			'selects' => [ 'page_name', 'item_type', 'value', 'members', 'tags', 'skill.category' ],
			'fields' => [
				'page_name' => [ 'select' => 'page_name', 'type' => 'PAGE', 'repeated' => false ],
				'item_type' => [ 'select' => 'item_type', 'type' => 'TEXT', 'repeated' => false ],
				'value' => [ 'select' => 'value', 'type' => 'INTEGER', 'repeated' => false ],
				'members' => [ 'select' => 'members', 'type' => 'BOOLEAN', 'repeated' => false ],
				'tags' => [ 'select' => 'tags', 'type' => 'TEXT', 'repeated' => true ],
				'skill__category' => [ 'select' => 'skill.category', 'type' => 'TEXT', 'repeated' => false ],
			],
			'joins' => [ [ 'bucket' => 'skill', 'on' => [ 'item.skill_required', 'skill.skill_name' ] ] ],
			'orderBy' => [ 'field' => 'value', 'direction' => 'DESC' ],
		];
	}

	/**
	 * @param array $selectRows Rows the mocked runner->select() returns.
	 * @param int $total Value the mocked runner->count() returns.
	 * @param array|null $spec Source spec (defaults to the item spec).
	 * @param array|null &$captured Receives the $data passed to runner->select().
	 * @param array|null &$countCaptured Receives the $data passed to runner->count().
	 * @param int $maxValues
	 */
	private function newDataSource(
		array $selectRows,
		int $total = 0,
		?array $spec = null,
		?array &$captured = null,
		?array &$countCaptured = null,
		int $maxValues = 50
	): BucketDataSource {
		$spec ??= $this->spec();

		$runner = $this->createMock( BucketRunner::class );
		$captured = null;
		$runner->method( 'select' )->willReturnCallback(
			static function ( array $data ) use ( &$captured, $selectRows ): array {
				$captured = $data;
				return $selectRows;
			}
		);
		$countCaptured = null;
		$runner->method( 'count' )->willReturnCallback(
			static function ( array $data ) use ( &$countCaptured, $total ): int {
				$countCaptured = $data;
				return $total;
			}
		);

		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( [ 'source' => 'bucket', 'spec' => $spec ] );

		return new BucketDataSource(
			$runner,
			$specStore,
			new BucketFilterTranslator(),
			new BucketColumnMapper(),
			120,
			$maxValues
		);
	}

	// -------------------------------------------------------------------------
	// getPage: row mapping
	// -------------------------------------------------------------------------

	public function testGetPageMapsCellsByType(): void {
		$row = [
			'page_name' => 'Bronze sword',
			'item_type' => 'weapon',
			'value' => 25,
			'members' => false,
			'tags' => [ 'melee', 'weapon' ],
			'skill.category' => 'Combat',
		];
		$source = $this->newDataSource( [ $row ], 100 );

		$page = $source->getPage( self::PAGE_ID, 0, 0, [], [], 10 );
		$rows = $page->getRows();
		$this->assertCount( 1, $rows );
		$mapped = $rows[0];

		$expectedHref = Title::newFromText( 'Bronze sword' )->getLocalURL();
		$this->assertSame( [ 'text' => 'Bronze sword', 'href' => $expectedHref ], $mapped['page_name'] );
		$this->assertSame( 'weapon', $mapped['item_type'] );
		$this->assertSame( 25, $mapped['value'], 'integer cells stay numeric' );
		$this->assertSame( 'false', $mapped['members'], 'boolean cells render as true/false text' );
		$this->assertSame( 'melee, weapon', $mapped['tags'], 'repeated scalars are comma-joined' );
		$this->assertSame( 'Combat', $mapped['skill__category'], 'joined column re-keyed to the dot-free colId' );

		$this->assertSame( 100, $page->getTotal() );
	}

	public function testGetPageOmitsNullCells(): void {
		$source = $this->newDataSource( [ [ 'value' => 25 ] ] );
		$rows = $source->getPage( self::PAGE_ID, 0, 0, [], [], 10 )->getRows();
		$this->assertArrayNotHasKey( 'page_name', $rows[0] );
		$this->assertArrayNotHasKey( 'item_type', $rows[0] );
		$this->assertSame( 25, $rows[0]['value'] );
	}

	public function testRepeatedPageRendersAsLinkList(): void {
		$spec = $this->spec();
		$spec['fields']['tags'] = [ 'select' => 'tags', 'type' => 'PAGE', 'repeated' => true ];
		$source = $this->newDataSource( [ [ 'tags' => [ 'Bronze sword', 'Iron sword' ] ] ], 0, $spec );

		$rows = $source->getPage( self::PAGE_ID, 0, 0, [], [], 10 )->getRows();
		$cell = $rows[0]['tags'];
		$this->assertCount( 2, $cell['links'] );
		$this->assertSame( 'Bronze sword', $cell['links'][0]['text'] );
	}

	// -------------------------------------------------------------------------
	// getPage: query construction
	// -------------------------------------------------------------------------

	public function testGetPageBuildsBaseData(): void {
		$captured = null;
		$source = $this->newDataSource( [], 0, null, $captured );

		$source->getPage( self::PAGE_ID, 0, 30, [], [], 15 );

		$this->assertSame( 'item', $captured['bucketName'] );
		$this->assertSame(
			[ 'page_name', 'item_type', 'value', 'members', 'tags', 'skill.category' ],
			$captured['selects']
		);
		$this->assertSame(
			[ [ 'bucketName' => 'skill', 'cond' => [ 'item.skill_required', 'skill.skill_name' ] ] ],
			$captured['joins']
		);
		$this->assertSame( 15, $captured['limit_arg'] );
		$this->assertSame( 30, $captured['offset_arg'] );
		$this->assertSame( [ 'op' => 'AND', 'operands' => [] ], $captured['wheres'] );
	}

	public function testGetPageDefaultsToSpecOrderBy(): void {
		$captured = null;
		$source = $this->newDataSource( [], 0, null, $captured );
		$source->getPage( self::PAGE_ID, 0, 0, [], [], 10 );
		$this->assertSame( [ 'fieldName' => 'value', 'direction' => 'DESC' ], $captured['orderBy'] );
	}

	public function testGetPageSortOverridesDefault(): void {
		$captured = null;
		$source = $this->newDataSource( [], 0, null, $captured );
		$source->getPage( self::PAGE_ID, 0, 0, [ [ 'colId' => 'item_type', 'sort' => 'asc' ] ], [], 10 );
		$this->assertSame( [ 'fieldName' => 'item_type', 'direction' => 'ASC' ], $captured['orderBy'] );
	}

	public function testGetPageNumberFilterAppliedToRowsAndCount(): void {
		$captured = null;
		$countCaptured = null;
		$source = $this->newDataSource( [], 0, null, $captured, $countCaptured );

		$source->getPage(
			self::PAGE_ID, 0, 0, [],
			[ 'value' => [ 'type' => 'greaterThan', 'filter' => 1000 ] ],
			10
		);

		$this->assertSame(
			[ 'op' => 'AND', 'operands' => [ [ 'value', '>', 1000 ] ] ],
			$captured['wheres']
		);
		$this->assertSame(
			$captured['wheres'],
			$countCaptured['wheres'],
			'the count query filters identically to the rows query'
		);
	}

	public function testGetPageSetFilterUsesOrGroup(): void {
		$captured = null;
		$source = $this->newDataSource( [], 0, null, $captured );
		$source->getPage(
			self::PAGE_ID, 0, 0, [],
			[ 'item_type' => [ 'values' => [ 'weapon' ] ] ],
			10
		);
		$this->assertSame(
			[ 'op' => 'AND', 'operands' => [
				[ 'op' => 'OR', 'operands' => [ [ 'item_type', '=', 'weapon' ] ] ],
			] ],
			$captured['wheres']
		);
	}

	public function testGetPageResolvesJoinedColumnFilterToSelectString(): void {
		$captured = null;
		$source = $this->newDataSource( [], 0, null, $captured );
		$source->getPage(
			self::PAGE_ID, 0, 0, [],
			[ 'skill__category' => [ 'values' => [ 'Combat' ] ] ],
			10
		);
		$this->assertSame(
			[ [ 'op' => 'OR', 'operands' => [ [ 'skill.category', '=', 'Combat' ] ] ] ],
			$captured['wheres']['operands']
		);
	}

	public function testGetPageMergesBaseWhere(): void {
		$spec = $this->spec();
		$spec['where'] = [ [ 'equipable', '=', true ] ];
		$captured = null;
		$source = $this->newDataSource( [], 0, $spec, $captured );
		$source->getPage(
			self::PAGE_ID, 0, 0, [],
			[ 'value' => [ 'type' => 'greaterThan', 'filter' => 5 ] ],
			10
		);
		$this->assertSame(
			[ [ 'equipable', '=', true ], [ 'value', '>', 5 ] ],
			$captured['wheres']['operands'],
			'base where is ANDed before the AG Grid filter conditions'
		);
	}

	public function testCountQueryBoundedAndSingleField(): void {
		$countCaptured = null;
		$source = $this->newDataSource( [], 0, null, $unused, $countCaptured );
		$source->getPage( self::PAGE_ID, 0, 0, [], [], 10 );
		$this->assertSame( 5000, $countCaptured['limit_arg'], 'count is bounded by MAX_LIMIT' );
		$this->assertSame(
			[ 'page_name' ],
			$countCaptured['selects'],
			'count query selects a single non-null column (fetchRowCount requires one field)'
		);
	}

	// -------------------------------------------------------------------------
	// getColumnValues
	// -------------------------------------------------------------------------

	public function testGetColumnValuesDedupes(): void {
		$captured = null;
		$source = $this->newDataSource(
			[
				[ 'item_type' => 'weapon' ],
				[ 'item_type' => 'armour' ],
				[ 'item_type' => 'weapon' ],
			],
			0, null, $captured
		);

		$result = $source->getColumnValues( self::PAGE_ID, 0, 'item_type' );

		$this->assertSame(
			[
				[ 'key' => 'weapon', 'label' => 'weapon' ],
				[ 'key' => 'armour', 'label' => 'armour' ],
			],
			$result['values']
		);
		$this->assertFalse( $result['partial'] );
		$this->assertSame( [ 'item_type' ], $captured['selects'], 'values query selects only the one column' );
	}

	public function testGetColumnValuesEnumeratesRepeated(): void {
		$source = $this->newDataSource(
			[
				[ 'tags' => [ 'melee', 'weapon' ] ],
				[ 'tags' => [ 'melee' ] ],
			]
		);
		$result = $source->getColumnValues( self::PAGE_ID, 0, 'tags' );
		$this->assertSame(
			[
				[ 'key' => 'melee', 'label' => 'melee' ],
				[ 'key' => 'weapon', 'label' => 'weapon' ],
			],
			$result['values']
		);
	}

	public function testGetColumnValuesBooleanKeysAndLabels(): void {
		$source = $this->newDataSource(
			[
				[ 'members' => true ],
				[ 'members' => false ],
				[ 'members' => true ],
			]
		);
		$result = $source->getColumnValues( self::PAGE_ID, 0, 'members' );
		$this->assertSame(
			[
				[ 'key' => '1', 'label' => 'true' ],
				[ 'key' => '0', 'label' => 'false' ],
			],
			$result['values']
		);
	}

	public function testGetColumnValuesPartialWhenCapped(): void {
		$source = $this->newDataSource(
			[ [ 'item_type' => 'a' ], [ 'item_type' => 'b' ] ],
			0, null, $unused, $unused2, 2
		);
		$result = $source->getColumnValues( self::PAGE_ID, 0, 'item_type' );
		$this->assertTrue( $result['partial'] );
		$this->assertSame( 2, $unused['limit_arg'] ?? null );
	}

	// -------------------------------------------------------------------------
	// Errors + cache
	// -------------------------------------------------------------------------

	public function testUnknownGridThrows(): void {
		$runner = $this->createMock( BucketRunner::class );
		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( null );
		$source = new BucketDataSource(
			$runner, $specStore, new BucketFilterTranslator(), new BucketColumnMapper(), 120, 50
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no Bucket source spec' );
		$source->getPage( 999, 0, 0, [], [], 10 );
	}

	public function testUndeclaredFilterColumnThrows(): void {
		$source = $this->newDataSource( [] );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'undeclared column' );
		$source->getPage( self::PAGE_ID, 0, 0, [], [ 'nope' => [ 'values' => [ 'x' ] ] ], 10 );
	}

	public function testUndeclaredValuesColumnThrows(): void {
		$source = $this->newDataSource( [] );
		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'undeclared column' );
		$source->getColumnValues( self::PAGE_ID, 0, 'nope' );
	}

	public function testCachePolicy(): void {
		$source = $this->newDataSource( [] );
		$policy = $source->getCachePolicy();
		$this->assertSame( 120, $policy->getMaxAge() );
		$this->assertSame( 0, $policy->getStaleWhileRevalidate() );
	}
}
