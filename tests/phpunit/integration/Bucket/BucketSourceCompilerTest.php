<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration\Bucket;

use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketColumnMapper;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSchemaReader;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSourceCompiler;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWikiIntegrationTestCase;

/**
 * Uses a mocked BucketSchemaReader (so no live Bucket DB is needed), but runs as an
 * integration test because constructing a LuaError builds a Message and so needs the
 * service container.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSourceCompiler
 */
class BucketSourceCompilerTest extends MediaWikiIntegrationTestCase {

	private BucketSourceCompiler $compiler;

	protected function setUp(): void {
		parent::setUp();

		$schemas = [
			'item' => [
				'page_name' => [ 'type' => 'PAGE', 'repeated' => false, 'indexed' => true ],
				'item_type' => [ 'type' => 'TEXT', 'repeated' => false, 'indexed' => true ],
				'value' => [ 'type' => 'INTEGER', 'repeated' => false, 'indexed' => true ],
				'members' => [ 'type' => 'BOOLEAN', 'repeated' => false, 'indexed' => true ],
				'equipable' => [ 'type' => 'BOOLEAN', 'repeated' => false, 'indexed' => true ],
				'tags' => [ 'type' => 'TEXT', 'repeated' => true, 'indexed' => true ],
				'skill_required' => [ 'type' => 'TEXT', 'repeated' => false, 'indexed' => true ],
			],
			'skill' => [
				'skill_name' => [ 'type' => 'TEXT', 'repeated' => false, 'indexed' => true ],
				'category' => [ 'type' => 'TEXT', 'repeated' => false, 'indexed' => true ],
			],
		];

		$reader = $this->createMock( BucketSchemaReader::class );
		$reader->method( 'getFields' )->willReturnCallback(
			static fn ( string $name ): array => $schemas[$name] ?? []
		);

		$this->compiler = new BucketSourceCompiler( $reader, new BucketColumnMapper() );
	}

	private function joinSpec(): array {
		return [ [ 'bucket' => 'skill', 'on' => [ 'item.skill_required', 'skill.skill_name' ] ] ];
	}

	// -------------------------------------------------------------------------
	// Happy path
	// -------------------------------------------------------------------------

