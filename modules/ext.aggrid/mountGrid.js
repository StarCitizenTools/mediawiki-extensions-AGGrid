/* global agGrid */

const { getWikiTheme } = require( './theme.js' );

const PLACEHOLDER_SELECTOR = '.ext-aggrid';
const CONFIG_ATTR = 'data-mw-aggrid-options';
// Marks a placeholder as already mounted so re-runs (e.g. wikipage.content
// firing on initial + re-rendered content) never call createGrid twice on it.
const INIT_CLASS = 'ext-aggrid--init';

/**
 * Read and parse the gridOptions carried in the placeholder's config attribute.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @return {Object|null} Parsed gridOptions, or null if absent/invalid.
 */
function parseConfig( el ) {
	const raw = el.getAttribute( CONFIG_ATTR );
	if ( !raw ) {
		return null;
	}
	try {
		return JSON.parse( raw );
	} catch ( e ) {
		mw.log.error( '[ext.aggrid] Failed to parse grid config', e );
		return null;
	}
}

/**
 * Mount an AG Grid into a single placeholder. No-op if already mounted.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 */
function mountGrid( el ) {
	if ( el.classList.contains( INIT_CLASS ) ) {
		return;
	}
	const gridOptions = parseConfig( el );
	if ( !gridOptions ) {
		return;
	}
	// Apply the wiki theme unless the author already chose one.
	if ( !gridOptions.theme ) {
		gridOptions.theme = getWikiTheme();
	}
	// Mark before mounting so a concurrent/re-entrant pass can't double-mount.
	el.classList.add( INIT_CLASS );
	// Drop the server-rendered loading skeleton and busy state; AG Grid expects
	// an empty container.
	const skeleton = el.querySelector( '.ext-aggrid__skeleton' );
	if ( skeleton ) {
		skeleton.remove();
	}
	el.removeAttribute( 'aria-busy' );
	// agGrid is the global exposed by the vendored AG Grid bundle.
	agGrid.createGrid( el, gridOptions );
}

/**
 * Mount every AG Grid placeholder within a root (defaults to the document).
 *
 * @param {HTMLElement|Document} [root] Scope to search; defaults to document.
 */
function mountAll( root ) {
	const scope = root || document;
	Array.prototype.forEach.call(
		scope.querySelectorAll( PLACEHOLDER_SELECTOR ),
		mountGrid
	);
}

module.exports = { parseConfig: parseConfig, mountGrid: mountGrid, mountAll: mountAll };
