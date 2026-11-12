<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use MediaWiki\Extension\AGGrid\DataSource\AbstractBackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use RuntimeException;
use SMW\DataValues\URIValue;
use SMW\DataValues\WikiPageValue;
use SMW\DIProperty;
use SMW\DIWikiPage;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Description;
use SMW\Query\PrintRequest;
use SMW\Query\Query;
use SMW\Query\QueryProcessor;
use SMW\Query\QueryResult;
use SMW\Query\Result\ResultArray;
use SMW\Store;
use SMWDataValue as DataValue;

/**
 * Backend data source backed by Semantic MediaWiki.
 *
 * Runs the stored #ask query with offset/limit pagination, mapping each
 * result row into an AG Grid row keyed by column field:
 *  - the subject (page) under the reserved key '_subject' as a link cell;
 *  - each printout under its print request label, as a link cell for
 *    page/uri values or a scalar string otherwise.
 *
 * A second count-mode query supplies the total matching count so the client can
 * render AG Grid's native pagination bar. Deep-page offsets are bounded by SMW's
 * configured query upper bound.
 */
class SmwDataSource extends AbstractBackendDataSource {

	/** Reserved row key carrying the subject (page) link cell. */
	private const SUBJECT_KEY = '_subject';

	/**
	 * Structural query bounds applied while running our stored query.
	 *
	 * SMW's default $smwgQMaxSize (16) / $smwgQMaxDepth (4) protect the wiki from
	 * expensive user-authored #ask queries. Our query is stored server-side and its
	 * filters are built from a controlled column model, so that rationale does not
	 * apply — but a set filter excluding many page-property values expands into one
	 * subquery per value, each costing size and depth, and trips the defaults. Raise
	 * both for our query only (see withRaisedLimits()).
	 */
	private const QUERY_MAX_SIZE = 5000;
	private const QUERY_MAX_DEPTH = 100;

	public function __construct(
		private readonly Store $store,
		SourceSpecStore $specStore,
		private readonly FilterTranslator $filterTranslator,
		private readonly TypeColumnMapper $typeColumnMapper,
		CachePolicy $cachePolicy,
		int $maxValues
	) {
		parent::__construct( $specStore, $cachePolicy, $maxValues );
	}

	/** @inheritDoc */
	protected function specSentinelKey(): string {
		return 'query';
	}

	/** @inheritDoc */
	protected function backendName(): string {
		return 'SMW';
	}

	/**
	 * @inheritDoc
	 *
	 * Materialises the rows eagerly inside withRaisedLimits(): the structural-limit
	 * raise must span setDescription() (which prunes against the globals), so the whole
	 * query lifecycle — including draining the result — happens within that scope. mapRow
	 * runs afterward in the base and only reads already-fetched values.
	 */
	protected function executeQuery(
		array $spec,
		int $offset,
		int $size,
		array $sortModel,
		array $filterModel,
		string $quickSearch
	): iterable {
		// Two resolver views over the declared columns: sorting follows the column's
		// display property; filtering follows its facet when one is declared. Both
		// are closed — an undeclared field throws (-> 400 in the REST handlers).
		$displayOf = $this->displayPropertyResolver( $spec );
		$filterOf = $this->filterPropertyResolver( $spec );

		return $this->withRaisedLimits( function () use (
			$spec, $displayOf, $filterOf, $offset, $size, $sortModel, $filterModel, $quickSearch
		): array {
			$query = $this->buildQuery( $spec, $spec['printouts'] ?? [] );
			$query->setOffset( $offset );
			$query->setLimit( $size );
			$query->setSortKeys( $this->filterTranslator->toSortKeys( $sortModel, $displayOf ) );

			// Column filters and the free-text quick search are independent conditions
			// ANDed onto the base query (and onto the count query, identically).
			$conditions = array_filter( [
				$this->buildFilterDescription( $filterModel, $filterOf ),
				$this->buildQuickSearchDescription( $spec, $quickSearch, $displayOf ),
			] );
			if ( $conditions !== [] ) {
				$query->setDescription(
					new Conjunction( array_merge( [ $query->getDescription() ], $conditions ) )
				);
			}

			$result = $this->store->getQueryResult( $query );
			$this->assertNoErrors( $result );

			$rows = [];
			$resultRow = $result->getNext();
			while ( $resultRow !== false ) {
				$rows[] = $resultRow;
				$resultRow = $result->getNext();
			}
			return $rows;
		} );
	}

