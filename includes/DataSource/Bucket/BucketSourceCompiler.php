<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;

/**
 * Compiles a Lua `source` descriptor (type = 'bucket') into AG Grid columnDefs and a
 * stored query spec.
 *
 * Validation happens here because parse time is the only author-feedback point — a bad
 * descriptor must die as a LuaError rather than surface as an opaque request-time 400.
 *
 * Field types are read from `bucket_schemas` ({@see BucketSchemaReader}) and frozen into
 * the spec. Each column's AG Grid colId is the Bucket select string with `.` replaced by
 * `__`, because AG Grid treats a dotted `field` as a nested object path while the data
 * source returns rows keyed by the flat select string. Bucket forbids `__` in field
 * names, so this never collides.
 */
class BucketSourceCompiler {

	/** Bucket's allowed comparison operators (mirrors BucketQuery's Operator allowlist). */
	private const WHERE_OPS = [ '=' => true, '!=' => true, '>=' => true, '<=' => true, '>' => true, '<' => true ];

	public function __construct(
		private readonly BucketSchemaReader $schemaReader,
		private readonly BucketColumnMapper $columnMapper
	) {
	}

	/**
	 * Compile a validated `source` descriptor into [ columnDefs, spec ].
	 *
	 * @param array $source The Lua `source` table (type already validated as 'bucket').
	 * @return array{0: array, 1: array} [ columnDefs, spec ]
	 * @throws LuaError On any invalid input.
	 */
	public function compile( array $source ): array {
		$bucket = $this->parseBucket( $source['bucket'] ?? null );
		$joins = $this->parseJoins( $source['join'] ?? null );

		// Field schemas keyed by bucket name: the primary bucket plus each joined bucket.
		$schemas = [ $bucket => $this->loadSchema( $bucket ) ];
		foreach ( $joins as $join ) {
			$schemas[$join['bucket']] = $this->loadSchema( $join['bucket'] );
		}

		[ $columnDefs, $selects, $fields ] = $this->parseFields( $source['fields'] ?? null, $bucket, $schemas );
		$where = $this->parseWhere( $source['where'] ?? null, $bucket, $schemas );
		$orderBy = $this->parseOrderBy( $source['orderBy'] ?? null, $selects );

		$spec = [
			'bucket' => $bucket,
			'selects' => $selects,
			'fields' => $fields,
		];
		// Omit empty sections so unchanged grids keep byte-identical specs (no spurious
		// aggrid_source rewrites under GridRenderer's hash compare).
		if ( $joins !== [] ) {
			$spec['joins'] = $joins;
		}
		if ( $where !== [] ) {
			$spec['where'] = $where;
		}
		if ( $orderBy !== null ) {
			$spec['orderBy'] = $orderBy;
		}

		return [ $columnDefs, $spec ];
	}

	/**
	 * @param mixed $bucket
	 * @throws LuaError
	 */
	private function parseBucket( $bucket ): string {
		if ( !is_string( $bucket ) || trim( $bucket ) === '' ) {
			throw new LuaError( 'mw.ext.aggrid.render: source.bucket must be a non-empty string' );
		}
		return trim( $bucket );
	}

	/**
	 * Load a bucket's field schema, or throw if the bucket is unknown.
	 *
	 * @param string $bucket
	 * @return array<string, array{type: string, repeated: bool, indexed: bool}>
	 * @throws LuaError
	 */
	private function loadSchema( string $bucket ): array {
		$fields = $this->schemaReader->getFields( $bucket );
		if ( $fields === [] ) {
			throw new LuaError( 'mw.ext.aggrid.render: unknown bucket "' . $bucket . '"' );
		}
		return $fields;
	}

	/**
	 * Parse the optional `join` list.
	 *
	 * @param mixed $joins
	 * @return array<int, array{bucket: string, on: array{0: string, 1: string}}>
	 * @throws LuaError
	 */
	private function parseJoins( $joins ): array {
		if ( $joins === null ) {
			return [];
		}
		if ( !is_array( $joins ) ) {
			throw new LuaError( 'mw.ext.aggrid.render: source.join must be a list of joins' );
		}
		$parsed = [];
		foreach ( $joins as $join ) {
			if ( !is_array( $join ) || !isset( $join['bucket'] ) || !is_string( $join['bucket'] )
				|| !isset( $join['on'] ) || !is_array( $join['on'] )
			) {
				throw new LuaError(
					'mw.ext.aggrid.render: each join needs a "bucket" and an "on" pair { field, field }'
				);
			}
			$on = array_values( $join['on'] );
			if ( count( $on ) !== 2 || !is_string( $on[0] ) || !is_string( $on[1] ) ) {
				throw new LuaError(
					'mw.ext.aggrid.render: each join needs a "bucket" and an "on" pair { field, field }'
				);
			}
			$parsed[] = [ 'bucket' => trim( $join['bucket'] ), 'on' => [ $on[0], $on[1] ] ];
		}
		return $parsed;
	}

