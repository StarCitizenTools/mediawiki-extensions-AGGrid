<?php

declare( strict_types=1 );

use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Html\TemplateParser;
use MediaWiki\MediaWikiServices;

return [
	'AGGrid.GridRenderer' => static function ( MediaWikiServices $services ): GridRenderer {
		return new GridRenderer( new TemplateParser( __DIR__ . '/templates' ) );
	},
];
