<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;
use RuntimeException;
use SMW\DIProperty;

/**
 * Compiles a Lua `source` descriptor (type = 'smw') into AG Grid columnDefs and a stored
 * query spec.
 *
 * Auto-builds type-aware columnDefs from the SMW property datatypes. The columnDef `field`
 * for each printout equals the SMW PrintRequest label that {@see SmwDataSource} emits, and the
 * subject column uses the reserved `_subject` key. Validation happens here because parse time is
 * the only author-feedback point.
 *
 * (Mirrors {@see \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSourceCompiler}.)
 */
class SmwSourceCompiler {

	/**
	 * Compile an `smw` source descriptor into [ columnDefs, spec ].
	 *
	 * @param array $source The `source` descriptor table.
	 * @return array{0: array, 1: array} [ columnDefs, spec ]
	 * @throws LuaError If the descriptor is invalid.
	 */
	public function compile( array $source ): array {
		$queryString = $this->buildQueryString( $source['query'] ?? null );
		$printouts = $this->parsePrintouts( $source['printouts'] ?? null );

		$mapper = new TypeColumnMapper();
		$columnDefs = [];
		$canonicalPrintouts = [];
		// field (the AG Grid colId / PrintRequest label) -> real SMW property name.
		// SmwDataSource resolves a column's property through this map so an aliased
		// printout ('Has population=Pop') sorts/filters on the property, not the alias.
		$fieldToProp = [];
		// field -> facet property label (issue #20): the property the column's filter
		// lists and matches, while display/sort stay on the column's own property.
		$facets = [];

		foreach ( $printouts as [ $prop, $label, $options ] ) {
			// newFromUserLabel returns null on some malformed labels and throws on
			// others (e.g. a bare underscore); treat both as an invalid property.
			try {
				$property = DIProperty::newFromUserLabel( $prop );
			} catch ( RuntimeException ) {
				throw new LuaError( 'mw.ext.aggrid.render: invalid property "' . $prop . '"' );
			}
			if ( $property === null ) {
				throw new LuaError( 'mw.ext.aggrid.render: invalid property "' . $prop . '"' );
			}
			$typeId = $property->findPropertyValueType();
			// field MUST equal the column key SmwDataSource emits (the PrintRequest label).
			$colDef = $mapper->mapColumn( $label, $label, $typeId );
			// Merge author presentation keys over the datatype-derived colDef. `type`
			// overrides the derived renderer; cellRendererParams/format are additive.
			// All are plain serializable data and ride in the placeholder attribute.
			if ( isset( $options['type'] ) ) {
				$colDef['type'] = $options['type'];
			}
			if ( isset( $options['cellRendererParams'] ) ) {
				$colDef['cellRendererParams'] = $options['cellRendererParams'];
			}
			if ( isset( $options['format'] ) ) {
				$colDef['format'] = $options['format'];
			}
			$facetProperty = $this->resolveFacetProperty( $options['filterProp'] ?? null, $property );
			if ( $facetProperty !== null ) {
				$filter = $mapper->filterComponent( $facetProperty->findPropertyValueType() );
				if ( $filter === false ) {
					throw new LuaError(
						'mw.ext.aggrid.render: filter property "' . $options['filterProp'] .
						'" has a datatype that cannot be filtered'
					);
				}
				// The whole filter follows the facet: the component here, the value
				// list and query conditions server-side via the spec's facets map.
				$colDef['filter'] = $filter;
				$facets[$label] = $facetProperty->getLabel();
			}
			$columnDefs[] = $colDef;
			// Stored printout must yield the same label: "prop=label" unless label === prop.
			$canonicalPrintouts[] = $label !== $prop ? $prop . '=' . $label : $prop;
			// Record the field->property mapping (label == prop for non-aliased columns).
			$fieldToProp[$label] = $prop;
		}

		// Subject (page) column, prepended unless mainlabel suppresses it ('-').
		$mainlabel = $source['mainlabel'] ?? null;
		$mainlabelIsString = is_string( $mainlabel );
		if ( !$mainlabelIsString || $mainlabel !== '-' ) {
			$header = ( $mainlabelIsString && trim( $mainlabel ) !== '' && $mainlabel !== '-' )
				? $mainlabel
				: 'Page';
			array_unshift( $columnDefs, [
				'field' => '_subject',
				'headerName' => $header,
				'type' => 'aggridLink',
				// The subject is not a filterable property in v1.
				'filter' => false,
			] );
		}

		$spec = [
			'query' => $queryString,
			'printouts' => $canonicalPrintouts,
			'mainlabel' => $mainlabelIsString ? $mainlabel : null,
			'fields' => $fieldToProp,
		];
		// Omitted when empty so facet-free grids keep byte-identical specs (no
		// spurious aggrid_source rewrites under the hash compare).
		if ( $facets !== [] ) {
			$spec['facets'] = $facets;
		}

		return [ $columnDefs, $spec ];
	}

