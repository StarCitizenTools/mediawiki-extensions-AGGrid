<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\GridOptionSanitizer;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\GridOptionSanitizer
 */
class GridOptionSanitizerTest extends MediaWikiUnitTestCase {

	public function testStripsGridLevelHtmlOptions(): void {
		$out = GridOptionSanitizer::strip( [
			'pagination' => true,
			'overlayNoRowsTemplate' => '<img src=x onerror=alert(1)>',
			'overlayLoadingTemplate' => '<b>loading</b>',
			'icons' => [ 'sortAscending' => '<img src=x onerror=alert(1)>' ],
		] );
		$this->assertSame( [ 'pagination' => true ], $out );
	}

	public function testStripsHtmlOptionsInsideColumnDefs(): void {
		$out = GridOptionSanitizer::strip( [
			'columnDefs' => [
				[
					'field' => 'a',
					'icons' => [ 'menu' => '<img src=x onerror=alert(1)>' ],
					'headerComponentParams' => [
						'template' => '<div onclick="alert(1)"></div>',
						'label' => 'A',
					],
				],
			],
		] );
		$this->assertSame( [
			'columnDefs' => [
				[
					'field' => 'a',
					'headerComponentParams' => [ 'label' => 'A' ],
				],
			],
		], $out );
	}

	public function testStripsInsideColumnGroupChildren(): void {
		$out = GridOptionSanitizer::strip( [
			'columnDefs' => [
				[
					'headerName' => 'Group',
					'children' => [
						[ 'field' => 'a', 'template' => '<img src=x onerror=alert(1)>' ],
					],
				],
			],
		] );
		$this->assertSame( [
			'columnDefs' => [
				[
					'headerName' => 'Group',
					'children' => [ [ 'field' => 'a' ] ],
				],
			],
		], $out );
	}

	public function testLeavesRowDataUntouched(): void {
		// A cell field may share a stripped name; rowData is data, not markup.
		$rowData = [
			[ 'template' => 'Standard', 'icons' => 'none', 'overlayNoRowsTemplate' => 'x' ],
		];
		$out = GridOptionSanitizer::strip( [ 'columnDefs' => [], 'rowData' => $rowData ] );
		$this->assertSame( $rowData, $out['rowData'] );
	}

	public function testLeavesSafeOptionsUntouched(): void {
		$options = [
			'theme' => 'legacy',
			'pagination' => true,
			'columnDefs' => [ [ 'field' => 'name', 'headerName' => 'Name', 'width' => 100 ] ],
		];
		$this->assertSame( $options, GridOptionSanitizer::strip( $options ) );
	}
}
