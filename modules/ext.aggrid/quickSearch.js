// Built-in opt-in quick-search box (issue #21), as one toolbar item; the container is
// toolbar.js's job. The box is row-model agnostic: buildItem() takes an onApply
// callback, so client grids drive AG Grid's quickFilterText while backend grids route
// the term to the server (the quick filter is client-model-only).

const { setClass } = require( './toolbar.js' );

const DEFAULT_DEBOUNCE_MS = 200;
const MAX_DEBOUNCE_MS = 5000;

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
 * Build the quick-search item and wire it to the quick filter.
 *
 * A gadget detecting the built-in box must look for .ext-aggrid-toolbar__search, not
 * .ext-aggrid-toolbar — the toolbar is shared with the expand button.
 *
 * @param {Object} api The AG Grid GridApi.
 * @param {Object} config Normalized config from normalize().
 * @param {Function} [onApply] Called with the current value on each (debounced) change
 *   and on Escape-clear. Defaults to `setGridOption('quickFilterText', value)`;
 *   backend grids inject a handler that routes the term to the server instead.
 * @return {HTMLElement} The toolbar item.
 */
function buildItem( api, config, onApply ) {
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

	// No teardown: a pending timer (≤5 s) may fire one apply after a grid is torn
	// down (e.g. live-preview re-render) — harmless, and the extension never
	// destroys grids itself, so listener plumbing isn't worth it.
	const applyValue = onApply ||
		( ( value ) => api.setGridOption( 'quickFilterText', value ) );
	const apply = debounce( applyValue, config.debounceMs );
	input.addEventListener( 'input', () => apply( input.value ) );
	// type=search gives a native clear control in Blink/WebKit; Escape covers the rest.
	input.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' && input.value !== '' ) {
			input.value = '';
			apply( '' );
			// Clearing the box consumes the key. Without this the same Escape also
			// reaches an enclosing expand dialog and collapses the grid, losing the
			// view the reader was only trying to reset.
			e.preventDefault();
		}
	} );

	item.appendChild( icon );
	item.appendChild( input );
	return item;
}

module.exports = { normalize, buildItem };
