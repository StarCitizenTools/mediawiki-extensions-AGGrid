<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Service\GridRenderer
 */
class GridRendererTest extends MediaWikiIntegrationTestCase {

	private function getRenderer(): \MediaWiki\Extension\AGGrid\Service\GridRenderer {
		return $this->getServiceContainer()->getService( 'AGGrid.GridRenderer' );
	}

	public function testRenderCarriesConfigInDataAttribute(): void {
		$html = $this->getRenderer()->render( [
			'columnDefs' => [ [ 'field' => 'name' ] ],
			'rowData' => [ [ 'name' => 'Aurora' ] ],
		] );

		$this->assertStringContainsString( 'class="ext-aggrid"', $html );
		$this->assertStringContainsString( 'data-mw-aggrid-options=', $html );
		$this->assertStringContainsString( 'columnDefs', $html );
		$this->assertStringContainsString( 'Aurora', $html );
		// Config travels in an attribute, not a script element.
		$this->assertStringNotContainsString( '<script', $html );
	}

	/**
	 * Lua tables reach PHP as 1-indexed arrays. They must serialize as JSON
	 * arrays (which AG Grid requires for columnDefs/rowData), not JSON objects.
	 */
	public function testRenderEncodesLuaSequencesAsJsonArrays(): void {
		$html = $this->getRenderer()->render( [
			'columnDefs' => [ 1 => [ 'field' => 'name' ], 2 => [ 'field' => 'price' ] ],
			'rowData' => [ 1 => [ 'name' => 'Aurora', 'price' => 25 ] ],
		] );

		// Decode the attribute payload and assert array-ness, not string matching.
		$this->assertSame( 1, preg_match( '/data-mw-aggrid-options="([^"]*)"/', $html, $m ) );
		$decoded = json_decode( htmlspecialchars_decode( $m[1], ENT_QUOTES ), true );

		$this->assertArrayHasKey( 0, $decoded['columnDefs'], 'columnDefs must be a 0-indexed list' );
		$this->assertSame( [ 'field' => 'name' ], $decoded['columnDefs'][0] );
		$this->assertSame( [ 'field' => 'price' ], $decoded['columnDefs'][1] );
		$this->assertArrayHasKey( 0, $decoded['rowData'], 'rowData must be a 0-indexed list' );
		$this->assertSame( 'Aurora', $decoded['rowData'][0]['name'] );
	}

	public function testRenderIncludesLoadingSkeleton(): void {
		$html = $this->getRenderer()->render( [
			'columnDefs' => [ [ 'field' => 'name' ] ],
			'rowData' => [],
		] );

		$this->assertStringContainsString( 'aria-busy="true"', $html );
		$this->assertStringContainsString( 'ext-aggrid__skeleton', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}
}
