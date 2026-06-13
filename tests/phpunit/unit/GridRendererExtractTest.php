<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Parser\ParserOutput;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Service\GridRenderer
 */
class GridRendererExtractTest extends MediaWikiUnitTestCase {

	public function testExtractInlineGridsProbesContiguousKeysUntilGap(): void {
		$po = new ParserOutput();
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '0', [ 'rows' => [ [ 'a' => 1 ] ], 'hash' => 'h0' ] );
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '1', [ 'rows' => [ [ 'b' => 2 ] ], 'hash' => 'h1' ] );
		// A gap at index 2 stops the probe; index 3 must not be reached.
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '3', [ 'rows' => [], 'hash' => 'h3' ] );

		$this->assertSame( [
			0 => [ 'rows' => [ [ 'a' => 1 ] ], 'hash' => 'h0' ],
			1 => [ 'rows' => [ [ 'b' => 2 ] ], 'hash' => 'h1' ],
		], GridRenderer::extractInlineGrids( $po ) );
	}

	public function testExtractInlineGridsEmptyWhenNoneQueued(): void {
		$this->assertSame( [], GridRenderer::extractInlineGrids( new ParserOutput() ) );
	}

	public function testExtractSourceGridsProbesIndependentKeyspace(): void {
		$po = new ParserOutput();
		$po->setExtensionData(
			GridRenderer::SOURCE_EXT_DATA_KEY . '0',
			[ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ], 'hash' => 's0' ]
		);
		// An inline grid on the same page must not bleed into the source extraction.
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '0', [ 'rows' => [], 'hash' => 'h0' ] );

		$this->assertSame( [
			0 => [ 'source' => 'smw', 'spec' => [ 'query' => '[[Category:Foo]]' ], 'hash' => 's0' ],
		], GridRenderer::extractSourceGrids( $po ) );
	}

	public function testExtractIncludesZeroRowGrid(): void {
		$po = new ParserOutput();
		$po->setExtensionData( GridRenderer::EXT_DATA_KEY . '0', [ 'rows' => [], 'hash' => 'h0' ] );

		$grids = GridRenderer::extractInlineGrids( $po );
		$this->assertArrayHasKey( 0, $grids );
		$this->assertSame( [], $grids[0]['rows'] );
	}
}
