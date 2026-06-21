<?php

declare( strict_types=1 );

use MediaWiki\Extension\AGGrid\DataSource\Backend;
use MediaWiki\Extension\AGGrid\DataSource\BackendDescriptor;
use MediaWiki\Extension\AGGrid\DataSource\BackendRegistry;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketBackend;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketColumnMapper;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketFilterTranslator;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketRunner;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSchemaReader;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSourceCompiler;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicyResolver;
use MediaWiki\Extension\AGGrid\DataSource\Smw\FilterTranslator;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwBackend;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwSourceCompiler;
use MediaWiki\Extension\AGGrid\DataSource\Smw\TypeColumnMapper;
use MediaWiki\Extension\AGGrid\Service\GridDataPopulator;
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
	'AGGrid.GridDataPopulator' => static function ( MediaWikiServices $services ): GridDataPopulator {
		return new GridDataPopulator(
			$services->getWikiPageFactory(),
			$services->getParserOutputAccess(),
			$services->getService( 'AGGrid.InlineDataStore' ),
			$services->getService( 'AGGrid.SourceSpecStore' ),
			$services->getReadOnlyMode()
		);
	},
	'AGGrid.BackendRegistry' => static function ( MediaWikiServices $services ): BackendRegistry {
		return new BackendRegistry(
			[
				new BackendDescriptor(
					'smw',
					'SemanticMediaWiki',
					static function () use ( $services ): Backend {
						return $services->getService( 'AGGrid.SmwBackend' );
					}
				),
				new BackendDescriptor(
					'bucket',
					'Bucket',
					static function () use ( $services ): Backend {
						return $services->getService( 'AGGrid.BucketBackend' );
					}
				),
			],
			static fn ( string $ext ): bool => ExtensionRegistry::getInstance()->isLoaded( $ext )
		);
	},
	'AGGrid.CachePolicyResolver' => static function ( MediaWikiServices $services ): CachePolicyResolver {
		return new CachePolicyResolver(
			(array)$services->getMainConfig()->get( 'AGGridCacheControl' )
		);
	},
	'AGGrid.SmwDataSource' => static function ( MediaWikiServices $services ): SmwDataSource {
		// SMW services are resolved lazily inside the closure so this entry can be
		// defined unconditionally; it is only ever requested when SMW is loaded.
		return new SmwDataSource(
			\SMW\Services\ServicesFactory::getInstance()->getStore(),
			$services->getService( 'AGGrid.SourceSpecStore' ),
			new FilterTranslator(),
			new TypeColumnMapper(),
			$services->getService( 'AGGrid.CachePolicyResolver' )->forSource( 'smw' ),
			(int)$GLOBALS['smwgQMaxInlineLimit']
		);
	},
	'AGGrid.SmwSourceCompiler' => static function ( MediaWikiServices $services ): SmwSourceCompiler {
		return new SmwSourceCompiler();
	},
	'AGGrid.SmwBackend' => static function ( MediaWikiServices $services ): SmwBackend {
		return new SmwBackend(
			$services->getService( 'AGGrid.SmwSourceCompiler' ),
			$services->getService( 'AGGrid.SmwDataSource' )
		);
	},
	'AGGrid.BucketMaxValues' => static function ( MediaWikiServices $services ): int {
		return (int)$services->getMainConfig()->get( 'AGGridBucketMaxValues' );
	},
	'AGGrid.BucketSchemaReader' => static function ( MediaWikiServices $services ): BucketSchemaReader {
		return new BucketSchemaReader();
	},
	'AGGrid.BucketSourceCompiler' => static function ( MediaWikiServices $services ): BucketSourceCompiler {
		return new BucketSourceCompiler(
			$services->getService( 'AGGrid.BucketSchemaReader' ),
			new BucketColumnMapper()
		);
	},
	'AGGrid.BucketDataSource' => static function ( MediaWikiServices $services ): BucketDataSource {
		// Bucket services are resolved lazily; this entry is only ever requested when
		// Bucket is loaded (the registry factory gates it on isLoaded).
		return new BucketDataSource(
			new BucketRunner(),
			$services->getService( 'AGGrid.SourceSpecStore' ),
			new BucketFilterTranslator(),
			new BucketColumnMapper(),
			$services->getService( 'AGGrid.CachePolicyResolver' )->forSource( 'bucket' ),
			$services->getService( 'AGGrid.BucketMaxValues' )
		);
	},
	'AGGrid.BucketBackend' => static function ( MediaWikiServices $services ): BucketBackend {
		return new BucketBackend(
			$services->getService( 'AGGrid.BucketSourceCompiler' ),
			$services->getService( 'AGGrid.BucketDataSource' )
		);
	},
];
