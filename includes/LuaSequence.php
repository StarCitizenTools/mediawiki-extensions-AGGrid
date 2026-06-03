<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid;

/**
 * Converts Lua's 1-indexed tables into 0-indexed PHP lists so json_encode emits
 * JSON arrays (which AG Grid requires for columnDefs/rowData), not JSON objects.
 */
final class LuaSequence {

	/**
	 * Re-index any array whose keys are exactly 1..n to a 0-based list; recurse
	 * into nested values. Associative arrays are left untouched (objects).
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	public static function normalize( $value ) {
		if ( !is_array( $value ) ) {
			return $value;
		}

		$normalized = [];
		foreach ( $value as $key => $item ) {
			$normalized[$key] = self::normalize( $item );
		}

		if ( array_keys( $normalized ) === range( 1, count( $normalized ) ) ) {
			return array_values( $normalized );
		}

		return $normalized;
	}
}