	/**
	 * Normalize a query descriptor into a single trimmed query string.
	 *
	 * Accepts a string or a Lua sequence of condition fragments (joined by spaces).
	 *
	 * @param mixed $query
	 * @return string
	 * @throws LuaError If the result is empty.
	 */
	private function buildQueryString( $query ): string {
		if ( is_string( $query ) ) {
			$queryString = trim( $query );
		} elseif ( is_array( $query ) ) {
			$fragments = [];
			foreach ( $query as $fragment ) {
				if ( is_string( $fragment ) && trim( $fragment ) !== '' ) {
					$fragments[] = trim( $fragment );
				}
			}
			$queryString = implode( ' ', $fragments );
		} else {
			$queryString = '';
		}

		if ( $queryString === '' ) {
			throw new LuaError(
				'mw.ext.aggrid.render: source.query must be a non-empty string or list of fragments'
			);
		}

		return $queryString;
	}

	/**
	 * Parse the printouts descriptor into a list of [ prop, label, options ] triples.
	 *
	 * Entries may be a plain string ('Population'), a 'prop=label' string, or a table
	 * { prop = ..., label = ..., type = ..., cellRendererParams = ..., format = ...,
	 * filterProp = ... }. The display-only presentation keys (type, cellRendererParams,
	 * format) ride in the placeholder's gridOptions attribute and are not part of the
	 * stored query spec. filterProp is different: it feeds the stored query spec — it
	 * declares the property the column's filter lists and matches.
	 *
	 * @param mixed $printouts
	 * @return array<int, array{0: string, 1: string, 2: array}> Options bag keys:
	 *   type?, cellRendererParams?, format? (display-only), filterProp? (feeds the spec)
	 * @throws LuaError If empty or an entry lacks a property name.
	 */
	private function parsePrintouts( $printouts ): array {
		if ( !is_array( $printouts ) || $printouts === [] ) {
			throw new LuaError(
				'mw.ext.aggrid.render: source.printouts must list at least one property'
			);
		}

		$parsed = [];
		foreach ( $printouts as $entry ) {
			$options = [];
			if ( is_string( $entry ) ) {
				if ( strpos( $entry, '=' ) !== false ) {
					[ $prop, $label ] = explode( '=', $entry, 2 );
					$prop = trim( $prop );
					$label = trim( $label );
				} else {
					$prop = trim( $entry );
					$label = $prop;
				}
			} elseif ( is_array( $entry ) ) {
				$prop = isset( $entry['prop'] ) ? trim( (string)$entry['prop'] ) : '';
				$label = isset( $entry['label'] ) ? trim( (string)$entry['label'] ) : $prop;
				// Display-only presentation keys, copied only when well-typed. These ride
				// in the placeholder's gridOptions attribute; they are not part of the
				// stored query spec (the client interprets them at mount).
				if ( isset( $entry['type'] ) && is_string( $entry['type'] ) ) {
					$options['type'] = $entry['type'];
				}
				if ( isset( $entry['cellRendererParams'] ) && is_array( $entry['cellRendererParams'] ) ) {
					$options['cellRendererParams'] = $entry['cellRendererParams'];
				}
				if ( isset( $entry['format'] ) && is_array( $entry['format'] ) ) {
					$options['format'] = $entry['format'];
				}
				// Filter facet (issue #20): unlike the display-only keys above, this DOES
				// feed the stored query spec — the server lists and filters on it. Silent
				// type-gating only degrades presentation for the keys above; a silently
				// dropped facet would change filtering semantics, so reject it here.
				if ( isset( $entry['filterProp'] ) ) {
					if ( !is_string( $entry['filterProp'] ) ) {
						throw new LuaError(
							'mw.ext.aggrid.render: filterProp must be a string property name'
						);
					}
					$options['filterProp'] = trim( $entry['filterProp'] );
				}
			} else {
				$prop = '';
				$label = '';
			}

			if ( $prop === '' ) {
				throw new LuaError( 'mw.ext.aggrid.render: each printout needs a property name' );
			}
			if ( $label === '' ) {
				$label = $prop;
			}
			$parsed[] = [ $prop, $label, $options ];
		}

		return $parsed;
	}

	/**
	 * Validate a printout's filterProp into a DIProperty, or null when absent or
	 * identical to the display property (a redundant facet adds nothing and would
	 * only churn the stored spec).
	 *
	 * Parse time is the only author-feedback point — request-time failures surface
	 * as opaque 400s — so an invalid facet must die here as a LuaError.
	 *
	 * @param ?string $facetProp
	 * @param DIProperty $displayProperty
	 * @return DIProperty|null
	 * @throws LuaError If the facet property is invalid.
	 */
	private function resolveFacetProperty( ?string $facetProp, DIProperty $displayProperty ): ?DIProperty {
		if ( $facetProp === null ) {
			return null;
		}
		try {
			$property = DIProperty::newFromUserLabel( $facetProp );
		} catch ( RuntimeException ) {
			throw new LuaError( 'mw.ext.aggrid.render: invalid filter property "' . $facetProp . '"' );
		}
		// Defensive parity with the printout loop: some SMW versions return null on
		// malformed labels even though the current signature is `self`.
		// @phan-suppress-next-line PhanImpossibleTypeComparison
		if ( $property === null ) {
			throw new LuaError( 'mw.ext.aggrid.render: invalid filter property "' . $facetProp . '"' );
		}
		if ( $property->getKey() === $displayProperty->getKey() ) {
			return null;
		}
		// Label-less predefined properties (e.g. '_SKEY') construct fine but have no
		// user label; letting one through would store a degenerate '' facet label.
		if ( $property->getLabel() === '' ) {
			throw new LuaError( 'mw.ext.aggrid.render: invalid filter property "' . $facetProp . '"' );
		}
		return $property;
	}
}
