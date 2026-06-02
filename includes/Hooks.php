<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid;

use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;

final class Hooks implements ScribuntoExternalLibrariesHook {

	/**
	 * @inheritDoc
	 */
	public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ) {
		if ( $engine !== 'lua' ) {
			return;
		}

		$extraLibraries['mw.ext.aggrid'] = LuaLibrary::class;
	}
}
