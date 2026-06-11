<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\DataSource\ColumnDescriptor;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\ColumnDescriptor
 */
class ColumnDescriptorTest extends MediaWikiUnitTestCase {

	public function testToColumnDefIncludesTypeWhenSet(): void {
		$desc = new ColumnDescriptor( 'numericColumn', 'agNumberColumnFilter', 'number' );
		$this->assertSame( [
			'field'      => 'value',
			'headerName' => 'Value',
			'filter'     => 'agNumberColumnFilter',
			'type'       => 'numericColumn',
		], $desc->toColumnDef( 'value', 'Value' ) );
	}

	public function testToColumnDefOmitsTypeWhenNull(): void {
		$desc = new ColumnDescriptor( null, 'agTextColumnFilter', 'text' );
		$colDef = $desc->toColumnDef( 'name', 'Name' );
		$this->assertArrayNotHasKey( 'type', $colDef );
		$this->assertSame( [
			'field'      => 'name',
			'headerName' => 'Name',
			'filter'     => 'agTextColumnFilter',
		], $colDef );
	}

	public function testToColumnDefWithFilterDisabled(): void {
		$desc = new ColumnDescriptor( 'aggridLink', false, 'none' );
		$colDef = $desc->toColumnDef( 'page', 'Page' );
		$this->assertFalse( $colDef['filter'] );
		$this->assertSame( 'aggridLink', $colDef['type'] );
	}

	public function testFallback(): void {
		$desc = ColumnDescriptor::fallback();
		$this->assertNull( $desc->type );
		$this->assertFalse( $desc->filter );
		$this->assertSame( 'none', $desc->family );
		$this->assertArrayNotHasKey( 'type', $desc->toColumnDef( 'x', 'X' ) );
	}

	public function testReadonlyAccessorsExposeAllThreeFields(): void {
		$desc = new ColumnDescriptor( 'aggridLinkList', 'aggridSet', 'set' );
		$this->assertSame( 'aggridLinkList', $desc->type );
		$this->assertSame( 'aggridSet', $desc->filter );
		$this->assertSame( 'set', $desc->family );
	}
}
