<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Immutable value object representing a single offset-paged result from a backend data source.
 *
 * Mirrors the client-facing shape: { rows, total }, where total is the count of
 * all rows matching the query (across every page) so AG Grid can render its
 * native pagination bar.
 */
final class GridPage {

	public function __construct(
		private readonly array $rows,
		private readonly int $total
	) {
	}

	public function getRows(): array {
		return $this->rows;
	}

	public function getTotal(): int {
		return $this->total;
	}
}
