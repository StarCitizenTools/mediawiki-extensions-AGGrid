<?php

declare( strict_types=1 );

use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\DataSourceRegistry;
use MediaWiki\Extension\AGGrid\DataSource\Smw\FilterTranslator;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Smw\TypeColumnMapper;
use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\AGGrid\Service\InlineDataStore;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MediaWikiServices;
use MediaWiki\Registration\ExtensionRegistry;

return [
	'AGGrid.GridRenderer' => static function ( MediaWikiServices $services ): GridRenderer {
		return new GridRenderer( new TemplateParser( __DIR__ . '/templates' ) );
	},
	'AGGrid.InlineDataStore' => static function ( MediaWikiServices $services ): GridDataStore {
		return new InlineDataStore( $services->getConnectionProvider() );
	},
	'AGGrid.SourceSpecStore' => static function ( MediaWikiServices $services ): SourceSpecStore {
		return new SourceSpecStore( $services->getConnectionProvider() );
	},
	'AGGrid.DataSourceRegistry' => static function ( MediaWikiServices $services ): DataSourceRegistry {
		$factories = [];
		if ( ExtensionRegistry::getInstance()->isLoaded( 'SemanticMediaWiki' ) ) {
			$factories['smw'] = static function () use ( $services ): BackendDataSource {
				return $services->getService( 'AGGrid.SmwDataSource' );
			};
		}
		return new DataSourceRegistry( $factories );
	},
	'AGGrid.BackendCacheMaxAge' => static function ( MediaWikiServices $services ): int {
		return (int)$services->getMainConfig()->get( 'AGGridBackendCacheMaxAge' );
	},
	'AGGrid.SmwDataSource' => static function ( MediaWikiServices $services ): SmwDataSource {
		// SMW services are resolved lazily inside the closure so this entry can be
		// defined unconditionally; it is only ever requested when SMW is loaded.
		return new SmwDataSource(
			\SMW\Services\ServicesFactory::getInstance()->getStore(),
			$services->getService( 'AGGrid.SourceSpecStore' ),
			new FilterTranslator(),
			new TypeColumnMapper(),
			$services->getService( 'AGGrid.BackendCacheMaxAge' ),
			(int)$GLOBALS['smwgQMaxInlineLimit']
		);
	},
];
