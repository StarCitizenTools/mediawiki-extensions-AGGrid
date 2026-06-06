/* global agGrid */

const { getWikiTheme } = require( './theme.js' );
const { buildColumnTypes } = require( './renderers.js' );
const { SetFilter } = require( './setFilter.js' );

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
 * Build the REST path for a placeholder that fetches its rows, or null if it
 * does not carry a complete handle.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @return {string|null}
 */
function restPath( el ) {
	const pageid = el.getAttribute( 'data-mw-aggrid-pageid' );
	const rev = el.getAttribute( 'data-mw-aggrid-rev' );
	const index = el.getAttribute( 'data-mw-aggrid-index' );
	if ( !pageid || !rev || index === null ) {
		return null;
	}
	return '/aggrid/v0/grid/' + pageid + '/' + rev + '/' + index + '/rows';
}

/**
 * Drop the loading skeleton/busy state and create the grid.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions Fully-populated gridOptions (rowData present).
 */
function finishMount( el, gridOptions ) {
	// Apply the wiki theme unless the author already chose one.
	if ( !gridOptions.theme ) {
		gridOptions.theme = getWikiTheme();
	}
	// Register the built-in rich-cell column types (link/image/link-list). Built-ins
	// win over author-supplied entries of the same reserved name — those can't carry
	// the cellRenderer function across the JSON boundary anyway.
	gridOptions.columnTypes = Object.assign(
		{}, gridOptions.columnTypes, buildColumnTypes()
	);
	// Register the built-in set filter under its reserved name. Built-in wins over an
	// author entry of the same name (a real component can't cross the JSON boundary anyway).
	gridOptions.components = Object.assign(
		{}, gridOptions.components, { aggridSet: SetFilter }
	);
	// AG Grid expects an empty container.
	const skeleton = el.querySelector( '.ext-aggrid__skeleton' );
	if ( skeleton ) {
		skeleton.remove();
	}
	el.removeAttribute( 'aria-busy' );
	// agGrid is the global exposed by the vendored AG Grid bundle.
	agGrid.createGrid( el, gridOptions );
}

/**
 * Mount the grid in an error state: render the column headers from the config
 * with no rows, and surface "data failed to load" via AG Grid's overlay.
 * Terminal — the element keeps INIT_CLASS so it is not retried (lazyMount has
 * already unobserved it).
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions Parsed gridOptions (rowData absent/unusable).
 */
function mountError( el, gridOptions ) {
	gridOptions.rowData = [];
	// AG Grid has no dedicated error overlay; show the message via the no-rows
	// overlay, which is auto-shown for an empty client-side row model. The
	// interface message is escaped before being injected into the overlay HTML.
	gridOptions.overlayNoRowsTemplate =
		'<span class="ext-aggrid__overlay-error">' +
		mw.html.escape( mw.msg( 'aggrid-error-load' ) ) +
		'</span>';
	finishMount( el, gridOptions );
}

/**
 * Mount an AG Grid into a single placeholder. No-op if already mounted.
 *
 * Inline/preview placeholders carry rowData and mount immediately. Otherwise the
 * rows are fetched once over REST (client-side row model) before mounting.
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
	// Mark before any async work so a concurrent/re-entrant pass can't double-mount.
	el.classList.add( INIT_CLASS );

	if ( Array.isArray( gridOptions.rowData ) ) {
		finishMount( el, gridOptions );
		return;
	}

	const path = restPath( el );
	if ( !path ) {
		mw.log.error( '[ext.aggrid] placeholder has neither rowData nor a fetch handle' );
		mountError( el, gridOptions );
		return;
	}
	new mw.Rest().get( path )
		.then( ( data ) => {
			gridOptions.rowData = ( data && data.rows ) || [];
			finishMount( el, gridOptions );
		} )
		.catch( ( e ) => {
			mw.log.error( '[ext.aggrid] failed to fetch grid rows', e );
			mountError( el, gridOptions );
		} );
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