	/**
	 * Parse the `fields` list into [ columnDefs, selects, specFields ].
	 *
	 * @param mixed $fields
	 * @param string $bucket Primary bucket name.
	 * @param array $schemas bucket name -> field schema map.
	 * @return array{0: array, 1: string[], 2: array<string, array{select:string,type:string,repeated:bool}>}
	 * @throws LuaError
	 */
	private function parseFields( $fields, string $bucket, array $schemas ): array {
		if ( !is_array( $fields ) || $fields === [] ) {
			throw new LuaError( 'mw.ext.aggrid.render: source.fields must list at least one field' );
		}

		$columnDefs = [];
		$selects = [];
		$specFields = [];
		foreach ( $fields as $entry ) {
			[ $select, $label, $opts ] = $this->parseFieldEntry( $entry );
			$meta = $this->resolveField( $select, $bucket, $schemas );
			$colId = $this->colId( $select );
			if ( isset( $specFields[$colId] ) ) {
				throw new LuaError( 'mw.ext.aggrid.render: duplicate column "' . $colId . '"' );
			}
			$colDef = $this->columnMapper->mapColumn( $colId, $label, $meta['type'], $meta['repeated'] );
			$columnDefs[] = $this->applyOverrides( $colDef, $opts );
			$selects[] = $select;
			$specFields[$colId] = [
				'select' => $select,
				'type' => $meta['type'],
				'repeated' => $meta['repeated'],
			];
		}
		return [ $columnDefs, $selects, $specFields ];
	}

	/**
	 * Parse one `fields` entry (a plain field string or a { field, label, … } table).
	 *
	 * @param mixed $entry
	 * @return array{0: string, 1: string, 2: array} [ select, label, opts ]
	 * @throws LuaError
	 */
	private function parseFieldEntry( $entry ): array {
		$opts = [];
		$labelOpt = '';
		if ( is_string( $entry ) ) {
			$select = trim( $entry );
		} elseif ( is_array( $entry ) ) {
			$select = isset( $entry['field'] ) && is_string( $entry['field'] ) ? trim( $entry['field'] ) : '';
			$labelOpt = isset( $entry['label'] ) && is_string( $entry['label'] ) ? trim( $entry['label'] ) : '';
			// Display-only presentation keys, copied only when well-typed (mirrors the SMW path).
			if ( isset( $entry['type'] ) && is_string( $entry['type'] ) ) {
				$opts['type'] = $entry['type'];
			}
			if ( isset( $entry['cellRendererParams'] ) && is_array( $entry['cellRendererParams'] ) ) {
				$opts['cellRendererParams'] = $entry['cellRendererParams'];
			}
			if ( isset( $entry['format'] ) && is_array( $entry['format'] ) ) {
				$opts['format'] = $entry['format'];
			}
			// filter override: false disables filtering, a string forces a component.
			if ( array_key_exists( 'filter', $entry )
				&& ( is_bool( $entry['filter'] ) || is_string( $entry['filter'] ) )
			) {
				$opts['filter'] = $entry['filter'];
			}
		} else {
			$select = '';
		}

		if ( $select === '' ) {
			throw new LuaError( 'mw.ext.aggrid.render: each field needs a name' );
		}
		$label = $labelOpt !== '' ? $labelOpt : $this->lastSegment( $select );
		return [ $select, $label, $opts ];
	}

	/**
	 * Resolve a select string to its field metadata, validating bucket and field.
	 *
	 * @param string $select Bucket select string ('field' or 'bucket.field').
	 * @param string $primaryBucket
	 * @param array $schemas bucket name -> field schema map.
	 * @return array{type: string, repeated: bool, indexed: bool}
	 * @throws LuaError
	 */
	private function resolveField( string $select, string $primaryBucket, array $schemas ): array {
		$parts = explode( '.', $select );
		if ( count( $parts ) === 1 ) {
			$bucket = $primaryBucket;
			$field = $parts[0];
		} elseif ( count( $parts ) === 2 ) {
			$bucket = $parts[0];
			$field = $parts[1];
			if ( !isset( $schemas[$bucket] ) ) {
				throw new LuaError(
					'mw.ext.aggrid.render: field "' . $select . '" references bucket "' . $bucket .
					'" which is not the primary bucket or a joined bucket'
				);
			}
		} else {
			throw new LuaError( 'mw.ext.aggrid.render: invalid field "' . $select . '"' );
		}
		if ( !isset( $schemas[$bucket][$field] ) ) {
			throw new LuaError( 'mw.ext.aggrid.render: unknown field "' . $select . '"' );
		}
		return $schemas[$bucket][$field];
	}

