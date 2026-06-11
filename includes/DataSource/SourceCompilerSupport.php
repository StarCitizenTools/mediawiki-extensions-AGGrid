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
 * columnDef, and raising a parse-time author error with the shared message prefix.
 * Backend-specific concerns (SMW's filterProp facet and _subject column, Bucket's
 * filter override and join/where/orderBy parsing) stay in the compilers.
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
