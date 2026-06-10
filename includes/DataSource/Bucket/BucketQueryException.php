<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use RuntimeException;

/**
 * Thrown when a Bucket-backed grid query fails at request time.
 *
 * The backend REST handlers map a RuntimeException to a 400 (rest-bad-request)
 * without leaking internals, mirroring {@see \MediaWiki\Extension\AGGrid\DataSource\Smw\SmwQueryException}.
 */
class BucketQueryException extends RuntimeException {
}
