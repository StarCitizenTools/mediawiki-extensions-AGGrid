<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use RuntimeException;

/**
 * Thrown when a Semantic MediaWiki query produced errors.
 *
 * The REST handler (Task 9) turns this into an HTTP 400, surfacing the joined
 * SMW error messages to the caller.
 */
class SmwQueryException extends RuntimeException {
}
