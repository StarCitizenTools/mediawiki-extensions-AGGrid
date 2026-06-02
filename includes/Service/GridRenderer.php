<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Service;

use MediaWiki\Html\TemplateParser;

/**
 * Renders the client-side placeholder for an AG Grid instance via Mustache template.
 *
 * The grid configuration (an AG Grid gridOptions object) is JSON-encoded and
 * passed into the template as the `options` variable. The client module reads
 * the resulting `data-mw-aggrid-options` attribute, parses it, and calls
 * agGrid.createGrid() on the rendered div.
 */
final class GridRenderer {

	public function __construct(
		private readonly TemplateParser $templateParser
	) {
	}

	/**
	 * Render the AG Grid placeholder HTML for the given grid options.
	 *
	 * @param array $gridOptions AG Grid gridOptions array.
	 * @return string HTML placeholder.
	 */
	public function render( array $gridOptions ): string {
		$json = json_encode(
			self::normalizeSequences( $gridOptions ),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);

		return $this->templateParser->processTemplate( 'grid', [ 'options' => $json ] );
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
