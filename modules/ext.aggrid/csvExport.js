// The export defaults go on gridOptions.defaultCsvExportParams, not on the button's call,
// so a gadget calling api.exportDataAsCsv() gets them too. Params passed to that call still
// override them (gadget code is trusted); the author's defaultCsvExportParams from Lua is
// not, and cannot switch the formula guard off.

const { setClass, normalizeButtonOption } = require( './toolbar.js' );

// AG Grid's list of leading characters a spreadsheet runs as a formula, plus line feed
// (OWASP). Prefixed with an apostrophe, not OWASP's tab: tab is itself on AG Grid's list.
const FORMULA_START = /^[=+\-@\t\r\n]/;

const UNSAFE_FILE_CHARS = /[\\/:*?"<>|]/g;

// Unquoted, a newline or separator inside a cell starts a new, unchecked cell; prepended
// and appended content is written out verbatim.
const UNSAFE_AUTHOR_KEYS = [ 'suppressQuotes', 'prependContent', 'appendContent' ];

/**
 * @param {*} value
 * @return {*}
 */
function neutralize( value ) {
	return typeof value === 'string' && FORMULA_START.test( value ) ? `'${ value }` : value;
}

/**
 * @param {Object} params AG Grid ProcessCellForExportParams.
 * @return {*}
 */
function processCell( params ) {
	const value = params.value;
	if ( params.column.getColDef().useValueFormatterForExport === false ) {
		return neutralize( value );
	}
	const text = params.formatValue( value );
	// AG Grid infers a formatter for a plain column that only stringifies (-5 → "-5",
	// ['a','b'] → "a,b"): keep such a number a number and join such an array. A column
	// type's own formatter is used as it is.
	if ( String( text ) === String( value ) ) {
		if ( typeof value === 'number' ) {
			return value;
		}
		if ( Array.isArray( value ) ) {
			return neutralize( value.join( ', ' ) );
		}
	}
	return neutralize( text );
}

/**
 * The export defaults for a grid on the given page.
 *
 * @param {string|null} pageTitle wgTitle; names the downloaded file.
 * @param {*} [authorParams] The author's defaultCsvExportParams, from the placeholder JSON.
 * @return {Object} CsvExportParams for gridOptions.defaultCsvExportParams.
 */
function defaultParams( pageTitle, authorParams ) {
	const params = {};
	if ( pageTitle ) {
		// AG Grid appends the extension only to a name with no dot in it.
		params.fileName = `${ pageTitle.replace( UNSAFE_FILE_CHARS, '_' ) }.csv`;
	}
	if ( authorParams && typeof authorParams === 'object' && !Array.isArray( authorParams ) ) {
		Object.assign( params, authorParams );
		UNSAFE_AUTHOR_KEYS.forEach( ( key ) => delete params[ key ] );
	}
	// Last: from Lua, the author's callbacks can only be non-functions, which would switch
	// the guard off or break the export.
	return Object.assign( params, {
		processCellCallback: processCell,
		// The locations AG Grid's own export reads header names with.
		processHeaderCallback: ( p ) => neutralize(
			p.api.getDisplayNameForColumn( p.column, 'csv' )
		),
		processGroupHeaderCallback: ( p ) => neutralize(
			p.api.getDisplayNameForColumnGroup( p.columnGroup, 'header' )
		)
	} );
}

/**
 * Build the export button.
 *
 * @param {Object} api The AG Grid GridApi.
 * @param {Object} config Normalized config from normalizeButtonOption().
 * @return {HTMLElement} The toolbar item.
 */
function buildItem( api, config ) {
	const item = setClass( document.createElement( 'div' ),
		'ag-toolbar-item ag-toolbar-button-wrapper ext-aggrid-toolbar__item' );
	const button = setClass( document.createElement( 'button' ),
		'ag-toolbar-button ext-aggrid-toolbar__button' );
	button.type = 'button';
	const label = config.label || mw.msg( 'aggrid-csvexport-label' );
	button.setAttribute( 'aria-label', label );
	button.setAttribute( 'title', label );
	const icon = setClass( document.createElement( 'span' ), 'ag-icon ag-icon-csv' );
	icon.setAttribute( 'aria-hidden', 'true' );
	button.appendChild( icon );
	// No params: they would override the author's defaultCsvExportParams.
	button.addEventListener( 'click', () => api.exportDataAsCsv() );
	item.appendChild( button );
	return item;
}

module.exports = { normalize: normalizeButtonOption, defaultParams, buildItem };
