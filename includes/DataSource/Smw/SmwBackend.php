<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource\Smw;

use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;

/**
 * The Semantic MediaWiki backend: bundles its source compiler, data source, and capabilities.
 */
class SmwBackend implements Backend {

	public function __construct(
		private readonly SmwSourceCompiler $compiler,
		private readonly BackendDataSource $dataSource
	) {
	}

	/** @inheritDoc */
	public function getType(): string {
		return 'smw';
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
		return true;
	}
}
