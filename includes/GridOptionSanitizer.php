<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid;

/**
 * Removes the author-supplied gridOptions that AG Grid renders as HTML rather than text, so
 * a wikitext author cannot inject markup (stored XSS). These have no safe use from wikitext:
 * custom cell/header HTML comes from a JS renderer registered through the ext.aggrid.register
 * hook (trusted site JS).
 *
 * Re-check the key list when the vendored AG Grid bundle is upgraded — a new version may add
 * options that take an HTML string.
 */
final class GridOptionSanitizer {

	/** Keys whose value AG Grid renders as HTML, anywhere in the options tree. */
	private const HTML_OPTION_KEYS = [
		'overlayNoRowsTemplate' => true,
		'overlayLoadingTemplate' => true,
		'icons' => true,
		'template' => true,
	];

	/**
	 * Recursively remove the HTML-string option keys. `rowData` is left untouched: its
	 * values are cell data, and a field may share a name with a stripped option.
	 *
	 * @param array $options gridOptions (or a nested fragment).
	 * @return array
	 */
	public static function strip( array $options ): array {
		$clean = [];
		foreach ( $options as $key => $value ) {
			if ( isset( self::HTML_OPTION_KEYS[$key] ) ) {
				continue;
			}
			if ( $key === 'rowData' || !is_array( $value ) ) {
				$clean[$key] = $value;
				continue;
			}
			$clean[$key] = self::strip( $value );
		}
		return $clean;
	}
}
