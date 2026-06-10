<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use InvalidArgumentException;

/**
 * Thrown by {@see BackendRegistry::get()} when no backend is registered for a source type.
 *
 * Extends InvalidArgumentException so the REST handlers map it to a 404; LuaLibrary rethrows it
 * as a LuaError at parse time.
 */
class UnknownBackendException extends InvalidArgumentException {
}
