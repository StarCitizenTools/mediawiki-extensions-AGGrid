<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LuaError;

/**
 * A backend-source provider: one cohesive unit per data source (SMW, Bucket, future Cargo).
 *
 * A Backend bundles the two halves of a backend-source grid that the generic code dispatches
 * to: the parse-time {@see self::compileSource()} (Lua `source` descriptor → AG Grid columnDefs
 * + stored query spec) and the request-time {@see self::getDataSource()} (offset-paged rows,
 * column values, cache policy). It also declares the one capability that affects the generic
 * render path, {@see self::supportsQuickSearch()}.
 *
 * Backends are resolved by {@see BackendRegistry}; adding one is a single implementation plus a
 * {@see BackendDescriptor} registration, with no edits to the generic dispatch.
 */
interface Backend {

	/**
	 * The source.type discriminator (e.g. 'smw'); also the value stored as ags_source and
	 * emitted to the client.
	 */
	public function getType(): string;

	/**
	 * Compile a Lua `source` descriptor into [ columnDefs, spec ].
	 *
	 * @param array $source The `source` table from gridOptions.
	 * @return array{0: array, 1: array} [ columnDefs, spec ]
	 * @throws LuaError On invalid author input (parse time is the only author-feedback point).
	 */
	public function compileSource( array $source ): array;

	/**
	 * The request-time data source serving rows, column values, and the cache policy.
	 */
	public function getDataSource(): BackendDataSource;

	/**
	 * Whether the backend supports server-side quick search. When false, the quick-search
	 * box is stripped rather than rendered inert.
	 */
	public function supportsQuickSearch(): bool;
}
