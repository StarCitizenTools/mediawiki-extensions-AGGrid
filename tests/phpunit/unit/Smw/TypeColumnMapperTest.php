<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit\Smw;

use MediaWiki\Extension\AGGrid\DataSource\Smw\TypeColumnMapper;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Smw\TypeColumnMapper
 */
class TypeColumnMapperTest extends MediaWikiUnitTestCase {

	private TypeColumnMapper $mapper;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = new TypeColumnMapper();
	}

	// -------------------------------------------------------------------------
	// mapColumn() — one assertion per mapping-table row
	// -------------------------------------------------------------------------

	public function testMapColumnSetsFieldAndHeader(): void {
		$col = $this->mapper->mapColumn( 'myField', 'My Header', '_wpg' );
		$this->assertSame( 'myField', $col['field'] );
		$this->assertSame( 'My Header', $col['headerName'] );
	}

	public function testWpgPageType(): void {
		$col = $this->mapper->mapColumn( 'page', 'Page', '_wpg' );
		$this->assertSame( 'aggridLink', $col['type'] );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testTxtTextType(): void {
		$col = $this->mapper->mapColumn( 'text', 'Text', '_txt' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'agTextColumnFilter', $col['filter'] );
	}

	public function testCodCodeType(): void {
		$col = $this->mapper->mapColumn( 'code', 'Code', '_cod' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'agTextColumnFilter', $col['filter'] );
	}

	public function testKeywKeywordType(): void {
		$col = $this->mapper->mapColumn( 'kw', 'Keyword', '_keyw' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'agTextColumnFilter', $col['filter'] );
	}

	public function testNumNumberType(): void {
		$col = $this->mapper->mapColumn( 'num', 'Number', '_num' );
		$this->assertSame( 'numericColumn', $col['type'] );
		$this->assertSame( 'agNumberColumnFilter', $col['filter'] );
	}

	public function testQtyQuantityType(): void {
		$col = $this->mapper->mapColumn( 'qty', 'Quantity', '_qty' );
		$this->assertSame( 'numericColumn', $col['type'] );
		$this->assertSame( 'agNumberColumnFilter', $col['filter'] );
	}

	public function testTemTemperatureType(): void {
		$col = $this->mapper->mapColumn( 'temp', 'Temperature', '_tem' );
		$this->assertSame( 'numericColumn', $col['type'] );
		$this->assertSame( 'agNumberColumnFilter', $col['filter'] );
	}

	public function testDatDateType(): void {
		$col = $this->mapper->mapColumn( 'date', 'Date', '_dat' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'agDateColumnFilter', $col['filter'] );
	}

	public function testBooBooleanType(): void {
		$col = $this->mapper->mapColumn( 'bool', 'Boolean', '_boo' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertSame( 'aggridSet', $col['filter'] );
	}

	public function testUriUrlType(): void {
		$col = $this->mapper->mapColumn( 'url', 'URL', '_uri' );
		$this->assertSame( 'aggridLink', $col['type'] );
		$this->assertSame( 'agTextColumnFilter', $col['filter'] );
	}

	public function testEmaEmailType(): void {
		$col = $this->mapper->mapColumn( 'email', 'Email', '_ema' );
		$this->assertSame( 'aggridLink', $col['type'] );
		$this->assertSame( 'agTextColumnFilter', $col['filter'] );
	}

	public function testTelTelephoneType(): void {
		$col = $this->mapper->mapColumn( 'tel', 'Telephone', '_tel' );
		$this->assertSame( 'aggridLink', $col['type'] );
		$this->assertSame( 'agTextColumnFilter', $col['filter'] );
	}

	public function testRecRecordType(): void {
		$col = $this->mapper->mapColumn( 'rec', 'Record', '_rec' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertFalse( $col['filter'] );
	}

	public function testMltRecContainingRecType(): void {
		$col = $this->mapper->mapColumn( 'mlt', 'Monolingual Text', '_mlt_rec' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertFalse( $col['filter'] );
	}

	public function testRefRecContainingRecType(): void {
		$col = $this->mapper->mapColumn( 'ref', 'Reference', '_ref_rec' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertFalse( $col['filter'] );
	}

	public function testGeoGeoType(): void {
		$col = $this->mapper->mapColumn( 'geo', 'Geo', '_geo' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertFalse( $col['filter'] );
	}

	public function testUnknownTypeId(): void {
		$col = $this->mapper->mapColumn( 'x', 'X', '_unknown_type' );
		$this->assertArrayNotHasKey( 'type', $col );
		$this->assertFalse( $col['filter'] );
	}

	// -------------------------------------------------------------------------
	// filterFamily() — one representative per family
	// -------------------------------------------------------------------------

	public function testFilterFamilySet(): void {
		$this->assertSame( 'set', $this->mapper->filterFamily( '_wpg' ) );
	}

	public function testFilterFamilyBoolean(): void {
		$this->assertSame( 'boolean', $this->mapper->filterFamily( '_boo' ) );
	}

	public function testFilterFamilyText(): void {
		$this->assertSame( 'text', $this->mapper->filterFamily( '_txt' ) );
	}

	public function testFilterFamilyNumber(): void {
		$this->assertSame( 'number', $this->mapper->filterFamily( '_num' ) );
	}

	public function testFilterFamilyDate(): void {
		$this->assertSame( 'date', $this->mapper->filterFamily( '_dat' ) );
	}

	public function testFilterFamilyNoneForRec(): void {
		$this->assertSame( 'none', $this->mapper->filterFamily( '_rec' ) );
	}

	public function testFilterFamilyNoneForMltRec(): void {
		$this->assertSame( 'none', $this->mapper->filterFamily( '_mlt_rec' ) );
	}

	public function testFilterFamilyNoneForGeo(): void {
		$this->assertSame( 'none', $this->mapper->filterFamily( '_geo' ) );
	}

	public function testFilterFamilyNoneForUnknown(): void {
		$this->assertSame( 'none', $this->mapper->filterFamily( '_unknown_type' ) );
	}

	// -------------------------------------------------------------------------
	// filterComponent() — component name per datatype, false for unfilterable
	// -------------------------------------------------------------------------

	public function testFilterComponent(): void {
		$this->assertSame( 'aggridSet', $this->mapper->filterComponent( '_wpg' ) );
		$this->assertSame( 'agNumberColumnFilter', $this->mapper->filterComponent( '_num' ) );
		$this->assertSame( 'agDateColumnFilter', $this->mapper->filterComponent( '_dat' ) );
		$this->assertFalse( $this->mapper->filterComponent( '_geo' ), 'geo has no filter' );
		$this->assertFalse( $this->mapper->filterComponent( '_rec' ), 'records have no filter' );
		$this->assertFalse( $this->mapper->filterComponent( '_unknown' ), 'unknown ids fall back to none' );
	}

	// -------------------------------------------------------------------------
	// searchKind() — how a column participates in quick search, null = not searched
	// -------------------------------------------------------------------------

	/**
	 * @dataProvider provideSearchKind
	 */
	public function testSearchKind( string $typeId, ?string $expected ): void {
		$this->assertSame( $expected, $this->mapper->searchKind( $typeId ) );
	}

	public static function provideSearchKind(): array {
		return [
			// Text-like: substring LIKE on the blob store.
			'text' => [ '_txt', 'like-text' ],
			'code' => [ '_cod', 'like-text' ],
			'keyword' => [ '_keyw', 'like-text' ],
			// Page: substring LIKE on the page name.
			'page' => [ '_wpg', 'like-page' ],
			// Date: precision range via the ~ comparator.
			'date' => [ '_dat', 'like-date' ],
			// Number / temperature: exact match (both parse a bare number).
			'number' => [ '_num', 'eq-number' ],
			'temperature' => [ '_tem', 'eq-number' ],
			// Not searched: SMW's query builders return a match-everything
			// ThingDescription for a ~*substring* (email/telephone) or a bare number
			// (quantity needs an explicit unit), and a URI is scheme-anchored
			// (~http://*foo*), so substring search on these is unreliable. Excluding
			// them keeps the box honest rather than silently no-op.
			'uri' => [ '_uri', null ],
			'email' => [ '_ema', null ],
			'telephone' => [ '_tel', null ],
			'quantity' => [ '_qty', null ],
			'boolean' => [ '_boo', null ],
			'geo' => [ '_geo', null ],
			'record' => [ '_rec', null ],
			'compound record' => [ '_mlt_rec', null ],
			'unknown' => [ '_unknown_type', null ],
		];
	}
}
