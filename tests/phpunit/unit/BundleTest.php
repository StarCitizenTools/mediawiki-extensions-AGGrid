<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Config\HashConfig;
use MediaWiki\Extension\AGGrid\ResourceLoader\Bundle;
use MediaWiki\MainConfigNames;
use MediaWiki\ResourceLoader\Context;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\ResourceLoader\Bundle
 */
class BundleTest extends MediaWikiUnitTestCase {

	private const EXTENSION_DIR = __DIR__ . '/../../..';

	private function makePackageFile( string $assetsPath = '/w/extensions' ): array {
		return Bundle::makePackageFile(
			$this->createMock( Context::class ),
			new HashConfig( [ MainConfigNames::ExtensionAssetsPath => $assetsPath ] )
		);
	}

	/**
	 * modules/ext.aggrid/lazyMount.js destructures `src` off this file. Were the
	 * key ever renamed, the client would set script.src to undefined and every
	 * grid would fail to load — and no JS test would see it, because vitest
	 * resolves the committed placeholder rather than the generated file.
	 */
	public function testExposesOnlyTheSrcKeyLazyMountDestructures(): void {
		$this->assertSame( [ 'src' ], array_keys( $this->makePackageFile() ) );
	}

	public function testSrcPointsAtTheBundleBelowTheConfiguredAssetsPath(): void {
		$this->assertStringStartsWith(
			'/w/extensions/AGGrid/modules/lib/ag-grid-community/ag-grid-community.min.js?v=',
			$this->makePackageFile()['src']
		);
	}

	public function testSrcHonoursExtensionAssetsPath(): void {
		$this->assertStringStartsWith(
			'https://cdn.example/ext/AGGrid/modules/',
			$this->makePackageFile( 'https://cdn.example/ext' )['src']
		);
	}

	public function testCacheBusterTracksTheBundleContents(): void {
		$expected = substr( hash_file( 'sha256', $this->bundlePath() ), 0, 12 );

		$this->assertStringEndsWith( '?v=' . $expected, $this->makePackageFile()['src'] );
	}

	/**
	 * A packageFiles callback never sees the module, so the module's base paths
	 * have to be restated here. Nothing at runtime reconciles the two: change
	 * remoteExtPath and every grid 404s with the whole suite green; change
	 * localBasePath and the file that gets hashed stops being the file the
	 * browser fetches — a silently frozen cache-buster, which is the exact
	 * failure this class exists to prevent.
	 *
	 * @dataProvider provideBasePaths
	 */
	public function testBasePathsMatchExtensionJson( string $key, string $constant ): void {
		$paths = $this->extensionJson()['ResourceFileModulePaths'];

		$this->assertSame( $paths[$key], $constant );
	}

	public static function provideBasePaths(): array {
		return [
			'remoteExtPath' => [ 'remoteExtPath', Bundle::REMOTE_MODULE_PATH ],
			'localBasePath' => [ 'localBasePath', Bundle::LOCAL_MODULE_PATH ],
		];
	}

	/**
	 * The bundle path is resolved two ways: relative to the module base (what
	 * the browser fetches, and what ResourceLoader versions the module against)
	 * and relative to this class (what gets hashed). A moved or renamed vendor
	 * directory must fail here rather than at module-build time on a live wiki.
	 */
	public function testBundlePathResolvesFromTheModuleBasePath(): void {
		$this->assertFileIsReadable( $this->bundlePath() );
	}

	public function testVersionFilePathPointsAtTheBundle(): void {
		$this->assertSame( Bundle::BUNDLE, $this->versionFilePath()->getPath() );
	}

	/**
	 * ResourceLoader mutates the returned FilePath via initBasePaths(), so a
	 * shared instance would leak base paths between callers.
	 */
	public function testVersionFilePathIsFreshEachCall(): void {
		$this->assertNotSame( $this->versionFilePath(), $this->versionFilePath() );
	}

	private function versionFilePath() {
		return Bundle::getVersionFilePath(
			$this->createMock( Context::class ),
			new HashConfig()
		);
	}

	private function extensionJson(): array {
		return json_decode(
			file_get_contents( self::EXTENSION_DIR . '/extension.json' ),
			true
		);
	}

	private function bundlePath(): string {
		return self::EXTENSION_DIR . '/' . Bundle::LOCAL_MODULE_PATH . '/' . Bundle::BUNDLE;
	}
}
