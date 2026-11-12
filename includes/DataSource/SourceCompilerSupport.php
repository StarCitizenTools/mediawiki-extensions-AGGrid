<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;

/**
 * Shared leaf helpers for the per-backend source compilers
 * ({@see Smw\SmwSourceCompiler}, {@see Bucket\BucketSourceCompiler}).
 *
 * Covers the idioms both compilers duplicate verbatim: harvesting the display-only
 * presentation keys an author may set on a column, merging them over a derived
 * columnDef, parsing the default sort (`orderBy`), and raising a parse-time author
 * error with the shared message prefix. Backend-specific concerns (SMW's filterProp
 * facet and _subject column, Bucket's filter override and join/where parsing) stay in
 * the compilers.
 */
trait SourceCompilerSupport {

	/**
	 * Harvest the display-only presentation keys from a column entry, copying each
	 * only when well-typed. These ride in the placeholder's gridOptions attribute and
	 * are not part of the stored query spec.
	 *
	 * Deliberately limited to the strict intersection both backends share — a filter
	 * or filterProp override is a backend concern handled by the caller.
	 *
	 * @param array $entry A column descriptor table.
	 * @return array{type?: string, cellRendererParams?: array, format?: array}
	 */
	protected function harvestPresentationOptions( array $entry ): array {
		$opts = [];
		if ( isset( $entry['type'] ) && is_string( $entry['type'] ) ) {
			$opts['type'] = $entry['type'];
		}
		if ( isset( $entry['cellRendererParams'] ) && is_array( $entry['cellRendererParams'] ) ) {
			$opts['cellRendererParams'] = $entry['cellRendererParams'];
		}
		if ( isset( $entry['format'] ) && is_array( $entry['format'] ) ) {
			$opts['format'] = $entry['format'];
		}
		return $opts;
	}

	/**
	 * Merge harvested presentation options over a derived columnDef. `type` overrides
	 * the datatype-derived renderer; cellRendererParams and format are additive. Extra
	 * keys (e.g. a backend filter override) are ignored here.
	 *
	 * @param array $colDef
	 * @param array $opts Options from {@see harvestPresentationOptions}.
	 * @return array
	 */
	protected function applyPresentationOverrides( array $colDef, array $opts ): array {
		if ( isset( $opts['type'] ) ) {
			$colDef['type'] = $opts['type'];
		}
		if ( isset( $opts['cellRendererParams'] ) ) {
			$colDef['cellRendererParams'] = $opts['cellRendererParams'];
		}
		if ( isset( $opts['format'] ) ) {
			$colDef['format'] = $opts['format'];
		}
		return $colDef;
	}

	/**
	 * Validate an `orderBy` descriptor into a stored spec entry, or null when absent.
	 * The field must be one of $allowed; the direction is 'ASC' (default) or 'DESC'.
	 * It feeds the stored query spec, so parse time is the only author-feedback point.
	 *
	 * @param mixed $orderBy The `source.orderBy` value; null when absent.
	 * @param string[] $allowed Field names the sort may name.
	 * @param string $noun How the error describes $allowed ('declared', 'selected').
	 * @return array{field: string, direction: string}|null
	 * @throws LuaError If the descriptor is invalid.
	 */
	protected function parseOrderBy( mixed $orderBy, array $allowed, string $noun ): ?array {
		if ( $orderBy === null ) {
			return null;
		}
		if ( !is_array( $orderBy ) || !isset( $orderBy['field'] ) || !is_string( $orderBy['field'] ) ) {
			$this->fail( 'source.orderBy must be { field, direction }' );
		}
		$field = trim( $orderBy['field'] );
		if ( !in_array( $field, $allowed, true ) ) {
			$this->fail( 'orderBy field "' . $field . '" must be one of the ' . $noun . ' fields' );
		}
		$direction = isset( $orderBy['direction'] ) && is_string( $orderBy['direction'] )
			? strtoupper( trim( $orderBy['direction'] ) )
			: 'ASC';
		if ( $direction !== 'ASC' && $direction !== 'DESC' ) {
			$this->fail( 'orderBy direction must be ASC or DESC' );
		}
		return [ 'field' => $field, 'direction' => $direction ];
	}

	/**
	 * Raise a parse-time author error with the shared 'mw.ext.aggrid.render:' prefix.
	 * Parse time is the only author-feedback point, so a descriptor problem must die
	 * here as a LuaError rather than surface as an opaque request-time 400.
	 *
	 * @param string $message Message without the prefix.
	 * @throws LuaError Always.
	 */
	protected function fail( string $message ): never {
		throw new LuaError( 'mw.ext.aggrid.render: ' . $message );
	}
}
