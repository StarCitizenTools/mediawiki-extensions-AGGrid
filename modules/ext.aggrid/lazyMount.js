const { mountGrid, mountAll } = require( './mountGrid.js' );

const PLACEHOLDER_SELECTOR = '.ext-aggrid';
const INIT_CLASS = 'ext-aggrid--init';
// AG Grid version, kept in sync with modules/lib/foreign-resources.yaml. Used to
// cache-bust the static script URL when the vendored bundle is updated.
const AG_GRID_VERSION = '35.3.0';
// Start loading slightly before a grid scrolls into view.
const ROOT_MARGIN = '200px';

let loadPromise = null;
let observer = null;

/**
 * Load the AG Grid bundle from its static asset URL, once. Memoised so
 * simultaneous callers share a single <script> injection.
 *
 * Loaded statically (not via ResourceLoader) because wikimedia/minify <= 2.10.0
 * corrupts AG Grid's ES2020 BigInt literals when minifying it.
 * Once a MediaWiki release ships a BigInt-aware minifier, this can become a
 * normal ResourceLoader `scripts` module.
 *
 * @return {Promise}
 */
function loadAgGrid() {
	if ( loadPromise ) {
		return loadPromise;
	}
	loadPromise = new Promise( ( resolve, reject ) => {
		if ( window.agGrid ) {
			resolve();
			return;
		}
		const base = mw.config.get( 'wgExtensionAssetsPath' );
		const src = base + '/AGGrid/modules/lib/ag-grid-community/ag-grid-community.min.js?v=' + AG_GRID_VERSION;
		const script = document.createElement( 'script' );
		script.src = src;
		script.onload = () => resolve();
		script.onerror = () => {
			loadPromise = null;
			script.remove();
			reject( new Error( 'failed to load AG Grid bundle' ) );
		};
		document.head.appendChild( script );
	} );
	return loadPromise;
}

/**
 * Return the module-level singleton IntersectionObserver, creating it on first
 * call. Re-using the same observer across lazyMount() calls means old observers
 * are never left holding references to detached nodes (e.g. after a VisualEditor
 * save or live-preview re-render).
 *
 * @return {IntersectionObserver}
 */
function getObserver() {
	if ( !observer ) {
		observer = new IntersectionObserver( ( entries, obs ) => {
			entries.forEach( ( entry ) => {
				if ( entry.isIntersecting ) {
					loadAgGrid()
						.then( () => {
							obs.unobserve( entry.target );
							mountGrid( entry.target );
						} )
						.catch( ( e ) => mw.log.error( '[ext.aggrid] ' + e.message ) );
				}
			} );
		}, { rootMargin: ROOT_MARGIN } );
	}
	return observer;
}

/**
 * Lazily mount every AG Grid placeholder within a root: load the bundle and
 * mount each grid as it nears the viewport. Falls back to eager mounting where
 * IntersectionObserver is unavailable.
 *
 * @param {HTMLElement|Document} [root] Scope to search; defaults to document.
 */
function lazyMount( root ) {
	const scope = root || document;

	if ( !window.IntersectionObserver ) {
		loadAgGrid()
			.then( () => mountAll( scope ) )
			.catch( ( e ) => mw.log.error( '[ext.aggrid] ' + e.message ) );
		return;
	}

	const obs = getObserver();
	Array.prototype.forEach.call(
		scope.querySelectorAll( PLACEHOLDER_SELECTOR ),
		( el ) => {
			if ( !el.classList.contains( INIT_CLASS ) ) {
				obs.observe( el );
			}
		}
	);
}

module.exports = { loadAgGrid: loadAgGrid, lazyMount: lazyMount };
