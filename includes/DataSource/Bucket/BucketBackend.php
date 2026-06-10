<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;

/**
 * The Bucket backend: bundles its source compiler, data source, and capabilities.
 *
 * Bucket has no LIKE operator, so it does not support server-side quick search.
 */
class BucketBackend implements Backend {

	public function __construct(
		private readonly BucketSourceCompiler $compiler,
		private readonly BackendDataSource $dataSource
	) {
	}

	/** @inheritDoc */
	public function getType(): string {
		return 'bucket';
	}

	/** @inheritDoc */
	public function compileSource( array $source ): array {
		return $this->compiler->compile( $source );
	}

	/** @inheritDoc */
	public function getDataSource(): BackendDataSource {
		return $this->dataSource;
	}

	/** @inheritDoc */
	public function supportsQuickSearch(): bool {
		return false;
	}
}
