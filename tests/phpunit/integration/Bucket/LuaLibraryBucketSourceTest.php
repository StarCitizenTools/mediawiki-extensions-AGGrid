<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketColumnMapper;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSchemaReader;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSourceCompiler;
use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaEngine;
use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IMaintainableDatabase;

/**
 * Drives the Lua render path for `source.type = 'bucket'`. The BucketSourceCompiler
 * service is overridden with one backed by a stubbed schema reader, so the parse path
 * never touches the live Bucket DB (which is unreachable under the cloned test DB).
 *
 * @covers \MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary
 * @group Database
 */
class LuaLibraryBucketSourceTest extends MediaWikiIntegrationTestCase {

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'Bucket' ) ) {
			$this->markTestSkipped( 'The Bucket extension is required for the bucket source path tests.' );
		}

		// Saving the fixture page (to obtain a real pageId/revId) otherwise fires Bucket's
		// own LinksUpdateComplete handler, which reads bucket_pages through Bucket's
		// restricted DB user — unreachable against the cloned, prefixed test tables. We
		// only need the spec queued into ParserOutput by render(), not the post-save flush.
		$this->clearHook( 'LinksUpdateComplete' );

		$schemas = [
			'item' => [
				'page_name' => [ 'type' => 'PAGE', 'repeated' => false, 'indexed' => true ],
				'value' => [ 'type' => 'INTEGER', 'repeated' => false, 'indexed' => true ],
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
		$this->setService(
			'AGGrid.BucketSourceCompiler',
			new BucketSourceCompiler( $reader, new BucketColumnMapper() )
		);
	}

	protected function getSchemaOverrides( IMaintainableDatabase $db ) {
		// Saving a page fires LinksUpdateComplete, which flushes both the inline
		// (aggrid_data) and backend (aggrid_source) stores, so both tables must exist.
		// This test lives one directory deeper than the other integration tests
		// (integration/Bucket/), so the extension root is four levels up.
		$dir = dirname( __DIR__, 4 ) . '/sql/' . $db->getType();
		return [
			'create' => [ 'aggrid_data', 'aggrid_source' ],
			'scripts' => [ "$dir/tables-generated.sql", "$dir/patch-aggrid_source.sql" ],
		];
	}

	public function testRendersBucketSourceQueuesSpecAndColumns(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'bucket',
				'bucket' => 'item',
				'fields' => [ 'page_name', [ 'field' => 'value', 'label' => 'Value' ] ],
			],
		] );

		$this->assertCount( 1, $result );
		$this->assertIsString( $result[0] );

		$parserOutput = $parser->getOutput();
		$entry = $parserOutput->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' );
		$this->assertIsArray( $entry, 'a source spec is queued under index 0' );
		$this->assertSame( 'bucket', $entry['source'] );

		$spec = $entry['spec'];
		$this->assertSame( 'item', $spec['bucket'] );
		$this->assertSame( [ 'page_name', 'value' ], $spec['selects'] );
		$this->assertSame(
			[ 'select' => 'value', 'type' => 'INTEGER', 'repeated' => false ],
			$spec['fields']['value']
		);

		$viewConfig = $this->viewConfigFromPlaceholder( $parser, $result[0] );
		$this->assertArrayNotHasKey( 'rowData', $viewConfig );
		$columnDefs = $viewConfig['columnDefs'];
		$this->assertSame( 'page_name', $columnDefs[0]['field'] );
		$this->assertSame( 'aggridLink', $columnDefs[0]['type'] );
		$this->assertSame( 'value', $columnDefs[1]['field'] );
		$this->assertSame( 'Value', $columnDefs[1]['headerName'] );
		$this->assertSame( 'numericColumn', $columnDefs[1]['type'] );

		// A successful source render tags the page with the usage tracking category.
		$catName = wfMessage( 'aggrid-tracking-category' )->inContentLanguage()->text();
		$this->assertContains(
			Title::makeTitleSafe( NS_CATEGORY, $catName )->getDBkey(),
			$parserOutput->getCategoryNames()
		);
	}

	public function testRendersJoinedBucketSource(): void {
		$parser = $this->newStartedParser();
		$result = $this->newLibrary( $parser )->render( [
			'source' => [
				'type' => 'bucket',
				'bucket' => 'item',
				'fields' => [ 'page_name', [ 'field' => 'skill.category', 'label' => 'Skill type' ] ],
				'join' => [ [ 'bucket' => 'skill', 'on' => [ 'item.skill_required', 'skill.skill_name' ] ] ],
			],
		] );
		$this->assertCount( 1, $result );

		$spec = $parser->getOutput()
			->getExtensionData( GridRenderer::SOURCE_EXT_DATA_KEY . '0' )['spec'];
		$this->assertSame( 'skill.category', $spec['fields']['skill__category']['select'] );
		$this->assertSame(
			[ [ 'bucket' => 'skill', 'on' => [ 'item.skill_required', 'skill.skill_name' ] ] ],
			$spec['joins']
		);

		$columnDefs = $this->viewConfigFromPlaceholder( $parser, $result[0] )['columnDefs'];
		$this->assertSame( 'skill__category', $columnDefs[1]['field'], 'dotted field becomes a dot-free colId' );
	}

	public function testUnknownSourceTypeThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'type' => 'cargo', 'bucket' => 'item', 'fields' => [ 'value' ] ],
		] );
	}

	public function testUnknownBucketThrows(): void {
		$this->expectException( LuaError::class );
		$this->newLibrary( $this->newStartedParser() )->render( [
			'source' => [ 'type' => 'bucket', 'bucket' => 'nonexistent', 'fields' => [ 'value' ] ],
		] );
	}

	/**
	 * Unstrip the placeholder HTML behind the render() strip marker and decode the
	 * gridOptions JSON the template emits into data-mw-aggrid-options.
	 */
	private function viewConfigFromPlaceholder( Parser $parser, string $stripped ): array {
		$html = $parser->getStripState()->unstripBoth( $stripped );
		$pattern = '/data-mw-aggrid-options="(.+?)" data-mw-aggrid-source="bucket"/s';
		$this->assertMatchesRegularExpression( $pattern, $html );
		preg_match( $pattern, $html, $m );
		$decoded = json_decode( htmlspecialchars_decode( $m[1], ENT_QUOTES ), true );
		$this->assertIsArray( $decoded, 'placeholder carries decodable gridOptions JSON' );
		return $decoded;
	}

	/**
	 * A parser started against a real saved page so the source path resolves a
	 * non-null pageId + revId (and thus queues the spec rather than embedding it).
	 */
	private function newStartedParser(): Parser {
		$status = $this->insertPage( 'AGGridBucketSourceTest_' . wfRandomString( 8 ) );
		$parser = $this->getServiceContainer()->getParserFactory()->create();
		$parser->startExternalParse(
			$status['title'],
			ParserOptions::newFromAnon(),
			Parser::OT_HTML,
			true
		);
		return $parser;
	}

	private function newLibrary( Parser $parser ): LuaLibrary {
		$engine = $this->createMock( LuaEngine::class );
		$engine->method( 'getParser' )->willReturn( $parser );
		return new LuaLibrary( $engine );
	}
}
