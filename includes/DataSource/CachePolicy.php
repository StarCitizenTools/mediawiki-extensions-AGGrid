<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Immutable value object describing the cache behaviour of a backend data source.
 */
final class CachePolicy {

	public function __construct(
		private readonly int $maxAge,
		private readonly int $staleWhileRevalidate = 0
	) {
	}

	public function getMaxAge(): int {
		return $this->maxAge;
	}

	public function getStaleWhileRevalidate(): int {
		return $this->staleWhileRevalidate;
	}
}
