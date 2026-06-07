<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Immutable value object describing the cache behaviour of a backend data source.
 */
final class CachePolicy {

	public function __construct(
		private readonly bool $public,
		private readonly int $maxAge
	) {
	}

	public function isPublic(): bool {
		return $this->public;
	}

	public function getMaxAge(): int {
		return $this->maxAge;
	}
}
