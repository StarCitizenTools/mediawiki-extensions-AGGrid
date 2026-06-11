<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Backend-agnostic descent over an AG Grid column filter model, shared by the per-backend
 * filter translators ({@see Smw\FilterTranslator}, {@see Bucket\BucketFilterTranslator}).
 *
 * Owns the structurally identical parts: the per-column set/combined/simple dispatch, the
 * combined { operator, conditions } descent (drop nulls, collapse a single survivor, else
 * group), the asc/desc direction map, and the 6-key comparator table as {@see FilterOperator}.
 *
 * Backends supply the leaf hooks (emitSet / emitSimple / combine) and keep every divergence
 * below this line: SMW's text/date/boolean families, quick search and sort-key normalization;
 * Bucket's single-value-set-stays-wrapped rule; and each backend's inRange single-bound
 * collapse, blank/notBlank semantics, and value coercion. The base never inspects a leaf.
 */
abstract class AbstractFilterModelWalker {

	/** @var array<string,string> AG Grid sort direction -> uppercase backend direction. */
	protected const SORT_DIR = [
		'asc' => 'ASC',
		'desc' => 'DESC',
	];

	/**
	 * Map an AG Grid number/date filter `type` string to a canonical {@see FilterOperator},
	 * or null for a type with no comparison operator (ranges, blanks, text matches).
	 */
	protected function operatorFor( string $agType ): ?FilterOperator {
		return match ( $agType ) {
			'equals' => FilterOperator::Equals,
			'notEqual' => FilterOperator::NotEqual,
			'greaterThan' => FilterOperator::GreaterThan,
			'greaterThanOrEqual' => FilterOperator::GreaterThanOrEqual,
			'lessThan' => FilterOperator::LessThan,
			'lessThanOrEqual' => FilterOperator::LessThanOrEqual,
			default => null,
		};
	}

	/**
	 * Dispatch one column's filter model: a set filter ({ values }), a combined
	 * ({ operator, conditions }), or a simple condition. Returns a backend leaf, or null
	 * when the column contributes no condition.
	 *
	 * @param string $col Backend column identity (SMW property name / Bucket select string).
	 * @param array<string,mixed> $model
	 * @param string $family Filter family for the simple/combined paths.
	 * @return mixed Backend leaf or null.
	 */
	protected function walkColumn( string $col, array $model, string $family ) {
		// Set filter: detected by presence of 'values'. (Backend decides how family applies.)
		if ( isset( $model['values'] ) ) {
			return $this->emitSet( $col, $model );
		}
		// Combined filter: { operator, conditions }.
		if ( isset( $model['operator'] ) && isset( $model['conditions'] ) ) {
			return $this->walkCombined( $col, $model, $family );
		}
		return $this->emitSimple( $col, $model, $family );
	}

	/**
	 * Descend a combined { operator, conditions } model: translate each condition via the
	 * simple leaf, drop nulls, collapse a single survivor to the bare leaf, else group.
	 * A non-'OR' operator (including a missing/malformed one) defaults to AND.
	 *
	 * @param string $col
	 * @param array<string,mixed> $model
	 * @param string $family
	 * @return mixed Backend leaf or null.
	 */
	protected function walkCombined( string $col, array $model, string $family ) {
		$parts = [];
		foreach ( $model['conditions'] as $condition ) {
			$part = $this->emitSimple( $col, $condition, $family );
			if ( $part !== null ) {
				$parts[] = $part;
			}
		}
		if ( count( $parts ) === 0 ) {
			return null;
		}
		if ( count( $parts ) === 1 ) {
			return $parts[0];
		}
		return $this->combine( ( $model['operator'] ?? '' ) === 'OR' ? 'OR' : 'AND', $parts );
	}

	/**
	 * Emit a set-filter leaf for a column ({ values, exclude? }). The backend owns the
	 * include/exclude shape and whether a single value collapses (SMW collapses; Bucket
	 * must stay wrapped so repeated-field conditions route through its subquery path).
	 *
	 * @param string $col
	 * @param array<string,mixed> $model
	 * @return mixed Backend leaf or null.
	 */
	abstract protected function emitSet( string $col, array $model );

	/**
	 * Emit a simple (non-set, non-combined) condition leaf, dispatching by family. The
	 * backend owns the inRange value-key + single-bound collapse, blank/notBlank semantics,
	 * comparator emission (via {@see operatorFor}), and value coercion.
	 *
	 * @param string $col
	 * @param array<string,mixed> $condition
	 * @param string $family
	 * @return mixed Backend leaf or null.
	 */
	abstract protected function emitSimple( string $col, array $condition, string $family );

	/**
	 * Combine two or more backend leaves under a group operator ('AND' | 'OR').
	 *
	 * @param string $operator 'AND' | 'OR'.
	 * @param array $parts Two or more backend leaves.
	 * @return mixed A backend group leaf.
	 */
	abstract protected function combine( string $operator, array $parts );
}
