<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\AbstractFilterModelWalker;
use MediaWiki\Extension\AGGrid\DataSource\FilterOperator;

/**
 * Translates AG Grid sort/filter models into Bucket query-input structures.
 *
 * - toOperands() maps a filterModel (keyed by colId) to a list of Bucket `where`
 *   operands that the data source ANDs onto the base query. Each operand is either a
 *   comparison `[ field, op, value ]` or a group `[ 'op' => 'AND'|'OR', 'operands' => […] ]`,
 *   matching the structure {@see \MediaWiki\Extension\Bucket\BucketQuery} parses.
 * - toOrderBy() maps a sortModel to Bucket's single-column `{ fieldName, direction }`.
 *
 * Bucket's query language has only the comparison operators `= != >= <= > <` (no LIKE),
 * so this translator emits only those. Set filters are always wrapped in an OR (include)
 * or AND (exclude) group — never collapsed to a bare condition — because Bucket routes a
 * repeated-field condition through its subquery path only when it sits inside such a
 * group. NULL is expressed with the '&&NULL&&' sentinel that BucketQuery converts to a
 * real NULL.
 */
class BucketFilterTranslator extends AbstractFilterModelWalker {

	/** Sentinel BucketQuery converts to a real NULL value (valid with =/!= only). */
	private const NULL_SENTINEL = '&&NULL&&';

	/**
	 * Convert an AG Grid filterModel to a list of Bucket `where` operands.
	 *
	 * @param array<string, array<string,mixed>> $filterModel Keyed by AG Grid colId.
	 * @param callable $selectOf Callable( string $colId ): string — colId to Bucket select string.
	 * @param callable $familyOf Callable( string $colId ): string — colId to filter family
	 *   ('set'|'number'|'none').
	 * @return array<int, array> Operands to AND onto the base query (possibly empty).
	 */
	public function toOperands( array $filterModel, callable $selectOf, callable $familyOf ): array {
		$operands = [];
		foreach ( $filterModel as $colId => $model ) {
			$family = $familyOf( $colId );
			if ( $family === 'none' ) {
				continue;
			}
			/** @var array|null $operand */
			$operand = $this->walkColumn( $selectOf( $colId ), $model, $family );
			if ( $operand !== null ) {
				$operands[] = $operand;
			}
		}
		return $operands;
	}

	/**
	 * Convert an AG Grid sortModel to Bucket's single-column orderBy, or null when no
	 * sort is active. Bucket's orderBy is a single field, so only the first sort entry
	 * is used; Bucket appends _page_id/_index tiebreakers itself for stable paging.
	 *
	 * @param array<int, array<string,string>> $sortModel
	 * @param callable $selectOf Callable( string $colId ): string.
	 * @return array{fieldName: string, direction: string}|null
	 */
	public function toOrderBy( array $sortModel, callable $selectOf ): ?array {
		if ( $sortModel === [] ) {
			return null;
		}
		$first = $sortModel[0];
		return [
			'fieldName' => $selectOf( $first['colId'] ),
			'direction' => self::SORT_DIR[$first['sort'] ?? 'asc'] ?? 'ASC',
		];
	}

	// -------------------------------------------------------------------------
	// Leaf hooks (the base owns the set/combined/simple dispatch and combined descent)
	// -------------------------------------------------------------------------

	/**
	 * @inheritDoc
	 *
	 * Include -> OR of equalities; exclude -> AND of inequalities. ALWAYS wrapped in a
	 * group (never collapsed, even for a single value) so repeated-field conditions route
	 * through Bucket's subquery path.
	 *
	 * @param string $col Bucket select string (possibly qualified, e.g. 'skill.category').
	 * @param array<string,mixed> $model
	 */
	protected function emitSet( string $col, array $model ): ?array {
		$values = $model['values'] ?? [];
		if ( count( $values ) === 0 ) {
			return null;
		}
		$exclude = !empty( $model['exclude'] );
		$op = $exclude ? '!=' : '=';
		$operands = [];
		foreach ( $values as $value ) {
			$operands[] = [ $col, $op, (string)$value ];
		}
		return [ 'op' => $exclude ? 'AND' : 'OR', 'operands' => $operands ];
	}

	/**
	 * @inheritDoc
	 *
	 * Only the number family is expressible in Bucket (no LIKE/date); other families
	 * yield no operand.
	 *
	 * @param string $col Bucket select string.
	 * @param array<string,mixed> $condition
	 */
	protected function emitSimple( string $col, array $condition, string $family ): ?array {
		return match ( $family ) {
			'number' => $this->numberOperand( $col, $condition ),
			default => null,
		};
	}

	/**
	 * @inheritDoc
	 *
	 * @param array $parts
	 * @return array
	 */
	protected function combine( string $operator, array $parts ): array {
		return [ 'op' => $operator, 'operands' => $parts ];
	}

	/**
	 * Build a number filter operand.
	 *
	 * @param string $select
	 * @param array<string,mixed> $condition
	 */
	private function numberOperand( string $select, array $condition ): ?array {
		$type = $condition['type'] ?? '';

		if ( $type === 'inRange' ) {
			$from = $condition['filter'] ?? null;
			$to = $condition['filterTo'] ?? null;
			$low = $from !== null ? [ $select, '>=', $from ] : null;
			$high = $to !== null ? [ $select, '<=', $to ] : null;
			if ( $low === null && $high === null ) {
				return null;
			}
			if ( $low === null ) {
				return $high;
			}
			if ( $high === null ) {
				return $low;
			}
			return [ 'op' => 'AND', 'operands' => [ $low, $high ] ];
		}

		if ( $type === 'blank' ) {
			return [ $select, '=', self::NULL_SENTINEL ];
		}
		if ( $type === 'notBlank' ) {
			return [ $select, '!=', self::NULL_SENTINEL ];
		}

		$op = $this->operator( $type );
		$value = $condition['filter'] ?? null;
		if ( $op === null || $value === null ) {
			return null;
		}
		return [ $select, $op, $value ];
	}

	/**
	 * Map an AG Grid number filter type to a Bucket comparison operator via the shared
	 * {@see FilterOperator} table. Null for a type with no comparison operator.
	 */
	private function operator( string $type ): ?string {
		return match ( $this->operatorFor( $type ) ) {
			FilterOperator::Equals => '=',
			FilterOperator::NotEqual => '!=',
			FilterOperator::GreaterThan => '>',
			FilterOperator::GreaterThanOrEqual => '>=',
			FilterOperator::LessThan => '<',
			FilterOperator::LessThanOrEqual => '<=',
			null => null,
		};
	}
}
