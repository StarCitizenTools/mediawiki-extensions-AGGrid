<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketColumnMapper;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketColumnMapper
 */
class BucketColumnMapperTest extends MediaWikiUnitTestCase {

	private BucketColumnMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new BucketColumnMapper();
	}

	// -------------------------------------------------------------------------
	// mapColumn()
	// -------------------------------------------------------------------------

	public function testMapColumnSetsFieldAndHeader(): void {
		$col = $this->mapper->mapColumn( 'value', 'Value', 'INTEGER', false );
		$this->assertSame( 'value', $col['field'] );
		$this->assertSame( 'Value', $col['headerName'] );
	}

	public function testPageType(): void {
		$col = $this->mapper->mapColumn( 'page_name', 'Page', 'PAGE', false );
		$this->assertSame( 'aggridLink', $col['type'] );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testRepeatedPageType(): void {
		$col = $this->mapper->mapColumn( 'pages', 'Pages', 'PAGE', true );
		$this->assertSame( 'aggridLinkList', $col['type'] );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testTextType(): void {
		$col = $this->mapper->mapColumn( 'item_type', 'Type', 'TEXT', false );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testRepeatedTextType(): void {
		// Repeated scalars have no special renderer (comma-joined display) but still
		// get a set filter so the filter routes through Bucket's repeated subquery path.
		$col = $this->mapper->mapColumn( 'tags', 'Tags', 'TEXT', true );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testIntegerType(): void {
		$col = $this->mapper->mapColumn( 'value', 'Value', 'INTEGER', false );
		$this->assertSame( 'numericColumn', $col['type'] );
		$this->assertSame( 'agNumberColumnFilter', $col['filter'] );
	}

	public function testDoubleType(): void {
		$col = $this->mapper->mapColumn( 'weight', 'Weight', 'DOUBLE', false );
		$this->assertSame( 'numericColumn', $col['type'] );
		$this->assertSame( 'agNumberColumnFilter', $col['filter'] );
	}

	public function testRepeatedNumericFallsBackToSetFilter(): void {
		// A repeated number cannot use a range filter (range conditions do not route
		// through Bucket's repeated subquery path), so it gets a set filter.
		$col = $this->mapper->mapColumn( 'values', 'Values', 'INTEGER', true );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testBooleanType(): void {
		$col = $this->mapper->mapColumn( 'members', 'Members', 'BOOLEAN', false );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testUnknownType(): void {
		$col = $this->mapper->mapColumn( 'x', 'X', 'MYSTERY', false );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertFalse( $col['filter'] );
	}

	// -------------------------------------------------------------------------
	// filterFamily()
	// -------------------------------------------------------------------------

	public function testFilterFamilySetForPage(): void {
		$this->assertSame( 'set', $this->mapper->filterFamily( 'PAGE', false ) );
	}

	public function testFilterFamilySetForText(): void {
		$this->assertSame( 'set', $this->mapper->filterFamily( 'TEXT', false ) );
	}

	public function testFilterFamilySetForBoolean(): void {
		$this->assertSame( 'set', $this->mapper->filterFamily( 'BOOLEAN', false ) );
	}

	public function testFilterFamilyNumberForInteger(): void {
		$this->assertSame( 'number', $this->mapper->filterFamily( 'INTEGER', false ) );
	}

	public function testFilterFamilyNumberForDouble(): void {
		$this->assertSame( 'number', $this->mapper->filterFamily( 'DOUBLE', false ) );
	}

	public function testFilterFamilySetForRepeatedNumber(): void {
		$this->assertSame( 'set', $this->mapper->filterFamily( 'INTEGER', true ) );
	}

	public function testFilterFamilyNoneForUnknown(): void {
		$this->assertSame( 'none', $this->mapper->filterFamily( 'MYSTERY', false ) );
	}

	// -------------------------------------------------------------------------
	// filterComponent()
	// -------------------------------------------------------------------------

	public function testFilterComponent(): void {
		$this->assertSame( 'aggridSet', $this->mapper->filterComponent( 'PAGE', false ) );
		$this->assertSame( 'aggridSet', $this->mapper->filterComponent( 'TEXT', false ) );
		$this->assertSame( 'agNumberColumnFilter', $this->mapper->filterComponent( 'INTEGER', false ) );
		$this->assertSame( 'aggridSet', $this->mapper->filterComponent( 'INTEGER', true ) );
		$this->assertFalse( $this->mapper->filterComponent( 'MYSTERY', false ) );
	}
}
