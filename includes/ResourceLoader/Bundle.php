<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\ResourceLoader;

use MediaWiki\Config\Config;
use MediaWiki\MainConfigNames;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\FilePath;
use RuntimeException;

/**
 * Describes the vendored AG Grid bundle to the client.
 *
 * The bundle is injected as a plain <script> from its static path rather than
 * through ResourceLoader (wikimedia/minify <= 2.10.0 corrupts AG Grid's ES2020
 * BigInt literals), so nothing versions that URL for us. Rather than have the
 * client rebuild the URL and carry a hand-maintained version alongside it, the
 * whole src is built here: the path is spelled once, and the cache-busting
 * token is derived from the bundle's own bytes, so it changes exactly when the
 * file changes and no dependency bump can forget it.
 */
final class Bundle {

	/** Bundle path, relative to the ext.aggrid module's base paths. */
	public const BUNDLE = 'lib/ag-grid-community/ag-grid-community.min.js';

	/**
	 * Mirrors "remoteExtPath" for ResourceFileModulePaths in extension.json.
	 *
	 * A packageFiles callback is handed only a Context and a Config, never the
	 * module, so the module's own remote base path is out of reach and has to be
	 * restated here. BundleTest asserts the two stay equal — diverge them and
	 * every grid 404s.
	 */
	public const REMOTE_MODULE_PATH = 'AGGrid/modules';

	/**
	 * Mirrors "localBasePath" for ResourceFileModulePaths in extension.json.
	 *
	 * Restated for the same reason, and the more dangerous of the two: diverge
	 * this one and the file that gets hashed is no longer the file the browser
	 * fetches, which is exactly the silent drift this class exists to prevent.
	 * BundleTest asserts it too.
	 */
	public const LOCAL_MODULE_PATH = 'modules';

	/**
	 * Content of the virtual bundle.json package file.
	 *
	 * @param Context $context
	 * @param Config $config
	 * @return array{src:string}
	 */
	public static function makePackageFile( Context $context, Config $config ): array {
		$base = $config->get( MainConfigNames::ExtensionAssetsPath );

		return [
			'src' => $base . '/' . self::REMOTE_MODULE_PATH . '/' . self::BUNDLE
				. '?v=' . self::hashBundle(),
		];
	}

	/**
	 * Version source for the virtual package file.
	 *
	 * Returning the bundle itself lets ResourceLoader version the module off
	 * that file's contents, so a new bundle invalidates the module that carries
	 * the URL without the (comparatively costly) hash running on every version
	 * computation.
	 *
	 * A fresh FilePath each call: ResourceLoader mutates it via initBasePaths().
	 *
	 * @param Context $context Unused; part of the versionCallback contract.
	 * @param Config $config Unused; part of the versionCallback contract.
	 * @return FilePath
	 */
	public static function getVersionFilePath( Context $context, Config $config ): FilePath {
		return new FilePath( self::BUNDLE );
	}

	private static function hashBundle(): string {
		$path = __DIR__ . '/../../' . self::LOCAL_MODULE_PATH . '/' . self::BUNDLE;
		$hash = is_readable( $path ) ? hash_file( 'sha256', $path ) : false;
		if ( $hash === false ) {
			throw new RuntimeException( "Unable to read AG Grid bundle at $path" );
		}

		// A short prefix is plenty: this only has to differ between releases of
		// a single vendored file, and it ends up in a user-visible URL.
		return substr( $hash, 0, 12 );
	}
}
