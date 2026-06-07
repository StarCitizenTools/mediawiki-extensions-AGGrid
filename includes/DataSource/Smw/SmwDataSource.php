<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\DataSource\GridPage;
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
class SmwDataSource implements BackendDataSource {

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
	 * both for our query only (see runQuery()).
	 */
	private const QUERY_MAX_SIZE = 5000;
	private const QUERY_MAX_DEPTH = 100;

	public function __construct(
		private readonly Store $store,
		private readonly SourceSpecStore $specStore,
		private readonly FilterTranslator $filterTranslator,
		private readonly TypeColumnMapper $typeColumnMapper,
		private readonly int $cacheMaxAge,
		private readonly int $maxValues
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function getPage(
		int $pageId,
		int $gridIndex,
		int $offset,
		array $sortModel,
		array $filterModel,
		int $size
	): GridPage {
		$spec = $this->resolveSpec( $pageId, $gridIndex );

		// A printout column field may be an alias for a different SMW property; map
		// the AG Grid field (colId / filterModel key) to the real property before any
		// SMW resolution, so aliased columns sort/filter on the property, not the alias.
		$propertyOf = $this->propertyResolver( $spec );

		return $this->withRaisedLimits( function () use (
			$spec, $propertyOf, $offset, $size, $sortModel, $filterModel
		): GridPage {
			$query = $this->buildQuery( $spec, $spec['printouts'] ?? [] );
			$query->setOffset( $offset );
			$query->setLimit( $size );
			$query->setSortKeys( $this->filterTranslator->toSortKeys( $sortModel, $propertyOf ) );

			$filterDesc = $this->buildFilterDescription( $filterModel, $propertyOf );
			if ( $filterDesc !== null ) {
				$query->setDescription( new Conjunction( [ $query->getDescription(), $filterDesc ] ) );
			}

			$result = $this->store->getQueryResult( $query );
			$this->assertNoErrors( $result );

			$rows = [];
			$resultRow = $result->getNext();
			while ( $resultRow !== false ) {
				$rows[] = $this->mapRow( $resultRow );
				$resultRow = $result->getNext();
			}

			$total = $this->countFor( $spec, $filterModel );

			return new GridPage( $rows, $total );
		} );
	}

	/**
	 * @inheritDoc
	 */
	public function getColumnValues( int $pageId, int $gridIndex, string $column ): array {
		$spec = $this->resolveSpec( $pageId, $gridIndex );

		// $column is the AG Grid field (possibly an alias); project the real property.
		$prop = $spec['fields'][$column] ?? $column;

		return $this->withRaisedLimits( function () use ( $spec, $prop ): array {
			$query = $this->buildQuery( $spec, [ $prop ] );
			$query->setLimit( $this->maxValues );

			$result = $this->store->getQueryResult( $query );
			$this->assertNoErrors( $result );

			$values = [];
			$rowCount = 0;
			$resultRow = $result->getNext();
			while ( $resultRow !== false ) {
				$rowCount++;
				foreach ( $resultRow as $resultArray ) {
					if ( $resultArray->getPrintRequest()->getMode() === PrintRequest::PRINT_THIS ) {
						continue;
					}
					$cell = $this->firstCellValue( $resultArray );
					if ( $cell === null ) {
						continue;
					}
					$label = is_array( $cell ) ? $cell['text'] : $cell;
					if ( $label === '' ) {
						continue;
					}
					$values[$label] = [ 'key' => $label, 'label' => $label ];
				}
				$resultRow = $result->getNext();
			}

			return [
				'values' => array_values( $values ),
				'partial' => $rowCount >= $this->maxValues,
			];
		} );
	}

	/**
	 * @inheritDoc
	 */
	public function getCachePolicy(): CachePolicy {
		// The PUBLIC flag is decided by the REST handler from anon-readability;
		// the source only supplies the configured maxAge default.
		return new CachePolicy( false, $this->cacheMaxAge );
	}

	// -------------------------------------------------------------------------
	// Internals
	// -------------------------------------------------------------------------

	/**
	 * Run a count-mode query for the total number of rows matching the spec query
	 * plus the active filter, independent of the current page's offset/limit/sort.
	 *
	 * SMW serves a total only in MODE_COUNT, where its QueryEngine emits a
	 * COUNT(DISTINCT …) and exposes it via QueryResult::getCountValue(). The count
	 * is bounded by the query's limit, so we raise it to SMW's configured maximum
	 * (smwgQMaxLimit/smwgQMaxInlineLimit) to report a realistic total.
	 *
	 * Called only from within getPage()'s withRaisedLimits() scope, so its
	 * setDescription() prune sees the raised structural limits.
	 *
	 * @param array{query: string, printouts?: string[], mainlabel?: ?string, fields?: array<string,string>} $spec
	 * @param array<string, array<string,mixed>> $filterModel
	 */
	private function countFor( array $spec, array $filterModel ): int {
		$countQuery = $this->buildQuery( $spec, [] );

		$filterDesc = $this->buildFilterDescription( $filterModel, $this->propertyResolver( $spec ) );
		if ( $filterDesc !== null ) {
			$countQuery->setDescription(
				new Conjunction( [ $countQuery->getDescription(), $filterDesc ] )
			);
		}

		$maxLimit = (int)( $GLOBALS['smwgQMaxLimit'] ?? 10000 );
		$maxInlineLimit = (int)( $GLOBALS['smwgQMaxInlineLimit'] ?? 5000 );
		$countQuery->setLimit( max( 1, min( $maxLimit, $maxInlineLimit ) ) );
		$countQuery->setQueryMode( Query::MODE_COUNT );

		$result = $this->store->getQueryResult( $countQuery );
		$this->assertNoErrors( $result );

		return (int)$result->getCountValue();
	}

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
	 * Build a field->property resolver backed by the spec's stored alias map.
	 * Falls back to the field itself when no mapping exists (field == property).
	 *
	 * @param array{fields?: array<string,string>} $spec
	 * @return callable Callable( string $field ): string
	 */
	private function propertyResolver( array $spec ): callable {
		$fields = $spec['fields'] ?? [];
		return static fn ( string $field ): string => $fields[$field] ?? $field;
	}

	/**
	 * Resolve and validate the stored source spec for a grid.
	 *
	 * @return array{query: string, printouts?: string[], mainlabel?: ?string, fields?: array<string,string>}
	 */
	private function resolveSpec( int $pageId, int $gridIndex ): array {
		$source = $this->specStore->getSource( $pageId, $gridIndex );
		if ( $source === null || !isset( $source['spec']['query'] ) ) {
			throw new RuntimeException(
				"AGGrid: no SMW source spec for page $pageId grid $gridIndex"
			);
		}
		return $source['spec'];
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
	 * @param array<int, ResultArray> $resultRow
	 * @return array<string, mixed>
	 */
	private function mapRow( array $resultRow ): array {
		$row = [];

		foreach ( $resultRow as $resultArray ) {
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
