// Built-in opt-in CSV export: a toolbar button that downloads the grid's rows through
// AG Grid Community's exportDataAsCsv(), plus the export defaults every grid gets.
//
// The defaults go on gridOptions.defaultCsvExportParams rather than on the button's
// call, so they also cover a gadget calling api.exportDataAsCsv() itself. AG Grid merges
// them as Object.assign( {}, defaultCsvExportParams, params ), so a caller's own params
// still override them.

const { setClass } = require( './toolbar.js' );

// A spreadsheet opening a CSV runs a cell starting with one of these as a formula. The
// list is AG Grid's ("Security Concerns" in its CSV export docs) plus line feed, which
// OWASP adds. Wiki editors write the cells and readers open the file, so the reader is
// the one at risk: prefix such text with an apostrophe to make it read as text. Not a
// leading tab, which OWASP now suggests — tab is itself on AG Grid's list.
const FORMULA_START = /^[=+\-@\t\r\n]/;

// Characters a page title may hold but a file name may not, on some platform.
const UNSAFE_FILE_CHARS = /[\\/:*?"<>|]/g;

/**
 * Normalize the author's csvExport gridOption into a config, or null when disabled.
 *
 * Accepted: true; { label? }; and [], an empty Lua table arriving as a JSON array.
 * Anything else disables the button — LuaLibrary rejects bad shapes at parse time, but
 * parser-cache entries can predate that validation, so this stays defensive.
 *
 * @param {*} raw gridOptions.csvExport as parsed from the placeholder JSON.
 * @return {Object|null} { label: string|null } or null.
 */
function normalize( raw ) {
	if ( raw === true || ( Array.isArray( raw ) && raw.length === 0 ) ) {
		return { label: null };
	}
	if ( !raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return null;
	}
	return { label: typeof raw.label === 'string' ? raw.label : null };
}

/**
 * Stop a spreadsheet from running exported text as a formula.
 *
 * @param {*} value
 * @return {*} The value, apostrophe-prefixed if it is text starting with a formula
 *   character. Numbers are never prefixed, so negative ones stay numbers.
 */
function neutralize( value ) {
	return typeof value === 'string' && FORMULA_START.test( value ) ? `'${ value }` : value;
}

/**
 * Export one cell. Rich cells (links, images, lists) export their display text via the
 * column's valueFormatter; a column with useValueFormatterForExport=false — every column
 * with a `format` spec, see mountGrid.js — exports its raw value, since a CSV is for
 * reuse in a spreadsheet, where "27 kg" or a locale's date wording is not data.
 *
 * @param {Object} params AG Grid ProcessCellForExportParams.
 * @return {*}
 */
function processCell( params ) {
	const value = params.value;
	// Numbers go out as numbers. AG Grid infers a formatter for a plain number column,
	// so formatValue() would turn -5 into the text "-5", which neutralize() then prefixes.
	if ( typeof value === 'number' ) {
		return value;
	}
	// A plain multi-value cell, joined like a list cell rather than AG Grid's "a,b".
	if ( Array.isArray( value ) ) {
		return neutralize( value.join( ', ' ) );
	}
	const raw = params.column.getColDef().useValueFormatterForExport === false;
	return neutralize( raw ? value : params.formatValue( value ) );
}

/**
 * The export defaults for a grid on the given page.
 *
 * @param {string|null} pageTitle wgTitle; names the downloaded file.
 * @return {Object} CsvExportParams for gridOptions.defaultCsvExportParams.
 */
function defaultParams( pageTitle ) {
	const params = {
		processCellCallback: processCell,
		processHeaderCallback: ( p ) => neutralize(
			p.api.getDisplayNameForColumn( p.column, 'csv' )
		),
		processGroupHeaderCallback: ( p ) => neutralize(
			p.api.getDisplayNameForColumnGroup( p.columnGroup, 'csv' )
		)
	};
	if ( pageTitle ) {
		// Always spelled out: AG Grid appends the extension only to a name with no dot
		// in it, so "St. Bernard" would otherwise download without one.
		params.fileName = `${ pageTitle.replace( UNSAFE_FILE_CHARS, '_' ) }.csv`;
	}
	return params;
}

/**
 * Build the export button.
 *
 * @param {Object} api The AG Grid GridApi.
 * @param {Object} config Normalized config from normalize().
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

module.exports = { normalize, defaultParams, buildItem };
