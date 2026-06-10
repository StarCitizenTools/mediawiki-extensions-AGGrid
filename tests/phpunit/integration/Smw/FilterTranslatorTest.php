<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Integration\Smw;

use MediaWiki\Extension\AGGrid\DataSource\Smw\FilterTranslator;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWikiIntegrationTestCase;
use SMW\Query\Language\Conjunction;
use SMW\Query\Language\Disjunction;
use SMW\Query\Language\SomeProperty;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Smw\FilterTranslator
 * @group Database
 */
class FilterTranslatorTest extends MediaWikiIntegrationTestCase {

	private FilterTranslator $translator;

	protected function setUp(): void {
		parent::setUp();
		if ( !ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
			$this->markTestSkipped( 'Semantic MediaWiki is not available' );
		}
		$this->translator = new FilterTranslator();
	}

	// -------------------------------------------------------------------------
	// toSortKeys()
	// -------------------------------------------------------------------------

	public function testToSortKeysBasic(): void {
		// The subject ('' key) is appended as a stable tiebreaker for offset paging.
		$result = $this->translator->toSortKeys( [
			[ 'colId' => 'Population', 'sort' => 'desc' ],
		] );
		$this->assertSame( [ 'Population' => 'DESC', '' => 'ASC' ], $result );
	}

	public function testToSortKeysMultipleColumns(): void {
		$result = $this->translator->toSortKeys( [
			[ 'colId' => 'Country', 'sort' => 'asc' ],
			[ 'colId' => 'Population', 'sort' => 'desc' ],
		] );
		$this->assertSame(
			[ 'Country' => 'ASC', 'Population' => 'DESC', '' => 'ASC' ],
			$result
		);
	}

	public function testToSortKeysSubjectColumnMapsToEmptyKey(): void {
		// The reserved subject column field '_subject' maps to SMW's '' sort key.
		// Because the user already sorts by the subject, no extra tiebreaker is added.
		$result = $this->translator->toSortKeys( [
			[ 'colId' => '_subject', 'sort' => 'desc' ],
			[ 'colId' => 'Name', 'sort' => 'asc' ],
		] );
		$this->assertSame( [ '' => 'DESC', 'Name' => 'ASC' ], $result );
	}

	public function testToSortKeysEmptyDefaultsToSubject(): void {
		$result = $this->translator->toSortKeys( [] );
		$this->assertSame( [ '' => 'ASC' ], $result );
	}

	public function testToSortKeysNormalizesPropertyKeyToDbKey(): void {
		// A multi-word property must be normalized to SMW's sort key (DBkey form,
		// spaces -> underscores) or SMW silently ignores the sort.
		$result = $this->translator->toSortKeys( [
			[ 'colId' => 'Maximum weight', 'sort' => 'desc' ],
		] );
		$this->assertSame( [ 'Maximum_weight' => 'DESC', '' => 'ASC' ], $result );
	}

	public function testToSortKeysResolvesAliasedColumnToProperty(): void {
		// An aliased printout's colId is the alias ('Pop'); the resolver maps it to
		// the real property ('Has population') so the sort key is the property DBkey.
		$propertyOf = static fn ( string $field ): string => [ 'Pop' => 'Has population' ][$field] ?? $field;
		$result = $this->translator->toSortKeys(
			[ [ 'colId' => 'Pop', 'sort' => 'desc' ] ],
			$propertyOf
		);
		$this->assertSame( [ 'Has_population' => 'DESC', '' => 'ASC' ], $result );
	}

	// -------------------------------------------------------------------------
	// toDescription() — empty / null
	// -------------------------------------------------------------------------

	public function testEmptyFilterModelReturnsNull(): void {
		$result = $this->translator->toDescription( [], static fn () => 'text' );
		$this->assertNull( $result );
	}

	// -------------------------------------------------------------------------
	// toDescription() — text filter
	// -------------------------------------------------------------------------