	public function testCompilesColumnsAndSpec(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'page_name', [ 'field' => 'value', 'label' => 'Value' ] ],
		] );

		$this->assertSame( 'page_name', $columnDefs[0]['field'] );
		$this->assertSame( 'aggridLink', $columnDefs[0]['type'] );
		$this->assertSame( 'value', $columnDefs[1]['field'] );
		$this->assertSame( 'Value', $columnDefs[1]['headerName'] );
		$this->assertSame( 'numericColumn', $columnDefs[1]['type'] );
		$this->assertSame( 'agNumberColumnFilter', $columnDefs[1]['filter'] );

		$this->assertSame( 'item', $spec['bucket'] );
		$this->assertSame( [ 'page_name', 'value' ], $spec['selects'] );
		$this->assertSame(
			[ 'select' => 'page_name', 'type' => 'PAGE', 'repeated' => false ],
			$spec['fields']['page_name']
		);
		$this->assertSame(
			[ 'select' => 'value', 'type' => 'INTEGER', 'repeated' => false ],
			$spec['fields']['value']
		);
		$this->assertArrayNotHasKey( 'joins', $spec );
		$this->assertArrayNotHasKey( 'where', $spec );
		$this->assertArrayNotHasKey( 'orderBy', $spec );
	}

	public function testDefaultHeaderIsFieldName(): void {
		[ $columnDefs ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'item_type' ],
		] );
		$this->assertSame( 'item_type', $columnDefs[0]['headerName'] );
	}

	public function testRepeatedFieldColumn(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'tags' ],
		] );
		$this->assertSame( 'aggridSet', $columnDefs[0]['filter'] );
		$this->assertTrue( $spec['fields']['tags']['repeated'] );
	}

	// -------------------------------------------------------------------------
	// Joins / qualified fields
	// -------------------------------------------------------------------------

	public function testJoinedQualifiedFieldMapsToDotFreeColId(): void {
		[ $columnDefs, $spec ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'page_name', [ 'field' => 'skill.category', 'label' => 'Skill type' ] ],
			'join' => $this->joinSpec(),
		] );

		$this->assertSame( 'skill__category', $columnDefs[1]['field'] );
		$this->assertSame( 'Skill type', $columnDefs[1]['headerName'] );
		$this->assertSame( 'skill.category', $spec['fields']['skill__category']['select'] );
		$this->assertSame( [ 'page_name', 'skill.category' ], $spec['selects'] );
		$this->assertSame( $this->joinSpec(), $spec['joins'] );
	}

	public function testQualifiedFieldWithoutJoinThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage(
			'mw.ext.aggrid.render: field "skill.category" references bucket "skill" ' .
			'which is not the primary bucket or a joined bucket'
		);
		$this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ [ 'field' => 'skill.category' ] ],
		] );
	}

	// -------------------------------------------------------------------------
	// Base where
	// -------------------------------------------------------------------------

	public function testBaseWhereNormalised(): void {
		[ , $spec ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'where' => [ [ 'equipable', '=', true ], [ 'value', '>', 100 ] ],
		] );
		$this->assertSame(
			[ [ 'equipable', '=', true ], [ 'value', '>', 100 ] ],
			$spec['where']
		);
	}

	public function testBaseWhereInvalidOperatorThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: invalid where operator "LIKE"' );
		$this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'where' => [ [ 'value', 'LIKE', 'x' ] ],
		] );
	}

	public function testBaseWhereUnknownFieldThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: unknown field "nope"' );
		$this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'where' => [ [ 'nope', '=', 1 ] ],
		] );
	}

	// -------------------------------------------------------------------------
	// orderBy
	// -------------------------------------------------------------------------

	public function testOrderByNormalisesDirection(): void {
		[ , $spec ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'orderBy' => [ 'field' => 'value', 'direction' => 'desc' ],
		] );
		$this->assertSame( [ 'field' => 'value', 'direction' => 'DESC' ], $spec['orderBy'] );
	}

	public function testOrderByMustBeSelectedThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage(
			'mw.ext.aggrid.render: orderBy field "item_type" must be one of the selected fields'
		);
		$this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'orderBy' => [ 'field' => 'item_type', 'direction' => 'ASC' ],
		] );
	}

	public function testOrderByInvalidDirectionThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: orderBy direction must be ASC or DESC' );
		$this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'orderBy' => [ 'field' => 'value', 'direction' => 'sideways' ],
		] );
	}

	// -------------------------------------------------------------------------
	// Author overrides
	// -------------------------------------------------------------------------

	public function testAuthorOverrides(): void {
		[ $columnDefs ] = $this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ [
				'field' => 'item_type',
				'label' => 'Type',
				'type' => 'wikiBadge',
				'cellRendererParams' => [ 'map' => [ 'weapon' => 'red' ] ],
				'format' => [ 'style' => 'text' ],
				'filter' => false,
			] ],
		] );
		$col = $columnDefs[0];
		$this->assertSame( 'wikiBadge', $col['type'] );
		$this->assertSame( [ 'map' => [ 'weapon' => 'red' ] ], $col['cellRendererParams'] );
		$this->assertSame( [ 'style' => 'text' ], $col['format'] );
		$this->assertFalse( $col['filter'], 'author can disable the filter' );
	}

	// -------------------------------------------------------------------------
	// Validation
	// -------------------------------------------------------------------------

	public function testUnknownBucketThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: unknown bucket "nope"' );
		$this->compiler->compile( [ 'bucket' => 'nope', 'fields' => [ 'x' ] ] );
	}

	public function testEmptyBucketThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: source.bucket must be a non-empty string' );
		$this->compiler->compile( [ 'bucket' => '  ', 'fields' => [ 'value' ] ] );
	}

	public function testNoFieldsThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: source.fields must list at least one field' );
		$this->compiler->compile( [ 'bucket' => 'item', 'fields' => [] ] );
	}

	public function testUnknownFieldThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: unknown field "nope"' );
		$this->compiler->compile( [ 'bucket' => 'item', 'fields' => [ 'nope' ] ] );
	}

	public function testFieldWithoutNameThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: each field needs a name' );
		$this->compiler->compile( [ 'bucket' => 'item', 'fields' => [ [ 'label' => 'X' ] ] ] );
	}

	public function testDuplicateColumnThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage( 'mw.ext.aggrid.render: duplicate column "value"' );
		$this->compiler->compile( [ 'bucket' => 'item', 'fields' => [ 'value', 'value' ] ] );
	}

	public function testInvalidJoinThrows(): void {
		$this->expectException( LuaError::class );
		$this->expectExceptionMessage(
			'mw.ext.aggrid.render: each join needs a "bucket" and an "on" pair { field, field }'
		);
		$this->compiler->compile( [
			'bucket' => 'item',
			'fields' => [ 'value' ],
			'join' => [ [ 'bucket' => 'skill' ] ],
		] );
	}

	public function testUnconfiguredBucketDbBecomesLuaError(): void {
		// An unconfigured Bucket DB surfaces from getFields() as a ConfigException; the
		// compiler must turn it into a clear author LuaError rather than a 500.
		$reader = $this->createMock( BucketSchemaReader::class );
		$reader->method( 'getFields' )
			->willThrowException( new ConfigException( 'BucketDBuser is required' ) );
		$compiler = new BucketSourceCompiler( $reader, new BucketColumnMapper() );

		$this->expectException( LuaError::class );
		$this->expectExceptionMessage(
			'mw.ext.aggrid.render: the Bucket database is not configured ' .
			'($wgBucketDBuser / $wgBucketDBpassword)'
		);
		$compiler->compile( [ 'bucket' => 'item', 'fields' => [ 'value' ] ] );
	}
}
