<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use Closure;

/**
 * Resolves a {@see Backend} by source type, memoizing after first resolution.
 *
 * The single dispatch seam for backend-source grids, used by both the parse-time LuaLibrary and
 * the request-time REST handlers. Built from {@see BackendDescriptor}s, it is also the one place
 * that gates a backend on its required extension, so adding a backend never touches the generic
 * dispatch.
 *
 * The extension-availability check is injected (rather than calling ExtensionRegistry directly)
 * so the registry stays a pure, unit-testable unit; ServiceWiring supplies the real check.
 */
class BackendRegistry {

	/** @var array<string, BackendDescriptor> */
	private array $descriptors = [];

	/** @var array<string, Backend> */
	private array $instances = [];

	/** @var Closure Callable( string $extension ): bool */
	private Closure $isExtensionLoaded;

	/**
	 * @param BackendDescriptor[] $descriptors
	 * @param callable $isExtensionLoaded Callable( string $extension ): bool — true when the
	 *   named extension is available.
	 */
	public function __construct( array $descriptors, callable $isExtensionLoaded ) {
		foreach ( $descriptors as $descriptor ) {
			$this->descriptors[$descriptor->type] = $descriptor;
		}
		$this->isExtensionLoaded = Closure::fromCallable( $isExtensionLoaded );
	}

	/**
	 * Resolve a backend by source type.
	 *
	 * @param string $type Source.type discriminator.
	 * @return Backend
	 * @throws UnknownBackendException When no backend is registered for the type.
	 * @throws BackendUnavailableException When the backend's required extension is not loaded.
	 */
	public function get( string $type ): Backend {
		if ( isset( $this->instances[$type] ) ) {
			return $this->instances[$type];
		}

		if ( !isset( $this->descriptors[$type] ) ) {
			throw new UnknownBackendException( "unknown source type \"$type\"" );
		}

		$descriptor = $this->descriptors[$type];
		if ( $descriptor->requiredExtension !== null
			&& !( $this->isExtensionLoaded )( $descriptor->requiredExtension )
		) {
			throw new BackendUnavailableException(
				"source type \"$type\" requires the \"{$descriptor->requiredExtension}\" extension"
			);
		}

		$this->instances[$type] = ( $descriptor->factory )();
		return $this->instances[$type];
	}

	/**
	 * Whether a backend is registered AND available (its required extension is loaded) for the type.
	 */
	public function has( string $type ): bool {
		if ( !isset( $this->descriptors[$type] ) ) {
			return false;
		}
		$descriptor = $this->descriptors[$type];
		return $descriptor->requiredExtension === null
			|| ( $this->isExtensionLoaded )( $descriptor->requiredExtension );
	}
}
