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

	/**
	 * Render the public Cache-Control value for this policy:
	 * "public, max-age=N" plus ", stale-while-revalidate=M" when M > 0.
	 */
	public function toPublicCacheControl(): string {
		$value = 'public, max-age=' . $this->maxAge;
		if ( $this->staleWhileRevalidate > 0 ) {
			$value .= ', stale-while-revalidate=' . $this->staleWhileRevalidate;
		}
		return $value;
	}
}
