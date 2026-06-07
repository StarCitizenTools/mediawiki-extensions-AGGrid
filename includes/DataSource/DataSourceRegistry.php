<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

use InvalidArgumentException;

/**
 * Resolves a {@see BackendDataSource} instance by source key, memoizing after first resolution.
 *
 * Factories are callables that return a BackendDataSource. They are invoked lazily — only when
 * {@see get()} is first called for that key.
 */
class DataSourceRegistry {

	/** @var array<string, callable(): BackendDataSource> */
	private array $factories;

	/** @var array<string, BackendDataSource> */
	private array $instances = [];

	/**
	 * @param array<string, callable(): BackendDataSource> $factories
	 */
	public function __construct( array $factories ) {
		$this->factories = $factories;
	}

	/**
	 * Resolve a BackendDataSource by source key.
	 *
	 * @param string $source Source discriminator (e.g. 'smw').
	 * @return BackendDataSource
	 * @throws InvalidArgumentException When no factory is registered for the given key.
	 */
	public function get( string $source ): BackendDataSource {
		if ( isset( $this->instances[$source] ) ) {
			return $this->instances[$source];
		}

		if ( !isset( $this->factories[$source] ) ) {
			throw new InvalidArgumentException( "No data source registered for key: $source" );
		}

		$this->instances[$source] = ( $this->factories[$source] )();

		return $this->instances[$source];
	}
}