	public function testTextContains(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'contains', 'filter' => 'lond' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '~', $qs );
		$this->assertStringContainsString( '*lond*', $qs );
	}

	public function testTextEquals(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'equals', 'filter' => 'London' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'London', $qs );
		// EQ has no ~ or >> comparator glyph
		$this->assertStringNotContainsString( '~', $qs );
		$this->assertStringNotContainsString( '>>', $qs );
	}

	public function testTextStartsWith(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'startsWith', 'filter' => 'Lon' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '~', $qs );
		$this->assertStringContainsString( 'Lon*', $qs );
	}

	public function testTextEndsWith(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'endsWith', 'filter' => 'don' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '~', $qs );
		$this->assertStringContainsString( '*don', $qs );
	}

	public function testTextNotContains(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'notContains', 'filter' => 'lond' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '!~', $qs );
		$this->assertStringContainsString( '*lond*', $qs );
	}

	public function testTextNotBlank(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'notBlank' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( SomeProperty::class, $desc );
		// ThingDescription inside → query string contains '+'
		$this->assertStringContainsString( '+', $desc->getQueryString() );
	}

	public function testTextBlankContributesNothing(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'blank' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNull( $desc );
	}

	// -------------------------------------------------------------------------
	// toDescription() — number filter
	// -------------------------------------------------------------------------

	public function testNumberInRange(): void {
		$model = [
			'Population' => [
				'filterType' => 'number',
				'type' => 'inRange',
				'filter' => 1000000,
				'filterTo' => 5000000,
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'number' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		// GEQ (≥) lower bound
		$this->assertStringContainsString( '≥', $qs );
		// LEQ (≤) upper bound
		$this->assertStringContainsString( '≤', $qs );
		$this->assertStringContainsString( '1000000', $qs );
		$this->assertStringContainsString( '5000000', $qs );
	}

	public function testNumberGreaterThan(): void {
		$model = [
			'Population' => [ 'filterType' => 'number', 'type' => 'greaterThan', 'filter' => 500 ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'number' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '>>', $qs );
		$this->assertStringContainsString( '500', $qs );
	}

	public function testNumberEquals(): void {
		$model = [
			'Population' => [ 'filterType' => 'number', 'type' => 'equals', 'filter' => 42 ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'number' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '42', $qs );
		$this->assertStringNotContainsString( '~', $qs );
		$this->assertStringNotContainsString( '≥', $qs );
	}

	public function testNumberLessThan(): void {
		$model = [
			'Population' => [ 'filterType' => 'number', 'type' => 'lessThan', 'filter' => 1000 ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'number' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '<<', $qs );
		$this->assertStringContainsString( '1000', $qs );
	}

	public function testNumberNotEqual(): void {
		$model = [
			'Population' => [ 'filterType' => 'number', 'type' => 'notEqual', 'filter' => 0 ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'number' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '!', $qs );
		$this->assertStringContainsString( '0', $qs );
	}

	public function testNumberInRangeMissingFilterTo(): void {
		// Only 'filter' (low bound) present; 'filterTo' absent.
		// Should produce only the low (≥) bound, not a spurious high bound.
		$model = [
			'Population' => [
				'filterType' => 'number',
				'type' => 'inRange',
				'filter' => 500,
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'number' );
		$this->assertNotNull( $desc );
		// Must not be a Conjunction — only one bound
		$this->assertNotInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '≥', $qs );
		$this->assertStringContainsString( '500', $qs );
		// No upper bound present
		$this->assertStringNotContainsString( '≤', $qs );
	}

	// -------------------------------------------------------------------------
	// toDescription() — date filter
	// -------------------------------------------------------------------------

	public function testDateGreaterThan(): void {
		$model = [
			'Founded' => [
				'filterType' => 'date',
				'type' => 'greaterThan',
				'dateFrom' => '2020-01-15 00:00:00',
				'dateTo' => null,
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'date' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '>>', $qs );
		$this->assertStringContainsString( '2020', $qs );
	}

	public function testDateInRange(): void {
		$model = [
			'Founded' => [
				'filterType' => 'date',
				'type' => 'inRange',
				'dateFrom' => '2020-01-01 00:00:00',
				'dateTo' => '2022-12-31 00:00:00',
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'date' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '≥', $qs );
		$this->assertStringContainsString( '≤', $qs );
	}

	public function testDateGreaterThanOrEqual(): void {
		$model = [
			'Founded' => [
				'filterType' => 'date',
				'type' => 'greaterThanOrEqual',
				'dateFrom' => '2021-06-01 00:00:00',
				'dateTo' => null,
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'date' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '≥', $qs );
		$this->assertStringContainsString( '2021', $qs );
	}

	public function testDateLessThanOrEqual(): void {
		$model = [
			'Founded' => [
				'filterType' => 'date',
				'type' => 'lessThanOrEqual',
				'dateFrom' => '2023-12-31 00:00:00',
				'dateTo' => null,
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'date' );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '≤', $qs );
		$this->assertStringContainsString( '2023', $qs );
	}

	// -------------------------------------------------------------------------
	// toDescription() — set filter
	// -------------------------------------------------------------------------

	public function testSetFilterMultipleValues(): void {
		// Include with multiple values → a single property condition with a disjunctive
		// value ([[Country::France||Germany]]), not a Disjunction of separate property
		// subqueries (which would drive SMW's temp-table path / invalid SQLite SQL).
		$model = [
			'Country' => [ 'values' => [ 'France', 'Germany' ] ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'set' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( SomeProperty::class, $desc );
		$this->assertInstanceOf( Disjunction::class, $desc->getDescription() );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'France', $qs );
		$this->assertStringContainsString( 'Germany', $qs );
		$this->assertStringContainsString( '||', $qs );
	}

	public function testSetFilterSingleValue(): void {
		$model = [
			'Country' => [ 'values' => [ 'France' ] ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'set' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( SomeProperty::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'France', $qs );
	}

	public function testSetFilterEmptyValuesContributesNothing(): void {
		$model = [
			'Country' => [ 'values' => [] ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'set' );
		$this->assertNull( $desc );
	}

	public function testSetFilterExcludeMultipleValues(): void {
		// Exclude model → AND of inequalities (not France AND not Germany), so the
		// query stays small when the user only unchecks a few values.
		$model = [
			'Country' => [ 'values' => [ 'France', 'Germany' ], 'exclude' => true ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'set' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '!France', $qs );
		$this->assertStringContainsString( '!Germany', $qs );
	}

	public function testSetFilterExcludeSingleValue(): void {
		$model = [
			'Country' => [ 'values' => [ 'France' ], 'exclude' => true ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'set' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( SomeProperty::class, $desc );
		$this->assertStringContainsString( '!France', $desc->getQueryString() );
	}

	// -------------------------------------------------------------------------
	// toDescription() — combined (OR / AND)
	// -------------------------------------------------------------------------

	public function testCombinedOrConditions(): void {
		$model = [
			'City' => [
				'filterType' => 'text',
				'operator' => 'OR',
				'conditions' => [
					[ 'type' => 'contains', 'filter' => 'london' ],
					[ 'type' => 'contains', 'filter' => 'paris' ],
				],
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Disjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'london', $qs );
		$this->assertStringContainsString( 'paris', $qs );
	}

	public function testCombinedAndConditions(): void {
		$model = [
			'City' => [
				'filterType' => 'text',
				'operator' => 'AND',
				'conditions' => [
					[ 'type' => 'startsWith', 'filter' => 'Lon' ],
					[ 'type' => 'endsWith', 'filter' => 'don' ],
				],
			],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'Lon*', $qs );
		$this->assertStringContainsString( '*don', $qs );
	}

	// -------------------------------------------------------------------------
	// toDescription() — multiple columns
	// -------------------------------------------------------------------------

	// -------------------------------------------------------------------------
	// toDescription() — aliased columns (field -> property resolver)
	// -------------------------------------------------------------------------

	public function testToDescriptionResolvesAliasedColumnToProperty(): void {
		// The filterModel is keyed by the alias ('Pop'); the resolver maps it to the
		// real property ('Has population'), which must appear in the Description.
		$model = [
			'Pop' => [ 'filterType' => 'number', 'type' => 'greaterThan', 'filter' => 1000 ],
		];
		$propertyOf = static fn ( string $field ): string => [ 'Pop' => 'Has population' ][$field] ?? $field;
		$desc = $this->translator->toDescription( $model, static fn () => 'number', $propertyOf );
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'Has population', $qs );
		$this->assertStringNotContainsString( 'Pop]]', $qs, 'the alias must not leak into the query' );
		$this->assertStringContainsString( '1000', $qs );
	}

	public function testToDescriptionWithoutResolverTreatsFieldAsProperty(): void {
		// No resolver: backward-compatible, the field is used as the property label.
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'equals', 'filter' => 'London' ],
		];
		$desc = $this->translator->toDescription( $model, static fn () => 'text' );
		$this->assertNotNull( $desc );
		$this->assertStringContainsString( 'City', $desc->getQueryString() );
	}

	public function testTwoColumnsProduceConjunction(): void {
		$model = [
			'City' => [ 'filterType' => 'text', 'type' => 'contains', 'filter' => 'lond' ],
			'Population' => [ 'filterType' => 'number', 'type' => 'greaterThan', 'filter' => 1000000 ],
		];
		$familyOf = static function ( string $field ): string {
			return $field === 'Population' ? 'number' : 'text';
		};
		$desc = $this->translator->toDescription( $model, $familyOf );
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'lond', $qs );
		$this->assertStringContainsString( '1000000', $qs );
	}

	// -------------------------------------------------------------------------
	// quickSearchDescription()
	//
	// Type-dependent behaviour (date range, dropping a value that does not parse)
	// is exercised through SMW's predefined 'Modification date' property, which is
	// always registered as a date type — so getQueryDescription dispatches to the
	// real TimeValueDescriptionBuilder without seeding any wiki data. Type-neutral
	// behaviour (word splitting, OR/AND structure, value forms, sanitization) uses
	// plain labels and asserts on the serialized query string.
	// -------------------------------------------------------------------------

	/** A property registered (predefined) as a date type. */
	private const DATE_PROP = 'Modification date';

	/** Identity field -> property resolver. */
	private static function identity(): callable {
		return static fn ( string $field ): string => $field;
	}

	/** Constant kind resolver. */
	private static function kind( ?string $kind ): callable {
		return static fn ( string $field ): ?string => $kind;
	}

	/** Per-field kind resolver. */
	private static function kinds( array $map ): callable {
		return static fn ( string $field ): ?string => $map[$field] ?? null;
	}

	public function testQuickSearchSingleWordTextColumn(): void {
		$desc = $this->translator->quickSearchDescription(
			'lond', [ 'City' ], self::identity(), self::kind( 'like-text' ), false
		);
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'City', $qs );
		$this->assertStringContainsString( '~', $qs );
		$this->assertStringContainsString( '*lond*', $qs );
	}

	public function testQuickSearchIncludesSubject(): void {
		// No declared columns: only the subject (page name) is matched, as a
		// top-level [[~*lond*]] condition.
		$desc = $this->translator->quickSearchDescription(
			'lond', [], self::identity(), self::kind( null ), true
		);
		$this->assertNotNull( $desc );
		$this->assertStringContainsString( '~*lond*', $desc->getQueryString() );
	}

	public function testQuickSearchSubjectAndColumnCombineAsDisjunction(): void {
		// One word, two targets (subject + column) → OR.
		$desc = $this->translator->quickSearchDescription(
			'lond', [ 'City' ], self::identity(), self::kind( 'like-text' ), true
		);
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Disjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'City', $qs );
		$this->assertStringContainsString( '~*lond*', $qs );
	}

	public function testQuickSearchMultiWordProducesConjunction(): void {
		// Each word must match somewhere → AND across words.
		$desc = $this->translator->quickSearchDescription(
			'red sedan', [ 'City' ], self::identity(), self::kind( 'like-text' ), false
		);
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Conjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '*red*', $qs );
		$this->assertStringContainsString( '*sedan*', $qs );
	}

	public function testQuickSearchMultipleColumnsProduceDisjunctionPerWord(): void {
		// One word, two columns → OR across columns.
		$desc = $this->translator->quickSearchDescription(
			'x', [ 'City', 'Country' ], self::identity(), self::kind( 'like-text' ), false
		);
		$this->assertNotNull( $desc );
		$this->assertInstanceOf( Disjunction::class, $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'City', $qs );
		$this->assertStringContainsString( 'Country', $qs );
	}

	public function testQuickSearchNumberColumnExactValueForm(): void {
		// eq-number emits the bare value: exact match, no LIKE comparator, no wildcard.
		$desc = $this->translator->quickSearchDescription(
			'42', [ 'Population' ], self::identity(), self::kind( 'eq-number' ), false
		);
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '42', $qs );
		$this->assertStringNotContainsString( '~', $qs );
		$this->assertStringNotContainsString( '*', $qs );
	}

	public function testQuickSearchNumberColumnIgnoresNonNumericWord(): void {
		// A number column only matches a strictly-numeric word. A non-numeric token
		// (here a bare word; in practice also comparator forms like "in:99" or "≥100"
		// that survive metacharacter stripping) must not be fed to the number builder,
		// where it would parse as a comparator/range. With no other target, the search
		// yields nothing.
		$desc = $this->translator->quickSearchDescription(
			'inch', [ 'Length' ], self::identity(), self::kind( 'eq-number' ), false
		);
		$this->assertNull( $desc );
	}

	public function testQuickSearchDateColumnPrecisionRange(): void {
		// A real date column: '2020' with the ~ comparator expands to a precision
		// range (all of 2020), so the serialized query carries a ≥ bound.
		$desc = $this->translator->quickSearchDescription(
			'2020', [ self::DATE_PROP ], self::identity(), self::kind( 'like-date' ), false
		);
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '2020', $qs );
		$this->assertStringContainsString( '≥', $qs );
	}

	public function testQuickSearchDropsValueThatDoesNotParseForItsType(): void {
		// 'red' is not a date, so the date column's condition is a match-everything
		// ThingDescription and is dropped; only the text column survives the word.
		$desc = $this->translator->quickSearchDescription(
			'red',
			[ 'City', self::DATE_PROP ],
			self::identity(),
			self::kinds( [ 'City' => 'like-text', self::DATE_PROP => 'like-date' ] ),
			false
		);
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'City', $qs );
		$this->assertStringContainsString( '*red*', $qs );
		$this->assertStringNotContainsString( self::DATE_PROP, $qs );
	}

	public function testQuickSearchEmptyTermReturnsNull(): void {
		$desc = $this->translator->quickSearchDescription(
			'   ', [ 'City' ], self::identity(), self::kind( 'like-text' ), true
		);
		$this->assertNull( $desc );
	}

	public function testQuickSearchStripsMetacharacters(): void {
		// Wildcard/comparator glyphs in the user term are stripped so the word is a
		// literal substring; the only * / ~ are the ones we wrap with.
		$desc = $this->translator->quickSearchDescription(
			'Lon*don', [ 'City' ], self::identity(), self::kind( 'like-text' ), false
		);
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( '*London*', $qs );
		$this->assertStringNotContainsString( 'Lon*don', $qs );
	}

	public function testQuickSearchSkipsNonSearchableColumns(): void {
		// The only column is not searchable (kind null) and the subject is excluded.
		$desc = $this->translator->quickSearchDescription(
			'x', [ 'Flag' ], self::identity(), self::kind( null ), false
		);
		$this->assertNull( $desc );
	}

	public function testQuickSearchResolvesAliasedColumnToProperty(): void {
		$propertyOf = static fn ( string $field ): string => [ 'Pop' => 'Has population' ][$field] ?? $field;
		$desc = $this->translator->quickSearchDescription(
			'1000', [ 'Pop' ], $propertyOf, self::kind( 'eq-number' ), false
		);
		$this->assertNotNull( $desc );
		$qs = $desc->getQueryString();
		$this->assertStringContainsString( 'Has population', $qs );
		$this->assertStringNotContainsString( 'Pop]]', $qs );
	}
}
