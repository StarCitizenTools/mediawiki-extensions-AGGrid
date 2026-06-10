<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration\Smw;

use MediaWiki\Extension\AGGrid\DataSource\Smw\FilterTranslator;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwQueryException;
use MediaWiki\Extension\AGGrid\DataSource\Smw\TypeColumnMapper;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RuntimeException;
use SMW\DataItems\WikiPage as DIWikiPage;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Description;
use SMW\Query\PrintRequest;
use SMW\Query\Query;
use SMW\Query\QueryResult;
use SMW\Query\Result\ResultArray;
use SMW\Store;
use SMWDataItem as DataItem;
use SMWDataValue as DataValue;

/**
 * Unit-style coverage for SmwDataSource using a mocked SMW Store.
 *
 * NOTE on coverage: this exercises the query construction (offset, limit, sort
 * keys, filter Conjunction), the QueryResult -> GridPage plumbing (rows + total
 * from a count query), the row-mapping contract (_subject + printout cells), and
 * the getColumnValues dedup/partial logic. It does NOT exercise SMW's real query
 * engine or live result iteration — a real-data integration test was attempted
 * (seeding via Store::updateData, the same pattern SMW's own DB integration tests
 * use) but SMW's SQLite table setup is not idempotent under this dev environment's
 * cloned-table test DB and crashes on the first test (SMW's own *DBIntegrationTest
 * suites fail identically here). The real offset round-trip and getColumnValues
 * against seeded data are validated end-to-end in Task 15.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Smw\SmwDataSource
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Smw\SmwQueryException
 * @group Database
 */
class SmwDataSourceTest extends MediaWikiIntegrationTestCase {

