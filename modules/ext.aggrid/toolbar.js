// The grid's toolbar, holding the controls the extension's own gridOptions add
// (quickSearch, csvExport, expand).
//
// It mirrors the DOM of AG Grid Enterprise's Quick Access Toolbar, whose components
// the Community bundle registers by name only, to raise "module not loaded" errors.
// Reusing that shape means the classes below read the same as AG Grid's, but the
// bundle emits the structural .ag-toolbar* rules only alongside the Enterprise
// component — so the layout lives in ext.aggrid.less instead. The toolbar goes
// INSIDE .ag-root-wrapper so the --ag-* variables are in scope, and so the grid and
// its chrome travel as one subtree when expand.js moves them into its dialog.

const TOOLBAR_CLASS = 'ext-aggrid-toolbar';

/**
 * Assign a class string. Centralised because the class-doc lint only inspects
 * string literals.
 *
 * @param {HTMLElement} el
 * @param {string} classes
 * @return {HTMLElement} el, for chaining.
 */
function setClass( el, classes ) {
	el.className = classes;
	return el;
}

/**
 * Return the grid's toolbar, creating and inserting it on first call.
 *
 * Deliberately not given `role="toolbar"`: that promises roving-tabindex arrow-key
 * navigation we do not implement, over a text input that needs the arrows.
 *
 * @param {HTMLElement} el The .ext-aggrid container (post-createGrid).
 * @return {HTMLElement|null} The toolbar, or null when the grid root is missing.
 */
function ensure( el ) {
	const rootWrapper = el.querySelector( '.ag-root-wrapper' );
	if ( !rootWrapper ) {
		mw.log.warn( '[ext.aggrid] toolbar: no .ag-root-wrapper to attach to' );
		return null;
	}
	const existing = rootWrapper.querySelector( `:scope > .${ TOOLBAR_CLASS }` );
	if ( existing ) {
		return existing;
	}
	const toolbar = setClass( document.createElement( 'div' ), `ag-toolbar ${ TOOLBAR_CLASS }` );
	rootWrapper.insertBefore( toolbar, rootWrapper.firstChild );
	return toolbar;
}

/**
 * Normalize a toolbar-button gridOption (csvExport, expand) into a config, or null when
 * disabled.
 *
 * Accepted: true; { label? }; and [], an empty Lua table arriving as a JSON array.
 * Anything else disables the button — LuaLibrary rejects bad shapes at parse time, but
 * parser-cache entries can predate that validation, so this stays defensive.
 *
 * @param {*} raw The gridOption as parsed from the placeholder JSON.
 * @return {Object|null} { label: string|null } or null.
 */
function normalizeButtonOption( raw ) {
	if ( raw === true || ( Array.isArray( raw ) && raw.length === 0 ) ) {
		return { label: null };
	}
	if ( !raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return null;
	}
	return { label: typeof raw.label === 'string' ? raw.label : null };
}

/**
 * Append an item to the toolbar.
 *
 * @param {HTMLElement} toolbar From ensure().
 * @param {HTMLElement} item
 * @param {Object} [options]
 * @param {boolean} [options.end] Push this item, and everything after it, to the
 *   trailing edge.
 */
function addItem( toolbar, item, options ) {
	if ( options && options.end ) {
		item.classList.add( 'ag-toolbar-right-start', 'ext-aggrid-toolbar__item--end' );
	}
	toolbar.appendChild( item );
}

module.exports = { ensure, addItem, setClass, normalizeButtonOption };
