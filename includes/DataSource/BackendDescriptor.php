<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use Closure;

/**
 * A lazy registration record for a {@see Backend}: its type, the extension it requires (if any),
 * and a factory that builds it on demand.
 *
 * The factory is only invoked when {@see BackendRegistry::get()} first resolves the type, so a
 * descriptor for a backend whose extension is absent never loads that backend's classes.
 */
final class BackendDescriptor {

	/**
	 * @param string $type Source.type discriminator (e.g. 'smw').
	 * @param string|null $requiredExtension Extension that must be loaded for this backend to be
	 *   available, by its ExtensionRegistry name (e.g. 'SemanticMediaWiki'); null = always available.
	 * @param Closure $factory Callable(): Backend — builds the backend lazily.
	 */
	public function __construct(
		public readonly string $type,
		public readonly ?string $requiredExtension,
		public readonly Closure $factory
	) {
	}
}
