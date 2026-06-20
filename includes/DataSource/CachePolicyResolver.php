<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * Resolves a {@see CachePolicy} for a grid source from the AGGridCacheControl map.
 *
 * Keys are source identifiers: 'inline' for the materialized /rows path and the backend
 * registry ids ('smw', 'bucket', ...) for live sources. An unlisted key falls back to
 * 'default'; a missing 'default' falls back to a hardcoded default.
 */
final class CachePolicyResolver {

	private const DEFAULT_MAX_AGE = 600;

	/**
	 * @param array<string,array{maxAge?:int,staleWhileRevalidate?:int}> $map The
	 *   AGGridCacheControl config value.
	 */
	public function __construct(
		private readonly array $map
	) {
	}

	public function forSource( string $key ): CachePolicy {
		$entry = $this->map[$key] ?? $this->map['default'] ?? [];
		return new CachePolicy(
			(int)( $entry['maxAge'] ?? self::DEFAULT_MAX_AGE ),
			(int)( $entry['staleWhileRevalidate'] ?? 0 )
		);
	}
}
