<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use RuntimeException;
use SMW\DataValueFactory;
use SMW\DIProperty;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Description;
use SMW\Query\Language\Disjunction;
use SMW\Query\Language\SomeProperty;
use SMW\Query\Language\ThingDescription;
use SMW\Query\Language\ValueDescription;

/**
 * Translates AG Grid sort/filter models into SMW query constructs.
 *
 * - toSortKeys() maps a sortModel array to an ordered associative array of
 *   property => direction, defaulting to the subject ('' => 'ASC') when the
 *   user has chosen no sort, and appending the subject as a secondary tiebreaker
 *   so offset paging yields a stable total order.
 *
 * - toDescription() maps a filterModel object (keyed by column field) to a
 *   Description tree that callers can AND onto a base SMW query.
 */
class FilterTranslator {

	/**
	 * Map AG Grid sort direction strings to SMW sort direction strings.
	 *
	 * @var array<string,string>
	 */
	private const SORT_DIR = [
		'asc' => 'ASC',
		'desc' => 'DESC',
	];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Convert an AG Grid sortModel to an ordered array of SMW sort keys.
	 *
	 * Each entry in $sortModel must be [ 'colId' => string, 'sort' => 'asc'|'desc' ].
	 * The reserved subject column field '_subject' maps to SMW's empty-string sort
	 * key. A property colId is normalized to SMW's sort key (the property's DBkey,
	 * e.g. "Maximum weight" -> "Maximum_weight"); SMW matches sort keys on that form,
	 * so an unnormalized label is silently ignored. When the user has chosen no sort,
	 * defaults to '' => 'ASC'.
	 *
	 * The subject ('' key) is appended as a secondary tiebreaker unless the user
	 * already sorts by it: offset paging over a non-unique sort can otherwise
	 * reorder rows across pages, so a stable total order is required.
	 *
	 * A column field may be an alias for a different SMW property (an aliased
	 * printout); the optional $propertyOf resolver maps the AG Grid field to the
	 * real property name before the sort key is computed. When null, the field is
	 * treated as the property (backward-compatible, field == property).
	 *
	 * @param array<int, array<string,string>> $sortModel
	 * @param callable|null $propertyOf Callable( string $field ): string mapping a
	 *   column field to its real SMW property name.
	 * @return array<string,string>
	 */
	public function toSortKeys( array $sortModel, ?callable $propertyOf = null ): array {
		$keys = [];
		foreach ( $sortModel as $entry ) {
			if ( $entry['colId'] === '_subject' ) {
				$colId = '';
			} else {
				$prop = $propertyOf !== null ? $propertyOf( $entry['colId'] ) : $entry['colId'];
				$colId = $this->sortKeyFor( $prop );
			}
			$keys[$colId] = self::SORT_DIR[$entry['sort']] ?? 'ASC';
		}
		if ( $keys === [] ) {
			return [ '' => 'ASC' ];
		}
		// Append the subject as a stable tiebreaker unless the user already sorts by it.
		if ( !array_key_exists( '', $keys ) ) {
			$keys[''] = 'ASC';
		}
		return $keys;
	}

	/**
	 * Normalize a column field (a property label) to SMW's sort key — the property
	 * DBkey (spaces to underscores, leading uppercase). SMW keys sort entries on
	 * this form, so passing the raw label causes the sort to be silently dropped.
	 * Falls back to the raw field if the label cannot be resolved to a property.
	 */
	private function sortKeyFor( string $field ): string {
		try {
			$property = DIProperty::newFromUserLabel( $field );
		} catch ( RuntimeException ) {
			return $field;
		}
		return $property->getKey();
	}

	/**
	 * Convert an AG Grid filterModel to an SMW Description tree.
	 *
	 * Returns a Conjunction if multiple columns are active, a single-column
	 * Description if only one column is active, or null if no active filters.
	 *
	 * A column field may be an alias for a different SMW property; the optional
	 * $propertyOf resolver maps the AG Grid field to the real property name before
	 * the DIProperty is built. When null, the field is treated as the property
	 * (backward-compatible, field == property).
	 *
	 * @param array<string, array<string,mixed>> $filterModel
	 * @param callable $familyOf Callable( string $field ): string — returns
	 *   the filter family ('text'|'number'|'date'|'boolean'|'set'|'none').
	 * @param callable|null $propertyOf Callable( string $field ): string mapping a
	 *   column field to its real SMW property name.
	 * @return Description|null
	 */
	public function toDescription(
		array $filterModel,
		callable $familyOf,
		?callable $propertyOf = null
	): ?Description {
		$parts = [];
		foreach ( $filterModel as $field => $model ) {
			$family = $familyOf( $field );
			$prop = $propertyOf !== null ? $propertyOf( $field ) : $field;
			$desc = $this->columnDescription( $prop, $model, $family );
			if ( $desc !== null ) {
				$parts[] = $desc;
			}
		}
		if ( count( $parts ) === 0 ) {
			return null;
		}
		if ( count( $parts ) === 1 ) {
			return $parts[0];
		}
		return new Conjunction( $parts );
	}

	// -------------------------------------------------------------------------
	// Quick search (free-text box over the whole grid)
	// -------------------------------------------------------------------------

	/**
	 * Build a quick-search Description for a free-text term, matching the page
	 * subject and the given columns.
	 *
	 * The term is split on whitespace into words; every word must match somewhere
	 * (AND across words), and each word may match any searched target (OR across the
	 * subject and columns) — mirroring AG Grid's client-side quick filter.
	 *
	 * Each target's condition is built through SMW's own
	 * {@see \SMW\DataValues\DataValue::getQueryDescription()}, so every datatype gets
	 * its native query semantics: text/page columns and the subject match a substring
	 * (~*word*), date columns expand to a precision range (~word), and number columns
	 * match exactly. A target whose value does not parse for its type yields a
	 * ThingDescription (match-everything), which is dropped so it cannot nullify the
	 * word's disjunction (the value form per kind comes from {@see quickSearchValue}).
	 *
	 * User words are stripped of SMW query metacharacters first; combined with the
	 * fixed wrapping per kind (and number columns matching only a strictly-numeric
	 * word), a word is always a literal substring or an exact number and cannot inject
	 * comparators or wildcards.
	 *
	 * @param string $term The raw search-box value.
	 * @param string[] $searchFields Declared column fields to consider.
	 * @param callable $propertyOf Callable( string $field ): string — column field to
	 *   its real SMW property name.
	 * @param callable $kindOf Callable( string $field ): ?string — column field to its
	 *   quick-search kind (like-text|like-page|like-date|eq-number), or null to skip.
	 * @param bool $includeSubject Whether to also match the page subject (page name).
	 * @return Description|null Null when the term is empty or yields no condition.
	 */
	public function quickSearchDescription(
		string $term,
		array $searchFields,
		callable $propertyOf,
		callable $kindOf,
		bool $includeSubject
	): ?Description {
		$words = $this->quickSearchWords( $term );
		if ( $words === [] ) {
			return null;
		}

		$wordDescriptions = [];
		foreach ( $words as $word ) {
			$parts = [];
			if ( $includeSubject ) {
				$subject = $this->quickSubjectDescription( $word );
				if ( $subject !== null ) {
					$parts[] = $subject;
				}
			}
			foreach ( $searchFields as $field ) {
				$kind = $kindOf( $field );
				if ( $kind === null ) {
					continue;
				}
				$column = $this->quickColumnDescription( $propertyOf( $field ), $kind, $word );
				if ( $column !== null ) {
					$parts[] = $column;
				}
			}
			if ( $parts === [] ) {
				// This word cannot be expressed as any condition — only reachable with
				// no subject and no text/page columns (e.g. a non-numeric word over a
				// number-only grid whose subject column is suppressed). The AND of words
				// is then unsatisfiable; degrade to no quick-search constraint.
				return null;
			}
			$wordDescriptions[] = count( $parts ) === 1 ? $parts[0] : new Disjunction( $parts );
		}

		return count( $wordDescriptions ) === 1
			? $wordDescriptions[0]
			: new Conjunction( $wordDescriptions );
	}

	/**
	 * Split a term into sanitized words: whitespace-separated, with SMW query
	 * metacharacters removed so each word is a literal substring. Empty words drop out.
	 *
	 * @return string[]
	 */
	private function quickSearchWords( string $term ): array {
		$words = [];
		foreach ( preg_split( '/\s+/', trim( $term ) ) as $raw ) {
			$word = str_replace( [ '*', '~', '!', '<', '>', '|', '?' ], '', $raw );
			if ( $word !== '' ) {
				$words[] = $word;
			}
		}
		return $words;
	}

	/**
	 * The query value form for a search kind: a substring (~*word*) for text/page, the
	 * ~ precision comparator for dates, and the bare word (exact =) for numbers.
	 *
	 * @return string|null Null for an unknown kind.
	 */
	private function quickSearchValue( string $kind, string $word ): ?string {
		return match ( $kind ) {
			'like-text', 'like-page' => '~*' . $word . '*',
			'like-date' => '~' . $word,
			// Only a strictly-numeric word reaches the number builder. The word is at
			// position 0 of the value (unlike the wrapped/prefixed forms above), so a
			// surviving comparator that quickSearchWords does not strip — the ≥/≤ glyphs
			// or an "in:"/"not:"/"phrase:" prefix — would otherwise be parsed as a
			// comparator or range instead of an exact match. Non-numeric words drop.
			'eq-number' => is_numeric( $word ) ? $word : null,
			default => null,
		};
	}

	/**
	 * Build one column's quick-search condition for a single word, wrapped in a
	 * SomeProperty. Null when the kind is unsearchable or the value does not parse for
	 * the column's datatype (a dropped ThingDescription).
	 */
	private function quickColumnDescription( string $propertyName, string $kind, string $word ): ?Description {
		$value = $this->quickSearchValue( $kind, $word );
		if ( $value === null ) {
			return null;
		}
		$property = DIProperty::newFromUserLabel( $propertyName );
		$dataValue = DataValueFactory::getInstance()->newDataValueByProperty( $property );
		$valueDesc = $dataValue->getQueryDescription( $value );
		if ( $valueDesc instanceof ThingDescription ) {
			return null;
		}
		return new SomeProperty( $property, $valueDesc );
	}

	/**
	 * Build the subject (page name) match for one word: a top-level [[~*word*]]
	 * substring condition. Null when SMW cannot build it.
	 */
	private function quickSubjectDescription( string $word ): ?Description {
		$dataValue = DataValueFactory::getInstance()->newDataValueByType( '_wpg' );
		$valueDesc = $dataValue->getQueryDescription( '~*' . $word . '*' );
		return $valueDesc instanceof ThingDescription ? null : $valueDesc;
	}

	// -------------------------------------------------------------------------
	// Per-column dispatcher
	// -------------------------------------------------------------------------

	/**
	 * Translate a single column's filter model to a Description.
	 *
	 * Handles both simple conditions and combined { operator, conditions } shapes.
	 *
	 * @param string $property Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $model
	 * @param string $family
	 * @return Description|null
	 */
	private function columnDescription( string $property, array $model, string $family ): ?Description {
		// Set filter: detected by presence of 'values' key
		if ( isset( $model['values'] ) ) {
			return $this->setDescription( $property, $model );
		}

		// Combined filter: has 'operator' and 'conditions'
		if ( isset( $model['operator'] ) && isset( $model['conditions'] ) ) {
			return $this->combinedDescription( $property, $model, $family );
		}

		// Simple filter
		return $this->simpleConditionDescription( $property, $model, $family );
	}

	/**
	 * Translate a combined { operator, conditions } filter model.
	 *
	 * @param string $property Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $model
	 * @param string $family
	 * @return Description|null
	 */
	private function combinedDescription( string $property, array $model, string $family ): ?Description {
		$parts = [];
		foreach ( $model['conditions'] as $condition ) {
			$desc = $this->simpleConditionDescription( $property, $condition, $family );
			if ( $desc !== null ) {
				$parts[] = $desc;
			}
		}
		if ( count( $parts ) === 0 ) {
			return null;
		}
		if ( count( $parts ) === 1 ) {
			return $parts[0];
		}
		if ( $model['operator'] === 'OR' ) {
			return new Disjunction( $parts );
		}
		return new Conjunction( $parts );
	}

	/**
	 * Dispatch a simple condition (no operator/conditions nesting) by family.
	 *
	 * @param string $property Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $condition
	 * @param string $family
	 * @return Description|null
	 */
	private function simpleConditionDescription(
		string $property,
		array $condition,
		string $family
	): ?Description {
		return match ( $family ) {
			'text', 'boolean' => $this->textDescription( $property, $condition ),
			'number' => $this->numberDescription( $property, $condition ),
			'date' => $this->dateDescription( $property, $condition ),
			default => null,
		};
	}

	// -------------------------------------------------------------------------
	// Family-specific description builders
	// -------------------------------------------------------------------------

	/**
	 * Build a description for a text (or boolean) filter condition.
	 *
	 * @param string $propertyName Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $condition
	 * @return Description|null
	 */
	private function textDescription( string $propertyName, array $condition ): ?Description {
		$type = $condition['type'] ?? '';
		$value = (string)( $condition['filter'] ?? '' );
		$property = DIProperty::newFromUserLabel( $propertyName );

		switch ( $type ) {
			case 'contains':
				return $this->buildValueDescription( $property, '*' . $value . '*', SMW_CMP_LIKE );
			case 'notContains':
				return $this->buildValueDescription( $property, '*' . $value . '*', SMW_CMP_NLKE );
			case 'startsWith':
				return $this->buildValueDescription( $property, $value . '*', SMW_CMP_LIKE );
			case 'endsWith':
				return $this->buildValueDescription( $property, '*' . $value, SMW_CMP_LIKE );
			case 'equals':
				return $this->buildValueDescription( $property, $value, SMW_CMP_EQ );
			case 'notEqual':
				return $this->buildValueDescription( $property, $value, SMW_CMP_NEQ );
			case 'notBlank':
				return new SomeProperty( $property, new ThingDescription() );
			case 'blank':
				// blank contributes no condition
				return null;
			default:
				return null;
		}
	}

	/**
	 * Build a description for a number filter condition.
	 *
	 * @param string $propertyName Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $condition
	 * @return Description|null
	 */
	private function numberDescription( string $propertyName, array $condition ): ?Description {
		$type = $condition['type'] ?? '';
		$property = DIProperty::newFromUserLabel( $propertyName );
		$rawValue = $condition['filter'] ?? null;

		if ( $type === 'inRange' ) {
			$rawTo = $condition['filterTo'] ?? null;
			$low = $rawValue !== null
				? $this->buildValueDescription( $property, (string)$rawValue, SMW_CMP_GEQ )
				: null;
			$high = $rawTo !== null
				? $this->buildValueDescription( $property, (string)$rawTo, SMW_CMP_LEQ )
				: null;
			if ( $low === null && $high === null ) {
				return null;
			}
			if ( $low === null ) {
				return $high;
			}
			if ( $high === null ) {
				return $low;
			}
			return new Conjunction( [ $low, $high ] );
		}

		if ( $type === 'notBlank' ) {
			return new SomeProperty( $property, new ThingDescription() );
		}
		if ( $type === 'blank' ) {
			return null;
		}

		$comparator = $this->numericComparator( $type );
		if ( $comparator === null || $rawValue === null ) {
			return null;
		}
		return $this->buildValueDescription( $property, (string)$rawValue, $comparator );
	}

	/**
	 * Build a description for a date filter condition.
	 *
	 * Date values come in 'dateFrom'/'dateTo' keys (not 'filter'/'filterTo').
	 *
	 * @param string $propertyName Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $condition
	 * @return Description|null
	 */
	private function dateDescription( string $propertyName, array $condition ): ?Description {
		$type = $condition['type'] ?? '';
		$property = DIProperty::newFromUserLabel( $propertyName );
		$dateFrom = $condition['dateFrom'] ?? null;

		if ( $type === 'inRange' ) {
			$dateTo = $condition['dateTo'] ?? null;
			$low = $dateFrom !== null
				? $this->buildValueDescription( $property, (string)$dateFrom, SMW_CMP_GEQ )
				: null;
			$high = $dateTo !== null
				? $this->buildValueDescription( $property, (string)$dateTo, SMW_CMP_LEQ )
				: null;
			if ( $low === null || $high === null ) {
				return null;
			}
			return new Conjunction( [ $low, $high ] );
		}

		if ( $type === 'notBlank' ) {
			return new SomeProperty( $property, new ThingDescription() );
		}
		if ( $type === 'blank' ) {
			return null;
		}

		$comparator = $this->dateComparator( $type );
		if ( $comparator === null || $dateFrom === null ) {
			return null;
		}
		return $this->buildValueDescription( $property, (string)$dateFrom, $comparator );
	}

	/**
	 * Build a description for a set filter condition.
	 *
	 * Include model ({ values }) → one property condition whose value is a
	 * Disjunction (value a OR b OR …), matching how SMW's own #ask parser shapes
	 * [[Prop::a||b||c]]. This is deliberate: a Disjunction of *separate* SomeProperty
	 * subqueries would instead drive SMW's temp-table disjunction path, which emits
	 * "INSERT IGNORE" — invalid SQL on SQLite — for page-valued properties.
	 *
	 * Exclude model ({ values, exclude: true }) → an AND of inequalities
	 * (not a AND not b AND …), each its own property condition so the conjunction
	 * path builds the SQL.
	 *
	 * The client sends whichever side (selected vs. unselected) is smaller to stay
	 * within SMW's query-size limit.
	 *
	 * @param string $propertyName Resolved SMW property name (not the AG Grid field).
	 * @param array<string,mixed> $model
	 * @return Description|null
	 */
	private function setDescription( string $propertyName, array $model ): ?Description {
		$values = $model['values'] ?? [];
		if ( count( $values ) === 0 ) {
			return null;
		}

		$property = DIProperty::newFromUserLabel( $propertyName );

		if ( !empty( $model['exclude'] ) ) {
			$parts = [];
			foreach ( $values as $value ) {
				$desc = $this->buildValueDescription( $property, (string)$value, SMW_CMP_NEQ );
				if ( $desc !== null ) {
					$parts[] = $desc;
				}
			}
			if ( count( $parts ) === 0 ) {
				return null;
			}
			return count( $parts ) === 1 ? $parts[0] : new Conjunction( $parts );
		}

		$valueParts = [];
		foreach ( $values as $value ) {
			$valueDesc = $this->buildInnerValueDescription( $property, (string)$value, SMW_CMP_EQ );
			if ( $valueDesc !== null ) {
				$valueParts[] = $valueDesc;
			}
		}
		if ( count( $valueParts ) === 0 ) {
			return null;
		}
		$inner = count( $valueParts ) === 1 ? $valueParts[0] : new Disjunction( $valueParts );
		return new SomeProperty( $property, $inner );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build a SomeProperty( ValueDescription ) pair, returning null if the
	 * user value fails to parse (so callers silently skip broken conditions).
	 *
	 * @param DIProperty $property
	 * @param string $userValue
	 * @param int $comparator One of the SMW_CMP_* constants.
	 * @return SomeProperty|null
	 */
	private function buildValueDescription(
		DIProperty $property,
		string $userValue,
		int $comparator
	): ?SomeProperty {
		$valueDesc = $this->buildInnerValueDescription( $property, $userValue, $comparator );
		if ( $valueDesc === null ) {
			return null;
		}
		return new SomeProperty( $property, $valueDesc );
	}

	/**
	 * Build a bare ValueDescription for a property/value/comparator, returning null
	 * if the user value fails to parse. Unlike buildValueDescription() this does not
	 * wrap the result in a SomeProperty, so several can be combined under a single
	 * SomeProperty (e.g. a disjunctive set-filter value, [[Prop::a||b||c]]).
	 *
	 * @param DIProperty $property
	 * @param string $userValue
	 * @param int $comparator One of the SMW_CMP_* constants.
	 * @return ValueDescription|null
	 */
	private function buildInnerValueDescription(
		DIProperty $property,
		string $userValue,
		int $comparator
	): ?ValueDescription {
		$dataValue = DataValueFactory::getInstance()->newDataValueByProperty( $property, $userValue );
		if ( !$dataValue->isValid() ) {
			return null;
		}
		return new ValueDescription( $dataValue->getDataItem(), $property, $comparator );
	}

	/**
	 * Map AG Grid number filter type string to an SMW comparator constant.
	 *
	 * @param string $type
	 * @return int|null SMW_CMP_* constant, or null for unknown types.
	 */
	private function numericComparator( string $type ): ?int {
		return match ( $type ) {
			'equals' => SMW_CMP_EQ,
			'notEqual' => SMW_CMP_NEQ,
			'greaterThan' => SMW_CMP_GRTR,
			'greaterThanOrEqual' => SMW_CMP_GEQ,
			'lessThan' => SMW_CMP_LESS,
			'lessThanOrEqual' => SMW_CMP_LEQ,
			default => null,
		};
	}

	/**
	 * Map AG Grid date filter type string to an SMW comparator constant.
	 *
	 * @param string $type
	 * @return int|null SMW_CMP_* constant, or null for unknown types.
	 */
	private function dateComparator( string $type ): ?int {
		return match ( $type ) {
			'equals' => SMW_CMP_EQ,
			'notEqual' => SMW_CMP_NEQ,
			'greaterThan' => SMW_CMP_GRTR,
			'greaterThanOrEqual' => SMW_CMP_GEQ,
			'lessThan' => SMW_CMP_LESS,
			'lessThanOrEqual' => SMW_CMP_LEQ,
			default => null,
		};
	}
}
