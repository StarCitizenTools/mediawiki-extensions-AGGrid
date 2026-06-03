<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Hooks;

use MediaWiki\Extension\AGGrid\Scribunto\LuaLibrary;
use MediaWiki\Extension\Scribunto\Hooks\ScribuntoExternalLibrariesHook;

/**
 * Registers the mw.ext.aggrid Lua library with Scribunto.
 */
final class ScribuntoHandler implements ScribuntoExternalLibrariesHook {

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