	/**
	 * Merge author presentation overrides over a derived colDef.
	 *
	 * @param array $colDef
	 * @param array $opts
	 * @return array
	 */
	private function applyOverrides( array $colDef, array $opts ): array {
		if ( isset( $opts['type'] ) ) {
			$colDef['type'] = $opts['type'];
		}
		if ( isset( $opts['cellRendererParams'] ) ) {
			$colDef['cellRendererParams'] = $opts['cellRendererParams'];
		}
		if ( isset( $opts['format'] ) ) {
			$colDef['format'] = $opts['format'];
		}
		if ( array_key_exists( 'filter', $opts ) ) {
			$colDef['filter'] = $opts['filter'];
		}
		return $colDef;
	}

	/**
	 * Parse the optional base `where` into a list of normalised [ field, op, value ]
	 * operands. Only a flat conjunction of comparison conditions is supported in v1.
	 *
	 * @param mixed $where
	 * @param string $bucket Primary bucket name.
	 * @param array $schemas bucket name -> field schema map.
	 * @return array<int, array{0: string, 1: string, 2: mixed}>
	 * @throws LuaError
	 */
	private function parseWhere( $where, string $bucket, array $schemas ): array {
		if ( $where === null ) {
			return [];
		}
		if ( !is_array( $where ) ) {
			throw new LuaError(
				'mw.ext.aggrid.render: source.where must be a list of { field, operator, value } conditions'
			);
		}
		$parsed = [];
		foreach ( $where as $cond ) {
			$c = is_array( $cond ) ? array_values( $cond ) : [];
			if ( count( $c ) !== 3 || !is_string( $c[0] ) || !is_string( $c[1] ) ) {
				throw new LuaError(
					'mw.ext.aggrid.render: each where condition must be { field, operator, value }'
				);
			}
			$field = trim( $c[0] );
			$op = $c[1];
			$value = $c[2];
			if ( !isset( self::WHERE_OPS[$op] ) ) {
				throw new LuaError( 'mw.ext.aggrid.render: invalid where operator "' . $op . '"' );
			}
			if ( !is_scalar( $value ) ) {
				throw new LuaError( 'mw.ext.aggrid.render: where value must be a string, number or boolean' );
			}
			// Validate the field exists (base where may scope on a non-displayed field).
			$this->resolveField( $field, $bucket, $schemas );
			$parsed[] = [ $field, $op, $value ];
		}
		return $parsed;
	}

	/**
	 * Parse the optional `orderBy` into a { field, direction } spec. The field must be
	 * one of the selected fields (Bucket requires the sort field to be selected).
	 *
	 * @param mixed $orderBy
	 * @param string[] $selects
	 * @return array{field: string, direction: string}|null
	 * @throws LuaError
	 */
	private function parseOrderBy( $orderBy, array $selects ): ?array {
		if ( $orderBy === null ) {
			return null;
		}
		if ( !is_array( $orderBy ) || !isset( $orderBy['field'] ) || !is_string( $orderBy['field'] ) ) {
			throw new LuaError( 'mw.ext.aggrid.render: source.orderBy must be { field, direction }' );
		}
		$field = trim( $orderBy['field'] );
		if ( !in_array( $field, $selects, true ) ) {
			throw new LuaError(
				'mw.ext.aggrid.render: orderBy field "' . $field . '" must be one of the selected fields'
			);
		}
		$direction = isset( $orderBy['direction'] ) && is_string( $orderBy['direction'] )
			? strtoupper( trim( $orderBy['direction'] ) )
			: 'ASC';
		if ( $direction !== 'ASC' && $direction !== 'DESC' ) {
			throw new LuaError( 'mw.ext.aggrid.render: orderBy direction must be ASC or DESC' );
		}
		return [ 'field' => $field, 'direction' => $direction ];
	}

	/**
	 * The AG Grid colId for a select string: dots become `__` (see class doc).
	 */
	private function colId( string $select ): string {
		return str_replace( '.', '__', $select );
	}

	/**
	 * The last dot-separated segment of a select string (default column header).
	 */
	private function lastSegment( string $select ): string {
		$parts = explode( '.', $select );
		return end( $parts );
	}
}
