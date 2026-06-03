<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration;

use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Parser\ParserOutput;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Service\GridRenderer
 */
class GridRendererTest extends MediaWikiIntegrationTestCase {

	private function getRenderer(): GridRenderer {
		return $this->getServiceContainer()->getService( 'AGGrid.GridRenderer' );
	}

	private function options(): array {
		return [
			'columnDefs' => [ 1 => [ 'field' => 'name' ], 2 => [ 'field' => 'price' ] ],
			'rowData' => [ 1 => [ 'name' => 'Aurora', 'price' => 25 ] ],
		];
	}

	private function decodeOptions( string $html ): array {
		$this->assertSame( 1, preg_match( '/data-mw-aggrid-options="([^"]*)"/', $html, $m ) );
		return json_decode( htmlspecialchars_decode( $m[1], ENT_QUOTES ), true );
	}

	public function testCanonicalParseSplitsRowDataIntoExtensionData(): void {
		$po = new ParserOutput();
		$html = $this->getRenderer()->render( $this->options(), $po, 7, 42, false );

		$this->assertStringContainsString( 'data-mw-aggrid-pageid="7"', $html );
		$this->assertStringContainsString( 'data-mw-aggrid-rev="42"', $html );
		$this->assertStringContainsString( 'data-mw-aggrid-index="0"', $html );
		$this->assertStringNotContainsString( 'Aurora', $html );

		$decoded = $this->decodeOptions( $html );
		$this->assertArrayHasKey( 0, $decoded['columnDefs'], 'columnDefs normalized to a list' );
		$this->assertArrayNotHasKey( 'rowData', $decoded, 'rowData removed from the attribute' );

		$grid = $po->getExtensionData( GridRenderer::EXT_DATA_KEY . '0' );
		$this->assertIsArray( $grid );
		$this->assertArrayHasKey( 0, $grid['rows'], 'rowData normalized to a list' );
		$this->assertSame( 'Aurora', $grid['rows'][0]['name'] );
		$this->assertSame( 40, strlen( $grid['hash'] ), 'sha1 hex hash' );
	}

	public function testSecondGridGetsNextIndex(): void {
		$po = new ParserOutput();
		$this->getRenderer()->render( $this->options(), $po, 7, 42, false );
		$html = $this->getRenderer()->render( $this->options(), $po, 7, 42, false );
		$this->assertStringContainsString( 'data-mw-aggrid-index="1"', $html );
		$this->assertNotNull( $po->getExtensionData( GridRenderer::EXT_DATA_KEY . '0' ) );
		$this->assertNotNull( $po->getExtensionData( GridRenderer::EXT_DATA_KEY . '1' ) );
	}

	public function testPreviewEmbedsRowDataInline(): void {
		$po = new ParserOutput();
		$html = $this->getRenderer()->render( $this->options(), $po, 7, 42, true );

		$this->assertStringNotContainsString( 'data-mw-aggrid-pageid', $html );
		$decoded = $this->decodeOptions( $html );
		$this->assertSame( 'Aurora', $decoded['rowData'][0]['name'], 'rows embedded for preview' );
		$this->assertNull( $po->getExtensionData( GridRenderer::EXT_DATA_KEY . '0' ) );
	}

	public function testIncludesLoadingSkeleton(): void {
		$po = new ParserOutput();
		$html = $this->getRenderer()->render(
			[ 'columnDefs' => [ [ 'field' => 'name' ] ], 'rowData' => [] ],
			$po, 7, 42, false
		);
		$this->assertStringContainsString( 'aria-busy="true"', $html );
		$this->assertStringContainsString( 'ext-aggrid__skeleton', $html );
		$this->assertStringContainsString( 'aria-hidden="true"', $html );
	}
}