	private const PAGE_ID = 4242;
	private const POP = 'Population';
	private const CAPITAL = 'Capital';

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
			$this->markTestSkipped( 'Semantic MediaWiki is not available' );
		}
	}

	/**
	 * Build an SmwDataSource over a mock Store whose getQueryResult returns the
	 * given QueryResult, capturing the FIRST Query it was called with into $captured
	 * (the data query; getPage issues a second count-mode query afterwards).
	 *
	 * @param QueryResult $queryResult
	 * @param array|null $spec Source spec, or null for a default City spec.
	 * @param Query|null &$captured Receives the data Query passed to getQueryResult.
	 * @param int $maxValues
	 * @param array|null &$allCaptured Receives EVERY Query passed to getQueryResult,
	 *  in call order (for getPage: data query, then count query).
	 */
	private function newDataSource(
		QueryResult $queryResult,
		?array $spec = null,
		?Query &$captured = null,
		int $maxValues = 50,
		?array &$allCaptured = null
	): SmwDataSource {
		$spec ??= [
			'query' => '[[Category:City]]',
			'printouts' => [ self::POP, self::CAPITAL ],
			'mainlabel' => null,
		];

		$store = $this->createMock( Store::class );
		$captured = null;
		$allCaptured = [];
		$store->method( 'getQueryResult' )->willReturnCallback(
			static function ( Query $query ) use ( &$captured, &$allCaptured, $queryResult ): QueryResult {
				// Only the first (data) query lands in $captured; the count query that
				// follows reuses the same result mock (which also answers
				// getCountValue()). $allCaptured records every query in order.
				if ( $captured === null ) {
					$captured = $query;
				}
				$allCaptured[] = $query;
				return $queryResult;
			}
		);

		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( [ 'source' => 'smw', 'spec' => $spec ] );

		return new SmwDataSource(
			$store,
			$specStore,
			new FilterTranslator(),
			new TypeColumnMapper(),
			120,
			$maxValues
		);
	}

	/**
	 * Build a stub ResultArray that yields the given cell values in order.
	 *
	 * @param int $mode PrintRequest mode constant
	 * @param string $label Print request label
	 * @param array<int, DataValue> $dataValues DataValues returned by getNextDataValue()
	 * @param DIWikiPage|null $subject Result subject (for PRINT_THIS)
	 */
	private function resultArray(
		int $mode,
		string $label,
		array $dataValues,
		?DIWikiPage $subject = null
	): ResultArray {
		$printRequest = $this->createMock( PrintRequest::class );
		$printRequest->method( 'getMode' )->willReturn( $mode );
		$printRequest->method( 'getLabel' )->willReturn( $label );

		$ra = $this->createMock( ResultArray::class );
		$ra->method( 'getPrintRequest' )->willReturn( $printRequest );
		if ( $subject !== null ) {
			$ra->method( 'getResultSubject' )->willReturn( $subject );
		}

		$queue = $dataValues;
		$ra->method( 'getNextDataValue' )->willReturnCallback(
			static function () use ( &$queue ) {
				return $queue === [] ? false : array_shift( $queue );
			}
		);
		return $ra;
	}

	/**
	 * A page-subject result (PRINT_THIS) whose title is $name.
	 */
	private function subjectResultArray( string $name ): ResultArray {
		$subject = $this->createMock( DIWikiPage::class );
		$subject->method( 'getTitle' )->willReturn( $this->title( $name ) );
		return $this->resultArray( PrintRequest::PRINT_THIS, '', [], $subject );
	}

	private function title( string $name ): Title {
		$title = $this->createMock( Title::class );
		$title->method( 'getText' )->willReturn( $name );
		$title->method( 'getLocalURL' )->willReturn( '/wiki/' . $name );
		return $title;
	}

	private function scalarDataValue( string $shortText ): DataValue {
		$item = $this->createMock( DataItem::class );
		$dv = $this->createMock( DataValue::class );
		$dv->method( 'getDataItem' )->willReturn( $item );
		// SmwDataSource maps scalar cells via the wikitext serialization.
		$dv->method( 'getShortWikiText' )->willReturn( $shortText );
		return $dv;
	}

	private function pageDataValue( string $name ): DataValue {
		$item = $this->createMock( DIWikiPage::class );
		$item->method( 'getTitle' )->willReturn( $this->title( $name ) );
		$dv = $this->createMock( DataValue::class );
		$dv->method( 'getDataItem' )->willReturn( $item );
		return $dv;
	}

	/**
	 * A mock QueryResult that yields the given rows then false, and reports the
	 * given total via getCountValue() (read by the count-mode query in getPage).
	 *
	 * @param array<int, array<int, ResultArray>> $rows
	 * @param int $total
	 * @param string[] $errors
	 */
	private function queryResult(
		array $rows,
		int $total = 0,
		array $errors = []
	): QueryResult {
		$qr = $this->createMock( QueryResult::class );
		$queue = $rows;
		$qr->method( 'getNext' )->willReturnCallback(
			static function () use ( &$queue ) {
				return $queue === [] ? false : array_shift( $queue );
			}
		);
		$qr->method( 'getCountValue' )->willReturn( $total );
		$qr->method( 'getErrors' )->willReturn( $errors );
		return $qr;
	}

	// -------------------------------------------------------------------------
	// getPage: row mapping
	// -------------------------------------------------------------------------

	public function testGetPageMapsSubjectAndPrintoutCells(): void {
		$row = [
			$this->subjectResultArray( 'Berlin' ),
			$this->resultArray( PrintRequest::PRINT_PROP, self::POP, [ $this->scalarDataValue( '3600000' ) ] ),
			$this->resultArray( PrintRequest::PRINT_PROP, self::CAPITAL, [ $this->pageDataValue( 'Mitte' ) ] ),
		];
		$source = $this->newDataSource( $this->queryResult( [ $row ], 42 ) );

		$page = $source->getPage( self::PAGE_ID, 0, 0, [], [], 50 );
		$rows = $page->getRows();
		$this->assertCount( 1, $rows );

		$mapped = $rows[0];
		$this->assertSame(
			[ 'text' => 'Berlin', 'href' => '/wiki/Berlin' ],
			$mapped['_subject']
		);
		$this->assertSame( '3600000', $mapped[self::POP] );
		$this->assertSame(
			[ 'text' => 'Mitte', 'href' => '/wiki/Mitte' ],
			$mapped[self::CAPITAL]
		);

		$this->assertSame( 42, $page->getTotal(), 'total comes from the count query' );
	}

	public function testGetPageTakesFirstDataValuePerCell(): void {
		$row = [
			$this->resultArray( PrintRequest::PRINT_PROP, self::POP, [
				$this->scalarDataValue( 'first' ),
				$this->scalarDataValue( 'second' ),
			] ),
		];
		$source = $this->newDataSource( $this->queryResult( [ $row ] ) );

		$rows = $source->getPage( self::PAGE_ID, 0, 0, [], [], 50 )->getRows();
		$this->assertSame( 'first', $rows[0][self::POP] );
	}

	public function testGetPageOmitsEmptyCell(): void {
		$row = [
			$this->resultArray( PrintRequest::PRINT_PROP, self::POP, [] ),
		];
		$source = $this->newDataSource( $this->queryResult( [ $row ] ) );

		$rows = $source->getPage( self::PAGE_ID, 0, 0, [], [], 50 )->getRows();
		$this->assertArrayNotHasKey( self::POP, $rows[0] );
	}

	// -------------------------------------------------------------------------
	// getPage: query construction
	// -------------------------------------------------------------------------

	public function testGetPageBuildsQueryWithOffsetLimitAndSort(): void {
		$captured = null;
		$source = $this->newDataSource(
			$this->queryResult( [] ),
			null,
			$captured
		);

		$source->getPage(
			self::PAGE_ID,
			0,
			75,
			[ [ 'colId' => self::POP, 'sort' => 'desc' ] ],
			[],
			25
		);

		$this->assertInstanceOf( Query::class, $captured );
		$this->assertSame( 75, $captured->getOffset() );
		$this->assertSame( 25, $captured->getLimit() );
		$this->assertSame(
			[ self::POP => 'DESC', '' => 'ASC' ],
			$captured->getSortKeys(),
			'sort keys carry the user sort plus the subject tiebreaker for stable offset paging'
		);
	}

	public function testGetPageResolvesAliasedColumnSortToRealProperty(): void {
		// An aliased printout ('Has population=Pop'): the column field is the alias
		// 'Pop', but the stored 'fields' map resolves it to the real property, so the
		// sort key must be the property DBkey, not the alias.
		$spec = [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population=Pop' ],
			'mainlabel' => null,
			'fields' => [ 'Pop' => 'Has population' ],
		];
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), $spec, $captured );

		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[ [ 'colId' => 'Pop', 'sort' => 'asc' ] ],
			[],
			25
		);

		$this->assertSame(
			[ 'Has_population' => 'ASC', '' => 'ASC' ],
			$captured->getSortKeys(),
			'aliased column sorts by the resolved property DBkey, not the alias'
		);
	}

	public function testGetPageResolvesAliasedColumnFilterToRealProperty(): void {
		$spec = [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population=Pop' ],
			'mainlabel' => null,
			'fields' => [ 'Pop' => 'Has population' ],
		];
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), $spec, $captured );

		// A set filter is detected by the 'values' key before any family lookup, so it
		// builds a Description independent of whether the test wiki knows the property.
		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[],
			[ 'Pop' => [ 'values' => [ '1000' ] ] ],
			25
		);

		$this->assertInstanceOf( Conjunction::class, $captured->getDescription() );
		$qs = $captured->getDescription()->getQueryString();
		$this->assertStringContainsString(
			'Has population',
			$qs,
			'aliased column filters on the resolved property, not the alias'
		);
	}

	public function testGetColumnValuesResolvesAliasToRealPropertyProjection(): void {
		// getColumnValues receives the AG Grid field (alias 'Pop'); the projection
		// query must request the real property, so its non-subject PrintRequest label
		// is the property. We assert via the captured query's print request labels.
		$spec = [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population=Pop' ],
			'mainlabel' => null,
			'fields' => [ 'Pop' => 'Has population' ],
		];
		$rows = [
			[ $this->resultArray(
				PrintRequest::PRINT_PROP,
				'Has population',
				[ $this->scalarDataValue( '5' ) ]
			) ],
		];
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( $rows ), $spec, $captured );

		$result = $source->getColumnValues( self::PAGE_ID, 0, 'Pop' );

		$labels = array_map(
			static fn ( $pr ): string => $pr->getLabel(),
			$captured->getExtraPrintouts()
		);
		$this->assertContains(
			'Has population',
			$labels,
			'projection requests the resolved property, not the alias'
		);
		$this->assertSame( [ [ 'key' => '5', 'label' => '5' ] ], $result['values'] );
	}

	public function testGetPageReturnsTotalFromCountQuery(): void {
		$source = $this->newDataSource( $this->queryResult( [], 318 ) );

		$page = $source->getPage( self::PAGE_ID, 0, 0, [], [], 25 );
		$this->assertSame( 318, $page->getTotal() );
	}

	public function testGetPageAndsFilterDescriptionViaConjunction(): void {
		$captured = null;
		$source = $this->newDataSource(
			$this->queryResult( [] ),
			null,
			$captured
		);

		// A set filter applies to the default (_wpg) property family, so the
		// base description gets ANDed with the filter via a Conjunction.
		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[],
			[ self::CAPITAL => [ 'values' => [ 'Berlin' ] ] ],
			50
		);

		$this->assertInstanceOf( Conjunction::class, $captured->getDescription() );
	}

	public function testGetPageNoFilterLeavesDescriptionUnwrapped(): void {
		$captured = null;
		$source = $this->newDataSource(
			$this->queryResult( [] ),
			null,
			$captured
		);

		$source->getPage( self::PAGE_ID, 0, 0, [], [], 50 );

		$this->assertInstanceOf( Description::class, $captured->getDescription() );
		$this->assertNotInstanceOf(
			Conjunction::class,
			$captured->getDescription(),
			'no filter model leaves the base description unwrapped'
		);
	}

	// -------------------------------------------------------------------------
	// Errors
	// -------------------------------------------------------------------------

	public function testQueryErrorsThrow(): void {
		$source = $this->newDataSource(
			$this->queryResult( [], 0, [ 'broken condition', 'bad sort' ] )
		);

		$this->expectException( SmwQueryException::class );
		$this->expectExceptionMessage( 'broken condition; bad sort' );
		$source->getPage( self::PAGE_ID, 0, 0, [], [], 50 );
	}

	public function testUnknownGridThrows(): void {
		$store = $this->createMock( Store::class );
		$specStore = $this->createMock( SourceSpecStore::class );
		$specStore->method( 'getSource' )->willReturn( null );
		$source = new SmwDataSource(
			$store, $specStore, new FilterTranslator(), new TypeColumnMapper(), 120, 50
		);

		$this->expectExceptionMessage( 'no SMW source spec' );
		$source->getPage( 999, 0, 0, [], [], 10 );
	}

	// -------------------------------------------------------------------------
	// getColumnValues
	// -------------------------------------------------------------------------

	public function testGetColumnValuesDedupesPreservingOrder(): void {
		$rows = [
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::POP, [ $this->scalarDataValue( '5' ) ] ) ],
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::POP, [ $this->scalarDataValue( '7' ) ] ) ],
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::POP, [ $this->scalarDataValue( '5' ) ] ) ],
		];
		$source = $this->newDataSource( $this->queryResult( $rows ) );

		$result = $source->getColumnValues( self::PAGE_ID, 0, self::POP );

		$this->assertSame(
			[
				[ 'key' => '5', 'label' => '5' ],
				[ 'key' => '7', 'label' => '7' ],
			],
			$result['values']
		);
		$this->assertFalse( $result['partial'] );
	}

	public function testGetColumnValuesPartialWhenCapped(): void {
		$rows = [
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::POP, [ $this->scalarDataValue( '1' ) ] ) ],
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::POP, [ $this->scalarDataValue( '2' ) ] ) ],
		];
		$source = $this->newDataSource( $this->queryResult( $rows ), null, $unused, 2 );

		$result = $source->getColumnValues( self::PAGE_ID, 0, self::POP );
		$this->assertTrue( $result['partial'], 'row count reaching maxValues marks the list partial' );
	}

	public function testGetColumnValuesUsesPageCellText(): void {
		$rows = [
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::CAPITAL, [ $this->pageDataValue( 'Paris' ) ] ) ],
		];
		$source = $this->newDataSource( $this->queryResult( $rows ) );

		$result = $source->getColumnValues( self::PAGE_ID, 0, self::CAPITAL );
		$this->assertSame( [ [ 'key' => 'Paris', 'label' => 'Paris' ] ], $result['values'] );
	}

	public function testGetColumnValuesEnumeratesAllValuesPerRow(): void {
		// A multi-valued property (a ship with two manufacturers): the value list must
		// offer BOTH, because a SomeProperty condition matches a row when ANY value
		// matches — a first-value-only list offers entries that cannot be coherently
		// deselected.
		// The leading empty value pins that empties are skipped without masking
		// later values (pre-fix, first-value-only reads would have hidden both).
		$rows = [
			[ $this->resultArray( PrintRequest::PRINT_PROP, self::POP, [
				$this->scalarDataValue( '' ),
				$this->scalarDataValue( 'Origin' ),
				$this->scalarDataValue( 'RSI' ),
			] ) ],
		];
		$source = $this->newDataSource( $this->queryResult( $rows ) );

		$result = $source->getColumnValues( self::PAGE_ID, 0, self::POP );

		$this->assertSame(
			[
				[ 'key' => 'Origin', 'label' => 'Origin' ],
				[ 'key' => 'RSI', 'label' => 'RSI' ],
			],
			$result['values']
		);
	}

	// -------------------------------------------------------------------------
	// Filter facets (issue #20) and closed column resolution
	// -------------------------------------------------------------------------

	/** A Ship spec with a facet: column 'Name' displays Has name, filters on Has manufacturer. */
	private function facetSpec(): array {
		return [
			'query' => '[[Category:Ship]]',
			'printouts' => [ 'Has name=Name' ],
			'mainlabel' => null,
			'fields' => [ 'Name' => 'Has name' ],
			'facets' => [ 'Name' => 'Has manufacturer' ],
		];
	}

	public function testGetPageFiltersOnDeclaredFacetProperty(): void {
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), $this->facetSpec(), $captured );

		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[],
			[ 'Name' => [ 'values' => [ 'Origin Jumpworks' ] ] ],
			25
		);

		$this->assertInstanceOf( Conjunction::class, $captured->getDescription() );
		$qs = $captured->getDescription()->getQueryString();
		$this->assertStringContainsString(
			'Has manufacturer',
			$qs,
			'set-filter conditions run against the facet property'
		);
		$this->assertStringNotContainsString(
			'Has name',
			$qs,
			'the filter targets the facet instead of the display property'
		);
	}

	public function testGetPageExcludeModelFiltersOnDeclaredFacetProperty(): void {
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), $this->facetSpec(), $captured );

		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[],
			[ 'Name' => [ 'values' => [ 'Origin Jumpworks' ], 'exclude' => true ] ],
			25
		);

		$this->assertInstanceOf( Conjunction::class, $captured->getDescription() );
		$qs = $captured->getDescription()->getQueryString();
		$this->assertStringContainsString(
			'Has manufacturer',
			$qs,
			'exclude-model NEQ conditions run against the facet property'
		);
		$this->assertStringNotContainsString( 'Has name', $qs );
	}

	public function testCountQueryFiltersOnDeclaredFacetProperty(): void {
		$captured = null;
		$allCaptured = null;
		$source = $this->newDataSource(
			$this->queryResult( [] ), $this->facetSpec(), $captured, 50, $allCaptured
		);

		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[],
			[ 'Name' => [ 'values' => [ 'Origin Jumpworks' ] ] ],
			25
		);

		$this->assertCount( 2, $allCaptured, 'getPage issues a data query then a count query' );
		$countQuery = $allCaptured[1];
		$this->assertSame( Query::MODE_COUNT, $countQuery->getQueryMode() );
		$qs = $countQuery->getDescription()->getQueryString();
		$this->assertStringContainsString(
			'Has manufacturer',
			$qs,
			'the filtered total is counted against the facet property, matching the rows'
		);
	}

	public function testGetPageSortStaysOnDisplayPropertyForFacetedColumn(): void {
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), $this->facetSpec(), $captured );

		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[ [ 'colId' => 'Name', 'sort' => 'asc' ] ],
			[],
			25
		);

		$this->assertSame(
			[ 'Has_name' => 'ASC', '' => 'ASC' ],
			$captured->getSortKeys(),
			'the facet redirects filtering only; sort keeps the display property'
		);
	}

	public function testGetColumnValuesProjectsFacetProperty(): void {
		$rows = [
			[ $this->resultArray(
				PrintRequest::PRINT_PROP,
				'Has manufacturer',
				[ $this->scalarDataValue( 'Origin Jumpworks' ) ]
			) ],
		];
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( $rows ), $this->facetSpec(), $captured );

		$result = $source->getColumnValues( self::PAGE_ID, 0, 'Name' );

		$labels = array_map(
			static fn ( $pr ): string => $pr->getLabel(),
			$captured->getExtraPrintouts()
		);
		$this->assertContains(
			'Has manufacturer',
			$labels,
			'the value list enumerates the facet property, matching what conditions run against'
		);
		$this->assertSame(
			[ [ 'key' => 'Origin Jumpworks', 'label' => 'Origin Jumpworks' ] ],
			$result['values']
		);
	}

	public function testGetColumnValuesUndeclaredColumnThrows(): void {
		$source = $this->newDataSource( $this->queryResult( [] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'undeclared column' );
		$source->getColumnValues( self::PAGE_ID, 0, 'Some arbitrary property' );
	}

	public function testGetPageUndeclaredFilterFieldThrows(): void {
		$source = $this->newDataSource( $this->queryResult( [] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'undeclared column' );
		$source->getPage(
			self::PAGE_ID, 0, 0, [], [ 'Nope' => [ 'values' => [ 'x' ] ] ], 25
		);
	}

	public function testGetPageUndeclaredSortFieldThrows(): void {
		$source = $this->newDataSource( $this->queryResult( [] ) );

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'undeclared column' );
		$source->getPage(
			self::PAGE_ID, 0, 0, [ [ 'colId' => 'Nope', 'sort' => 'asc' ] ], [], 25
		);
	}

	public function testLegacySpecWithoutFieldsMapDerivesDeclaredColumns(): void {
		// A spec stored before the fields map existed: declared columns must derive
		// from the canonical printout strings, so aliased columns still resolve.
		$spec = [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population=Pop' ],
			'mainlabel' => null,
		];
		$rows = [
			[ $this->resultArray(
				PrintRequest::PRINT_PROP,
				'Has population',
				[ $this->scalarDataValue( '5' ) ]
			) ],
		];
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( $rows ), $spec, $captured );

		$result = $source->getColumnValues( self::PAGE_ID, 0, 'Pop' );

		$labels = array_map(
			static fn ( $pr ): string => $pr->getLabel(),
			$captured->getExtraPrintouts()
		);
		$this->assertContains( 'Has population', $labels );
		$this->assertSame( [ [ 'key' => '5', 'label' => '5' ] ], $result['values'] );
	}

	// -------------------------------------------------------------------------
	// Quick search
	// -------------------------------------------------------------------------

	public function testGetPageAndsQuickSearchOntoQuery(): void {
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), null, $captured );

		$source->getPage( self::PAGE_ID, 0, 0, [], [], 50, 'berlin' );

		$this->assertInstanceOf( Conjunction::class, $captured->getDescription() );
		$this->assertStringContainsString(
			'berlin',
			$captured->getDescription()->getQueryString(),
			'the quick-search term is ANDed onto the data query'
		);
	}

	public function testCountQueryIncludesQuickSearch(): void {
		$captured = null;
		$allCaptured = null;
		$source = $this->newDataSource(
			$this->queryResult( [] ), null, $captured, 50, $allCaptured
		);

		$source->getPage( self::PAGE_ID, 0, 0, [], [], 50, 'berlin' );

		$this->assertCount( 2, $allCaptured, 'getPage issues a data query then a count query' );
		$this->assertStringContainsString(
			'berlin',
			$allCaptured[1]->getDescription()->getQueryString(),
			'the filtered total counts against the same quick-search condition as the rows'
		);
	}

	public function testGetPageQuickSearchAndsWithColumnFilter(): void {
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), null, $captured );

		$source->getPage(
			self::PAGE_ID,
			0,
			0,
			[],
			[ self::CAPITAL => [ 'values' => [ 'Berlin' ] ] ],
			50,
			'xyz'
		);

		$this->assertInstanceOf( Conjunction::class, $captured->getDescription() );
		$qs = $captured->getDescription()->getQueryString();
		$this->assertStringContainsString( 'xyz', $qs, 'quick-search term present' );
		$this->assertStringContainsString( 'Berlin', $qs, 'column filter value present' );
	}

	public function testGetPageEmptyQuickSearchAddsNoCondition(): void {
		$captured = null;
		$source = $this->newDataSource( $this->queryResult( [] ), null, $captured );

		$source->getPage( self::PAGE_ID, 0, 0, [], [], 50, '   ' );

		$this->assertNotInstanceOf(
			Conjunction::class,
			$captured->getDescription(),
			'a blank quick-search term leaves the base description unwrapped'
		);
	}

	// -------------------------------------------------------------------------
	// Cache policy
	// -------------------------------------------------------------------------

	public function testGetCachePolicyUsesConfiguredMaxAge(): void {
		$source = $this->newDataSource( $this->queryResult( [] ) );
		$policy = $source->getCachePolicy();
		$this->assertSame( 120, $policy->getMaxAge() );
		$this->assertFalse( $policy->isPublic() );
	}
}
