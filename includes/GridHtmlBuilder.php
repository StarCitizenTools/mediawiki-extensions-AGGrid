<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid;

use MediaWiki\Html\Html;

/**
 * Builds the client-side placeholder for an AG Grid instance.
 *
 * The grid configuration (an AG Grid gridOptions object) is carried in a
 * `data-mw-aggrid-options` attribute on the placeholder. The client module
 * reads the attribute, JSON-parses it, and calls agGrid.createGrid() on the div.
 */
final class GridHtmlBuilder {

	private const SKELETON_ROWS = 6;

	public static function build( array $gridOptions ): string {
		$json = json_encode(
			self::normalizeSequences( $gridOptions ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return Html::rawElement( 'div', [
			'class' => 'ext-aggrid',
			'data-mw-aggrid-options' => $json,
			'aria-busy' => 'true',
		], self::buildSkeleton() );
	}

	/**
	 * Decorative loading skeleton shown until the grid mounts client-side.
	 * aria-hidden because it carries no real content.
	 */
	private static function buildSkeleton(): string {
		$rows = '';
		for ( $i = 0; $i < self::SKELETON_ROWS; $i++ ) {
			$rows .= Html::element( 'div', [ 'class' => 'ext-aggrid__skeleton-row' ] );
		}

		return Html::rawElement(
			'div',
			[ 'class' => 'ext-aggrid__skeleton', 'aria-hidden' => 'true' ],
			Html::element( 'div', [ 'class' => 'ext-aggrid__skeleton-header' ] ) . $rows
		);
	}

	/**
	 * Re-index Lua sequences to 0-based lists so json_encode emits JSON arrays.
	 *
	 * Lua tables reach PHP as 1-indexed arrays (keys 1..n). json_encode only
	 * serializes 0-indexed sequential arrays as JSON arrays; a 1-indexed array
	 * becomes a JSON object ({"1":…}). AG Grid requires columnDefs/rowData to be
	 * arrays, so any array whose keys are exactly 1..n is converted to a list.
	 * Recurses into nested values. Associative arrays (e.g. a column definition)
	 * are left as objects.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function normalizeSequences( $value ) {
		if ( !is_array( $value ) ) {
			return $value;
		}

		$normalized = [];
		foreach ( $value as $key => $item ) {
			$normalized[$key] = self::normalizeSequences( $item );
		}

		if ( array_keys( $normalized ) === range( 1, count( $normalized ) ) ) {
			return array_values( $normalized );
		}

		return $normalized;
	}
}
