<?php

declare( strict_types=1 );

use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\AGGrid\Service\InlineDataStore;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MediaWikiServices;

return [
	'AGGrid.GridRenderer' => static function ( MediaWikiServices $services ): GridRenderer {
		return new GridRenderer( new TemplateParser( __DIR__ . '/templates' ) );
	},
	'AGGrid.InlineDataStore' => static function ( MediaWikiServices $services ): GridDataStore {
		return new InlineDataStore( $services->getConnectionProvider() );
	},
];