	/**
	 * @inheritDoc
	 *
	 * SMW serves a total only in MODE_COUNT, where its QueryEngine emits a
	 * COUNT(DISTINCT …) and exposes it via QueryResult::getCountValue(). The count is
	 * bounded by the query's limit, so we raise it to SMW's configured maximum
	 * (smwgQMaxLimit/smwgQMaxInlineLimit) to report a realistic total. Wrapped in its own
	 * withRaisedLimits() so its setDescription() prune sees the raised structural limits.
	 */
	protected function countTotal( array $spec, array $filterModel, string $quickSearch ): int {
		return $this->withRaisedLimits( function () use ( $spec, $filterModel, $quickSearch ): int {
			$countQuery = $this->buildQuery( $spec, [] );

			// Mirror executeQuery exactly: AND the same column-filter and quick-search
			// conditions so the reported total matches the rows actually returned.
			$conditions = array_filter( [
				$this->buildFilterDescription( $filterModel, $this->filterPropertyResolver( $spec ) ),
				$this->buildQuickSearchDescription( $spec, $quickSearch, $this->displayPropertyResolver( $spec ) ),
			] );
			if ( $conditions !== [] ) {
				$countQuery->setDescription(
					new Conjunction( array_merge( [ $countQuery->getDescription() ], $conditions ) )
				);
			}

			$maxLimit = (int)( $GLOBALS['smwgQMaxLimit'] ?? 10000 );
			$maxInlineLimit = (int)( $GLOBALS['smwgQMaxInlineLimit'] ?? 5000 );
			$countQuery->setLimit( max( 1, min( $maxLimit, $maxInlineLimit ) ) );
			$countQuery->setQueryMode( Query::MODE_COUNT );

			$result = $this->store->getQueryResult( $countQuery );
			$this->assertNoErrors( $result );

			return (int)$result->getCountValue();
		} );
	}

