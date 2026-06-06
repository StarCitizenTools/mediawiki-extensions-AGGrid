// Custom AG Grid (Community) Set Filter: a checkbox list of a column's unique values.
//
// AG Grid Community ships no Set Filter; this is a vanilla IFilterComp registered by name
// (aggridSet) and referenced from a colDef as `filter: 'aggridSet'`. Values are derived
// from the loaded rows via each column's display scalar — the same valueFormatter output
// that sort and quick-search use — so a rich link/image column filters on its text, not the
// raw object. Every label is built as a text node; nothing uses innerHTML.

// Empty/null/undefined values collapse under this single key (shown as the blanks message).
const BLANK_KEY = '';

// A node's display value for this column: the valueFormatter output when the column has one
// (rich columns do), else the raw value. getCellValue with useFormatter is the v32+ API.
function displayValue( api, column, node ) {
	return api.getCellValue( { rowNode: node, colKey: column, useFormatter: true } );
}

function isBlank( v ) {
	return v === null || v === undefined || v === '';
}

// Map a display value to its filter key: '' for blanks, else its string form.
function keyOf( v ) {
	return isBlank( v ) ? BLANK_KEY : String( v );
}

/**
 * Walk all loaded leaf rows and tally unique display values for a column.
 *
 * This is the single seam where filter values come from. Today it reads the full loaded
 * client-side dataset (matching AG Grid's default: the value list is not narrowed by other
 * active filters). A future server-side/async value source replaces only this function.
 *
 * @param {Object} api AG Grid GridApi.
 * @param {Object} column AG Grid Column (or column id).
 * @return {Map<string,number>} Insertion-ordered key → row count.
 */
function deriveValues( api, column ) {
	const counts = new Map();
	api.forEachLeafNode( ( node ) => {
		const key = keyOf( displayValue( api, column, node ) );
		counts.set( key, ( counts.get( key ) || 0 ) + 1 );
	} );
	return counts;
}

/**
 * AG Grid IFilterComp. One instance per filtered column.
 */
function SetFilter() {}

SetFilter.prototype.init = function ( params ) {
	this.params = params;
	this.counts = deriveValues( params.api, params.column );
	this.allKeys = Array.from( this.counts.keys() );
	// Inactive to start: every value selected.
	this.selected = new Set( this.allKeys );
	this.items = [];
	this.gui = this.buildGui();
};

SetFilter.prototype.getGui = function () {
	return this.gui;
};

// Active iff at least one value is unchecked. (Ignores stale keys a setModel might carry.)
SetFilter.prototype.isFilterActive = function () {
	return this.allKeys.some( ( key ) => !this.selected.has( key ) );
};

SetFilter.prototype.doesFilterPass = function ( params ) {
	const key = keyOf( displayValue( this.params.api, this.params.column, params.node ) );
	return this.selected.has( key );
};

SetFilter.prototype.getModel = function () {
	return this.isFilterActive() ? { values: Array.from( this.selected ) } : null;
};

SetFilter.prototype.setModel = function ( model ) {
	this.selected = model && Array.isArray( model.values ) ?
		new Set( model.values ) :
		new Set( this.allKeys );
	this.refreshSelectionUi();
};

// --- GUI ---------------------------------------------------------------------------------

SetFilter.prototype.buildGui = function () {
	const root = document.createElement( 'div' );
	root.className = 'ext-aggrid-setfilter';

	const search = document.createElement( 'input' );
	search.type = 'text';
	search.className = 'ext-aggrid-setfilter__search';
	search.setAttribute( 'placeholder', mw.msg( 'aggrid-setfilter-search-placeholder' ) );
	search.addEventListener( 'input', () => this.onSearch( search.value ) );
	root.appendChild( search );

	const list = document.createElement( 'ul' );
	list.className = 'ext-aggrid-setfilter__list';

	// Select-all row (tri-state).
	const allCb = document.createElement( 'input' );
	allCb.type = 'checkbox';
	allCb.className = 'ext-aggrid-setfilter__cb';
	allCb.checked = true;
	allCb.addEventListener( 'change', () => this.onSelectAll( allCb.checked ) );
	this.selectAllCb = allCb;
	list.appendChild( this.row(
		'ext-aggrid-setfilter__item--all', allCb, mw.msg( 'aggrid-setfilter-select-all' ), null
	) );

	// One row per value.
	this.counts.forEach( ( count, key ) => {
		const cb = document.createElement( 'input' );
		cb.type = 'checkbox';
		cb.className = 'ext-aggrid-setfilter__cb';
		cb.checked = true;
		cb.addEventListener( 'change', () => this.onToggle( key, cb.checked ) );
		const label = key === BLANK_KEY ? mw.msg( 'aggrid-setfilter-blanks' ) : key;
		const li = this.row( 'ext-aggrid-setfilter__item--value', cb, label, count );
		this.items.push( { key: key, li: li, cb: cb, label: label } );
		list.appendChild( li );
	} );

	root.appendChild( list );
	return root;
};

// Build one <li><label><cb> text <count?></label></li>. count === null omits the count.
SetFilter.prototype.row = function ( modifier, cb, text, count ) {
	const li = document.createElement( 'li' );
	// modifier is one of the documented --all / --value literals from the call sites.
	// eslint-disable-next-line mediawiki/class-doc
	li.className = 'ext-aggrid-setfilter__item ' + modifier;
	const label = document.createElement( 'label' );
	label.className = 'ext-aggrid-setfilter__label';
	label.appendChild( cb );
	label.appendChild( document.createTextNode( ' ' + text ) );
	if ( count !== null ) {
		const c = document.createElement( 'span' );
		c.className = 'ext-aggrid-setfilter__count';
		c.textContent = ' (' + count + ')';
		label.appendChild( c );
	}
	li.appendChild( label );
	return li;
};

SetFilter.prototype.onToggle = function ( key, checked ) {
	if ( checked ) {
		this.selected.add( key );
	} else {
		this.selected.delete( key );
	}
	this.syncSelectAll();
	this.params.filterChangedCallback();
};

SetFilter.prototype.onSelectAll = function ( checked ) {
	this.items.forEach( ( item ) => {
		if ( item.li.hidden ) {
			return;
		}
		item.cb.checked = checked;
		if ( checked ) {
			this.selected.add( item.key );
		} else {
			this.selected.delete( item.key );
		}
	} );
	this.syncSelectAll();
	this.params.filterChangedCallback();
};

SetFilter.prototype.onSearch = function ( term ) {
	const q = term.trim().toLowerCase();
	this.items.forEach( ( item ) => {
		item.li.hidden = q !== '' && !item.label.toLowerCase().includes( q );
	} );
	this.syncSelectAll();
};

// Set the select-all checkbox to checked / unchecked / indeterminate from the visible rows.
SetFilter.prototype.syncSelectAll = function () {
	const visible = this.items.filter( ( item ) => !item.li.hidden );
	const checked = visible.filter( ( item ) => item.cb.checked );
	this.selectAllCb.indeterminate = checked.length > 0 && checked.length < visible.length;
	this.selectAllCb.checked = visible.length > 0 && checked.length === visible.length;
};

SetFilter.prototype.refreshSelectionUi = function () {
	this.items.forEach( ( item ) => {
		item.cb.checked = this.selected.has( item.key );
	} );
	this.syncSelectAll();
};

module.exports = { SetFilter: SetFilter, deriveValues: deriveValues };
