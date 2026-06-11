<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration\Smw;

use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwSourceCompiler;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;

/**
 * Focused coverage of the SMW source compiler extracted from LuaLibrary. The full Lua
 * render path is exercised by LuaLibrarySourceTest. Skipped without SMW (resolving SMW
 * property datatypes needs the extension); runs as an integration test because LuaError
 * construction builds a Message.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Smw\SmwSourceCompiler
 * @group Database
 */
class SmwSourceCompilerTest extends MediaWikiIntegrationTestCase {

	private SmwSourceCompiler $compiler;

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
			$this->markTestSkipped( 'Semantic MediaWiki is required for the SMW source compiler.' );
		}
		$this->compiler = new SmwSourceCompiler();
	}

	public function testCompilesColumnsAndSpec(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population', 'Has mayor' ],
		] );

		// Subject column is prepended.
		$this->assertSame( '_subject', $columnDefs[0]['field'] );
		$this->assertSame( 'Page', $columnDefs[0]['headerName'] );
		$this->assertSame( 'aggridLink', $columnDefs[0]['type'] );
		$this->assertFalse( $columnDefs[0]['filter'] );
		// Printout columns: field == label.
		$this->assertSame( 'Has population', $columnDefs[1]['field'] );
		$this->assertSame( 'Has mayor', $columnDefs[2]['field'] );

		$this->assertSame( '[[Category:City]]', $spec['query'] );
		$this->assertSame( [ 'Has population', 'Has mayor' ], $spec['printouts'] );
		$this->assertNull( $spec['mainlabel'] );
		$this->assertArrayNotHasKey( 'facets', $spec );
	}

	public function testQueryFragmentListJoinsWithSpaces(): void {
		[ , $spec ] = $this->compiler->compile( [
			'query' => [ '[[Category:City]]', '', '[[Population::>1000]]' ],
			'printouts' => [ 'Has population' ],
		] );
		$this->assertSame( '[[Category:City]] [[Population::>1000]]', $spec['query'] );
	}

	public function testPrintoutWithExplicitLabel(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population=Pop' ],
		] );
		$this->assertSame( 'Pop', $columnDefs[1]['field'] );
		$this->assertSame( [ 'Has population=Pop' ], $spec['printouts'] );
		$this->assertSame( [ 'Pop' => 'Has population' ], $spec['fields'] );
	}

	public function testMainlabelDashSuppressesSubjectColumn(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'query' => '[[Category:City]]',
			'printouts' => [ 'Has population' ],
			'mainlabel' => '-',
		] );
		$this->assertSame( 'Has population', $columnDefs[0]['field'] );
		$this->assertSame( '-', $spec['mainlabel'] );
		foreach ( $columnDefs as $col ) {
			$this->assertNotSame( '_subject', $col['field'] );
		}
	}

	public function testPresentationKeysRideOnColDefNotSpec(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'query' => '[[Category:City]]',
			'printouts' => [ [
				'prop' => 'Has population',
				'label' => 'State',
				'type' => 'wikiStatusBadge',
				'cellRendererParams' => [ 'variantMap' => [ 'Flyable' => 'success' ] ],
				'format' => [ 'style' => 'number' ],
			] ],
		] );
		$this->assertSame( 'wikiStatusBadge', $columnDefs[1]['type'] );
		$this->assertSame( [ 'variantMap' => [ 'Flyable' => 'success' ] ], $columnDefs[1]['cellRendererParams'] );
		$this->assertSame( [ 'style' => 'number' ], $columnDefs[1]['format'] );
		$this->assertSame( [ 'Has population=State' ], $spec['printouts'] );
		$this->assertArrayNotHasKey( 'type', $spec );
		$this->assertArrayNotHasKey( 'cellRendererParams', $spec );
		$this->assertArrayNotHasKey( 'format', $spec );
	}

	public function testFilterPropStoresFacet(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'query' => '[[Category:Ship]]',
			'printouts' => [ [ 'prop' => 'Has name', 'label' => 'Name', 'filterProp' => 'Has manufacturer' ] ],
		] );
		$this->assertSame( [ 'Name' => 'Has manufacturer' ], $spec['facets'] );
		$this->assertSame( 'aggridSet', $columnDefs[1]['filter'] );
		$this->assertArrayNotHasKey( 'filterProp', $columnDefs[1] );
	}

	public function testEmptyQueryThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage(
			'mw.ext.aggrid.render: source.query must be a non-empty string or list of fragments'
		);
		$this->compiler->compile( [ 'query' => '   ', 'printouts' => [ 'A' ] ] );
	}

	public function testNoPrintoutsThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage(
			'mw.ext.aggrid.render: source.printouts must list at least one property'
		);
		$this->compiler->compile( [ 'query' => '[[Category:City]]', 'printouts' => [] ] );
	}

	public function testPrintoutWithoutPropertyNameThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: each printout needs a property name' );
		$this->compiler->compile( [ 'query' => '[[Category:City]]', 'printouts' => [ [ 'label' => 'Pop' ] ] ] );
	}

	public function testInvalidPropertyThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: invalid property "_"' );
		$this->compiler->compile( [ 'query' => '[[Category:City]]', 'printouts' => [ '_' ] ] );
	}
}