	/**
	 * @inheritDoc
	 */
	protected function fetchColumnValueRows( array $spec, string $column, int $maxValues ): array {
		// $column is the AG Grid field; filtering resolves through the facet when one is
		// declared, so the value list enumerates the same property the filter conditions
		// will run against. Undeclared columns throw (-> 400).
		$prop = $this->filterPropertyResolver( $spec )( $column );

		return $this->withRaisedLimits( function () use ( $spec, $prop, $maxValues ): array {
			$query = $this->buildQuery( $spec, [ $prop ] );
			$query->setLimit( $maxValues );

			$result = $this->store->getQueryResult( $query );
			$this->assertNoErrors( $result );

			$entries = [];
			$rowCount = 0;
			$resultRow = $result->getNext();
			while ( $resultRow !== false ) {
				$rowCount++;
				foreach ( $resultRow as $resultArray ) {
					if ( $resultArray->getPrintRequest()->getMode() === PrintRequest::PRINT_THIS ) {
						continue;
					}
					foreach ( $this->cellValues( $resultArray ) as $cell ) {
						$label = is_array( $cell ) ? $cell['text'] : $cell;
						if ( $label === '' ) {
							continue;
						}
						$entries[] = [ 'key' => $label, 'label' => $label ];
					}
				}
				$resultRow = $result->getNext();
			}

			return [ 'entries' => $entries, 'rowCount' => $rowCount ];
		} );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Build the filter Description for a filterModel, resolving each field's family
	 * via its SMW property type. Returns null when no filter is active.
	 *
	 * The $propertyOf resolver maps an AG Grid column field (possibly an alias) to
	 * its real SMW property; family lookup and the Description both use the resolved
	 * property so aliased columns filter on the property, not the alias.
	 *
	 * @param array<string, array<string,mixed>> $filterModel
	 * @param callable $propertyOf Callable( string $field ): string
	 */
	private function buildFilterDescription( array $filterModel, callable $propertyOf ): ?Description {
		return $this->filterTranslator->toDescription(
			$filterModel,
			fn ( string $field ): string => $this->typeColumnMapper->filterFamily(
				$this->propertyType( $propertyOf( $field ) )
			),
			$propertyOf
		);
	}

	/**
	 * Build the quick-search Description for a free-text term over the spec's display
	 * columns plus the subject, or null when the term is blank.
	 *
	 * Quick search matches what the user sees, so it resolves and classifies each
	 * column by its DISPLAY property (not its filter facet, unlike column filters).
	 * The subject (page name) is searched unless the grid suppresses it (mainlabel '-').
	 *
	 * @phpcs:ignore Generic.Files.LineLength
	 * @param array{printouts?: string[], fields?: array<string,string>, mainlabel?: ?string} $spec
	 * @param string $quickSearch
	 * @param callable $displayOf Callable( string $field ): string
	 */
	private function buildQuickSearchDescription(
		array $spec,
		string $quickSearch,
		callable $displayOf
	): ?Description {
		if ( trim( $quickSearch ) === '' ) {
			return null;
		}
		return $this->filterTranslator->quickSearchDescription(
			$quickSearch,
			array_keys( $this->declaredFields( $spec ) ),
			$displayOf,
			fn ( string $field ): ?string => $this->typeColumnMapper->searchKind(
				$this->propertyType( $displayOf( $field ) )
			),
			( $spec['mainlabel'] ?? null ) !== '-'
		);
	}

	/**
	 * Build a field -> display-property resolver over the spec's declared columns.
	 *
	 * Resolution is closed: clients name columns only by colId/field, and only
	 * author-declared printouts resolve. An undeclared field throws, which the
	 * REST handlers surface as a 400 — the previous fall-through (treat the
	 * request string as a property name) let a client list values of, filter on,
	 * and sort by ANY wiki property, gated only by page read permission.
	 *
	 * @param array{printouts?: string[], fields?: array<string,string>} $spec
	 * @return callable Callable( string $field ): string
	 */
	private function displayPropertyResolver( array $spec ): callable {
		$fields = $this->declaredFields( $spec );
		return static function ( string $field ) use ( $fields ): string {
			if ( !isset( $fields[$field] ) ) {
				throw new RuntimeException( "AGGrid: undeclared column \"$field\"" );
			}
			return $fields[$field];
		};
	}

	/**
	 * Build a field -> property resolver for FILTERING: a declared filter facet
	 * overrides the display property, so the filter (value list and conditions)
	 * operates on the facet while sort and display stay on the display property.
	 *
	 * @param array{printouts?: string[], fields?: array<string,string>, facets?: array<string,string>} $spec
	 * @return callable Callable( string $field ): string
	 */
	private function filterPropertyResolver( array $spec ): callable {
		$displayOf = $this->displayPropertyResolver( $spec );
		$facets = $spec['facets'] ?? [];
		return static function ( string $field ) use ( $displayOf, $facets ): string {
			// Resolve the display property first: it validates the declaration
			// even when a facet overrides the result.
			$prop = $displayOf( $field );
			return $facets[$field] ?? $prop;
		};
	}

	/**
	 * field -> display property for every declared printout.
	 *
	 * Prefers the stored fields map; for specs lacking one, defensively derives
	 * the same mapping renderSource would have stored from the canonical
	 * printout strings ('Prop' or 'Prop=Label').
	 *
	 * @param array{printouts?: string[], fields?: array<string,string>} $spec
	 * @return array<string,string>
	 */
	private function declaredFields( array $spec ): array {
		$fields = $spec['fields'] ?? [];
		if ( $fields !== [] ) {
			return $fields;
		}
		foreach ( $spec['printouts'] ?? [] as $printout ) {
			if ( str_contains( $printout, '=' ) ) {
				[ $prop, $label ] = explode( '=', $printout, 2 );
				$fields[$label] = $prop;
			} else {
				$fields[$printout] = $printout;
			}
		}
		return $fields;
	}

	/**
	 * Build a base Query from the spec query string plus the given printout labels.
	 *
	 * @param array{query: string, mainlabel?: ?string} $spec
	 * @param string[] $printouts
	 */
	private function buildQuery( array $spec, array $printouts ): Query {
		$rawParams = array_merge(
			[ $spec['query'] ],
			array_map( static fn ( string $p ): string => "?$p", $printouts )
		);
		if ( isset( $spec['mainlabel'] ) && $spec['mainlabel'] !== null ) {
			$rawParams[] = 'mainlabel=' . $spec['mainlabel'];
		}

		[ $query ] = QueryProcessor::getQueryAndParamsFromFunctionParams(
			$rawParams,
			SMW_OUTPUT_WIKI,
			QueryProcessor::INLINE_QUERY,
			false
		);

		return $query;
	}

	/**
	 * Map one SMW result row (array of ResultArray) into an AG Grid row.
	 *
	 * @param array<int, ResultArray> $rawRow
	 * @param array $spec Unused; the SMW row carries its own print requests.
	 * @return array<string, mixed>
	 */
	protected function mapRow( array $rawRow, array $spec ): array {
		$row = [];

		foreach ( $rawRow as $resultArray ) {
			$printRequest = $resultArray->getPrintRequest();

			if ( $printRequest->getMode() === PrintRequest::PRINT_THIS ) {
				$subject = $resultArray->getResultSubject();
				$title = $subject->getTitle();
				if ( $title !== null ) {
					$row[self::SUBJECT_KEY] = [
						'text' => $title->getText(),
						'href' => $title->getLocalURL(),
					];
				}
				continue;
			}

			$label = $printRequest->getLabel();
			if ( $label === '' ) {
				continue;
			}
			$cell = $this->firstCellValue( $resultArray );
			if ( $cell !== null ) {
				$row[$label] = $cell;
			}
		}

		return $row;
	}

	/**
	 * Read the first DataValue of a ResultArray and map it to a cell value.
	 *
	 * Returns a link cell [ 'text', 'href' ] for page/uri values, a scalar
	 * string for other values, or null when the cell is empty.
	 *
	 * @return array{text: string, href: string}|string|null
	 */
	private function firstCellValue( ResultArray $resultArray ) {
		$dataValue = $resultArray->getNextDataValue();
		if ( $dataValue === false ) {
			return null;
		}
		return $this->cellFromDataValue( $dataValue );
	}

	/**
	 * Read every DataValue of a ResultArray, mapped to cell values.
	 *
	 * The set-filter value list must enumerate ALL of a row's values for the
	 * property: an SMW SomeProperty condition matches a row when ANY value
	 * matches, so a list built from first values only offers entries that cannot
	 * be coherently deselected on multi-valued properties. Row cells (mapRow)
	 * intentionally keep first-value-only display. Values per row are
	 * intentionally uncapped; maxValues/partial stay row-based.
	 *
	 * @return array<int, array{text: string, href: string}|string>
	 */
	private function cellValues( ResultArray $resultArray ): array {
		$cells = [];
		$dataValue = $resultArray->getNextDataValue();
		while ( $dataValue !== false ) {
			$cells[] = $this->cellFromDataValue( $dataValue );
			$dataValue = $resultArray->getNextDataValue();
		}
		return $cells;
	}

	/**
	 * @return array{text: string, href: string}|string
	 */
	private function cellFromDataValue( DataValue $dataValue ) {
		$item = $dataValue->getDataItem();

		if ( $item instanceof DIWikiPage ) {
			$title = $item->getTitle();
			$text = $title !== null ? $title->getText() : $dataValue->getShortText( SMW_OUTPUT_RAW );
			$href = $title !== null ? $title->getLocalURL() : '';
			return [ 'text' => $text, 'href' => $href ];
		}

		if ( $dataValue instanceof WikiPageValue ) {
			$title = $dataValue->getTitle();
			if ( $title !== null ) {
				return [ 'text' => $title->getText(), 'href' => $title->getLocalURL() ];
			}
		}

		if ( $dataValue instanceof URIValue ) {
			return [
				'text' => $this->plainText( $dataValue ),
				'href' => $dataValue->getURI(),
			];
		}

		return $this->plainText( $dataValue );
	}

	/**
	 * Plain display text for a scalar value.
	 *
	 * Uses the wikitext serialization rather than getShortText(): the HTML form
	 * wraps some types in markup (a Date renders as "<time datetime=…>…</time>"),
	 * which the client — building cells as text nodes — would show literally. The
	 * wikitext form still emits HTML entities for a few types (a Quantity is
	 * "14&#160;kg"), so decode those to plain text.
	 */
	private function plainText( DataValue $dataValue ): string {
		return html_entity_decode(
			$dataValue->getShortWikiText(),
			ENT_QUOTES | ENT_HTML5,
			'UTF-8'
		);
	}

	/**
	 * Resolve the SMW value type id for a property name, falling back to the empty
	 * string (which TypeColumnMapper treats as the default family) when the property
	 * label cannot be resolved.
	 */
	private function propertyType( string $propertyName ): string {
		try {
			$property = DIProperty::newFromUserLabel( $propertyName );
		} catch ( RuntimeException ) {
			return '';
		}
		return $property->findPropertyValueType();
	}

	/**
	 * Run a callback with raised structural query limits.
	 *
	 * Temporarily lifts $smwgQMaxSize / $smwgQMaxDepth to our bounds (see the
	 * QUERY_MAX_* constants), restoring the prior globals afterward so unrelated
	 * #ask queries on the same request keep their normal ceilings.
	 *
	 * The raise must span Query::setDescription(), not just getQueryResult():
	 * setDescription() calls Query::applyRestrictions() immediately, which prunes
	 * the description against the globals as they stand at that moment. A set filter
	 * excluding many page-property values expands into one subquery per value, each
	 * costing size and depth, so the prune would trip the defaults before the query
	 * ever runs.
	 *
	 * @param callable $fn Callable(): T
	 * @return mixed The callback's return value.
	 */
	private function withRaisedLimits( callable $fn ) {
		$prevSize = $GLOBALS['smwgQMaxSize'];
		$prevDepth = $GLOBALS['smwgQMaxDepth'];
		$GLOBALS['smwgQMaxSize'] = self::QUERY_MAX_SIZE;
		$GLOBALS['smwgQMaxDepth'] = self::QUERY_MAX_DEPTH;
		try {
			return $fn();
		} finally {
			$GLOBALS['smwgQMaxSize'] = $prevSize;
			$GLOBALS['smwgQMaxDepth'] = $prevDepth;
		}
	}

	/**
	 * Throw if the query result carries SMW errors.
	 */
	private function assertNoErrors( QueryResult $result ): void {
		$errors = $result->getErrors();
		if ( $errors !== [] ) {
			throw new SmwQueryException( 'AGGrid SMW query error: ' . implode( '; ', $errors ) );
		}
	}
}
