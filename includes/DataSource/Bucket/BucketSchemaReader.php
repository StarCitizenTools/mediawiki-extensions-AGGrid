<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\Bucket\BucketDatabase;

/**
 * Reads a Bucket's field schema from the `bucket_schemas` table.
 *
 * Used at parse time by {@see BucketSourceCompiler} to validate authored fields and
 * derive type-aware columns. The schema is read through Bucket's own restricted DB
 * connection ({@see BucketDatabase::getDB()}), so getFields() only works when the
 * Bucket extension is loaded and configured.
 */
class BucketSchemaReader {

	/**
	 * Read the author-visible fields of a bucket: field name => { type, repeated, indexed }.
	 *
	 * Returns an empty array for an unknown bucket (the caller raises a LuaError).
	 *
	 * @param string $bucketName
	 * @return array<string, array{type: string, repeated: bool, indexed: bool}>
	 */
	public function getFields( string $bucketName ): array {
		$json = BucketDatabase::getDB()->newSelectQueryBuilder()
			->select( 'schema_json' )
			->from( 'bucket_schemas' )
			->where( [ 'bucket_name' => $bucketName ] )
			->caller( __METHOD__ )
			->fetchField();

		if ( $json === false ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		if ( !is_array( $decoded ) ) {
			return [];
		}
		return self::fieldsFromSchema( $decoded );
	}

	/**
	 * Transform a decoded Bucket schema_json into the author-visible field map.
	 *
	 * Drops the internal columns (`_page_id`, `_index`) and the `_time` marker; keeps
	 * `page_name`/`page_name_sub` (real Page-type fields an author may select). Entries
	 * that are not well-formed (no array or no `type`) are skipped.
	 *
	 * @param array<string, mixed> $schema Decoded schema_json.
	 * @return array<string, array{type: string, repeated: bool, indexed: bool}>
	 */
	public static function fieldsFromSchema( array $schema ): array {
		$fields = [];
		foreach ( $schema as $name => $meta ) {
			if ( $name === '_time' || $name === '_page_id' || $name === '_index' ) {
				continue;
			}
			if ( !is_array( $meta ) || !isset( $meta['type'] ) ) {
				continue;
			}
			$fields[$name] = [
				'type' => (string)$meta['type'],
				'repeated' => (bool)( $meta['repeated'] ?? false ),
				'indexed' => (bool)( $meta['index'] ?? false ),
			];
		}
		return $fields;
	}
}
