const { mountAll } = require( './mountGrid.js' );

// AG Grid version, kept in sync with modules/lib/foreign-resources.yaml. Used to
// cache-bust the static script URL when the vendored bundle is updated.
const AG_GRID_VERSION = '35.3.0';

/**
 * Load the AG Grid Community bundle and resolve once `window.agGrid` is ready.
 *
 * The bundle is loaded from its static extension asset URL rather than through
 * ResourceLoader, because wikimedia/minify <= 2.10.0 corrupts AG Grid's BigInt
 * literals (`0n`) when minifying it. Serving the file statically skips that
 * minification. Once a MediaWiki release ships a BigInt-aware minifier this can
 * be replaced by a normal `scripts` ResourceLoader module.
 *
 * @return {Promise}
 */
function loadAgGrid() {
	return new Promise( ( resolve, reject ) => {
		if ( window.agGrid ) {
			resolve();
			return;
		}
		const base = mw.config.get( 'wgExtensionAssetsPath' );
		const src = base + '/AGGrid/modules/lib/ag-grid-community/ag-grid-community.min.js?v=' + AG_GRID_VERSION;
		const script = document.createElement( 'script' );
		script.src = src;
		script.onload = () => resolve();
		script.onerror = () => reject( new Error( 'failed to load AG Grid bundle' ) );
		document.head.appendChild( script );
	} );
}

// Mount on every content render — initial load and re-rendered content alike
// (VisualEditor save, live preview, AJAX, lazy Tabber panels). The window.agGrid
// guard makes loadAgGrid() a no-op after the first call, and mountGrid skips
// already-mounted placeholders, so repeated firings are cheap and idempotent.
mw.hook( 'wikipage.content' ).add( ( $content ) => {
	loadAgGrid()
		.then( () => mountAll( $content[ 0 ] ) )
		.catch( ( e ) => {
			mw.log.error( '[ext.aggrid] ' + e.message );
		} );
} );
