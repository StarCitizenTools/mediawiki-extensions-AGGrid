<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use InvalidArgumentException;

/**
 * Thrown by {@see BackendRegistry::get()} when a backend is registered for the source type but
 * its required extension is not loaded.
 *
 * Extends InvalidArgumentException so the REST handlers map it to a 404; LuaLibrary rethrows it
 * as a LuaError at parse time.
 */
class BackendUnavailableException extends InvalidArgumentException {
}
