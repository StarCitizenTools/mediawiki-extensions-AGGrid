// Built-in opt-in quick-search box (issue #21): a toolbar emulating AG Grid
// Enterprise's Quick Access Toolbar (`toolbar` gridOption + agQuickFilterToolbarItem),
// whose component is absent from the Community bundle. The bundle still paints the
// magnifier (the .ag-icon-search mask ships with the icon CSS), but the structural
// .ag-toolbar* rules are emitted only with the Enterprise component, so the layout
// lives in ext.aggrid.less (.ext-aggrid-toolbar), built from the --ag-* variables.
// The toolbar is inserted INSIDE the grid's .ag-root-wrapper so those variables
// (mapped from Codex tokens by theme.js, light-dark() values) are in scope — the box
// matches the grid theme and follows dark mode. Client-side row model only: AG Grid
// hard-validates quickFilterText against the infinite row model, so mountGrid never
// wires this on backend grids.

const DEFAULT_DEBOUNCE_MS = 200;
const MAX_DEBOUNCE_MS = 5000;

// Assign a class string. Centralised so the AG Grid theme classes we reuse (ag-*) and
// our own ext-aggrid-toolbar* hooks are set in one place; assigning via a variable also
// keeps the class-doc lint (which only inspects string literals) satisfied.
function setClass( el, classes ) {
	el.className = classes;
	return el;
}

/**
 * Trailing-edge debounce. A zero/absent wait returns fn unwrapped, so an
 * author-configured debounceMs of 0 means "apply on every keystroke".
 *
 * @param {Function} fn
 * @param {number} wait Milliseconds.
 * @return {Function}
 */
function debounce( fn, wait ) {
	if ( !wait ) {
		return fn;
	}
	let timer = null;
	return ( value ) => {
		clearTimeout( timer );
		timer = setTimeout( () => fn( value ), wait );
	};
}

/**
 * Normalize the author's quickSearch gridOption into a config, or null when disabled.
 *
 * Accepted shapes: true; { placeholder?, debounceMs? }; and [] — an empty Lua table
 * arrives as a JSON array via LuaSequence normalization. Anything else disables the
 * box: LuaLibrary rejects bad shapes at parse time for new parses, but parser-cache
 * entries can predate that validation, so this stays defensive.
 *
 * @param {*} raw gridOptions.quickSearch as parsed from the placeholder JSON.
 * @return {Object|null} { placeholder: string|null, debounceMs: number } or null.
 */
function normalize( raw ) {
	if ( raw === true || ( Array.isArray( raw ) && raw.length === 0 ) ) {
		return { placeholder: null, debounceMs: DEFAULT_DEBOUNCE_MS };
	}
	if ( !raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return null;
	}
	const placeholder = typeof raw.placeholder === 'string' ? raw.placeholder : null;
	let debounceMs = DEFAULT_DEBOUNCE_MS;
	if ( typeof raw.debounceMs === 'number' && Number.isFinite( raw.debounceMs ) ) {
		debounceMs = Math.min( MAX_DEBOUNCE_MS, Math.max( 0, Math.round( raw.debounceMs ) ) );
	}
	return { placeholder, debounceMs };
}

/**
 * Build the quick-search toolbar inside the grid's root wrapper and wire it to the
 * quick filter. Called by mountGrid after createGrid and before gridReady fires, so
 * hook subscribers observe the final grid chrome (a gadget can detect
 * .ext-aggrid-toolbar and skip wiring its own search box).
 *
 * @param {HTMLElement} el The .ext-aggrid container (post-createGrid).
 * @param {Object} api The AG Grid GridApi.
 * @param {Object} config Normalized config from normalize().
 */
function setup( el, api, config ) {
	const rootWrapper = el.querySelector( '.ag-root-wrapper' );
	if ( !rootWrapper ) {
		mw.log.warn( '[ext.aggrid] quickSearch: no .ag-root-wrapper to attach the toolbar to' );
		return;
	}

	const toolbar = setClass( document.createElement( 'div' ), 'ag-toolbar ext-aggrid-toolbar' );
	toolbar.setAttribute( 'role', 'toolbar' );
	const item = setClass( document.createElement( 'div' ),
		'ag-toolbar-item ag-toolbar-input ext-aggrid-toolbar__search' );
	const icon = setClass( document.createElement( 'span' ),
		'ag-toolbar-input-icon ext-aggrid-toolbar__icon' );
	icon.setAttribute( 'aria-hidden', 'true' );
	icon.appendChild( setClass( document.createElement( 'span' ), 'ag-icon ag-icon-search' ) );
	const input = setClass( document.createElement( 'input' ),
		'ag-toolbar-input-field ext-aggrid-toolbar__input' );
	input.type = 'search';
	input.name = 'aggrid-quicksearch';
	// DOM property assignment: an author-supplied placeholder cannot inject markup.
	input.placeholder = config.placeholder !== null ?
		config.placeholder :
		mw.msg( 'aggrid-quicksearch-placeholder' );
	input.setAttribute( 'aria-label', mw.msg( 'aggrid-quicksearch-label' ) );

	// No teardown: a pending timer (≤5 s) may fire one setGridOption after a grid
	// is torn down (e.g. live-preview re-render) — harmless, and the extension
	// never destroys grids itself, so listener plumbing isn't worth it.
	const apply = debounce( ( value ) => {
		api.setGridOption( 'quickFilterText', value );
	}, config.debounceMs );
	input.addEventListener( 'input', () => apply( input.value ) );
	// type=search gives a native clear control in Blink/WebKit; Escape covers the rest.
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && input.value !== '' ) {
			input.value = '';
			apply( '' );
		}
	} );

	item.appendChild( icon );
	item.appendChild( input );
	toolbar.appendChild( item );
	rootWrapper.insertBefore( toolbar, rootWrapper.firstChild );
}

module.exports = { normalize, setup };
