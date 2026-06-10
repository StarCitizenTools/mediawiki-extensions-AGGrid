<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSchemaReader;
use MediaWikiUnitTestCase;

/**
 * Covers the pure schema-JSON parsing. The DB read in getFields() is exercised
 * end-to-end against the seeded buckets (Task 8); it cannot run under PHPUnit's
 * cloned test DB because Bucket uses a separate restricted DB connection.
 *
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSchemaReader
 */
class BucketSchemaReaderTest extends MediaWikiUnitTestCase {

	/** The real seeded `item` bucket schema (trimmed but representative). */
	private function itemSchema(): array {
		return [
			'_page_id' => [ 'type' => 'INTEGER', 'index' => false, 'repeated' => false ],
			'_index' => [ 'type' => 'INTEGER', 'index' => false, 'repeated' => false ],
			'page_name' => [ 'type' => 'PAGE', 'index' => true, 'repeated' => false ],
			'page_name_sub' => [ 'type' => 'PAGE', 'index' => true, 'repeated' => false ],
			'item_type' => [ 'type' => 'TEXT', 'index' => true, 'repeated' => false ],
			'value' => [ 'type' => 'INTEGER', 'index' => true, 'repeated' => false ],
			'weight' => [ 'type' => 'DOUBLE', 'index' => false, 'repeated' => false ],
			'members' => [ 'type' => 'BOOLEAN', 'index' => true, 'repeated' => false ],
			'tags' => [ 'type' => 'TEXT', 'index' => true, 'repeated' => true ],
			'_time' => 1781117412,
		];
	}

	public function testParsesFieldTypesAndFlags(): void {
		$fields = BucketSchemaReader::fieldsFromSchema( $this->itemSchema() );

		$this->assertSame( [ 'type' => 'INTEGER', 'repeated' => false, 'indexed' => true ], $fields['value'] );
		$this->assertSame( [ 'type' => 'DOUBLE', 'repeated' => false, 'indexed' => false ], $fields['weight'] );
		$this->assertSame( [ 'type' => 'BOOLEAN', 'repeated' => false, 'indexed' => true ], $fields['members'] );
		$this->assertSame( [ 'type' => 'PAGE', 'repeated' => false, 'indexed' => true ], $fields['page_name'] );
	}

	public function testKeepsRepeatedFlag(): void {
		$fields = BucketSchemaReader::fieldsFromSchema( $this->itemSchema() );
		$this->assertTrue( $fields['tags']['repeated'] );
		$this->assertSame( 'TEXT', $fields['tags']['type'] );
	}

	public function testDropsSystemFields(): void {
		$fields = BucketSchemaReader::fieldsFromSchema( $this->itemSchema() );
		$this->assertArrayNotHasKey( '_page_id', $fields );
		$this->assertArrayNotHasKey( '_index', $fields );
		$this->assertArrayNotHasKey( '_time', $fields );
	}

	public function testKeepsPageNameAsSelectableField(): void {
		// page_name / page_name_sub are real Page-type fields an author may select.
		$fields = BucketSchemaReader::fieldsFromSchema( $this->itemSchema() );
		$this->assertArrayHasKey( 'page_name', $fields );
		$this->assertArrayHasKey( 'page_name_sub', $fields );
	}

	public function testSkipsMalformedEntries(): void {
		$fields = BucketSchemaReader::fieldsFromSchema( [
			'good' => [ 'type' => 'TEXT', 'index' => true, 'repeated' => false ],
			'bad' => 'not-an-array',
			'noType' => [ 'index' => true ],
			'_time' => 123,
		] );
		$this->assertSame( [ 'good' ], array_keys( $fields ) );
	}
}
