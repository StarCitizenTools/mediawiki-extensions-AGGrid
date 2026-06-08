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

// Compare two display labels for the value list: natural, case-insensitive,
// number-aware alphabetical order (so "Item 2" sorts before "Item 10").
function compareLabels( a, b ) {
	return a.localeCompare( b, undefined, { numeric: true, sensitivity: 'base' } );
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

// --- GUI helpers -------------------------------------------------------------------------
//
// The popup mirrors AG Grid's Enterprise set-filter DOM (ag-set-filter / ag-mini-filter /
// ag-set-filter-list / ag-set-filter-item / ag-set-filter-item-value, with an ag-checkbox per
// item) so the active grid theme — mapped from Codex tokens by theme.js — styles it natively,
// matching the grid's built-in filters and any custom gridOptions.theme. The
// ext-aggrid-setfilter-* classes are our own hooks (tests, the bounded scroll area, value
// counts). The checkbox visual is driven by ag-checked / ag-indeterminate on the wrapper; the
// real <input> underneath carries the state for logic. If AG Grid ever renames these classes
// the filter still functions, it just falls back to unstyled controls.

// Assign a class string. Centralised so the AG Grid theme classes we reuse (ag-*) and our
// own ext-aggrid-setfilter-* hooks are set in one place; assigning via a variable also keeps
// the class-doc lint (which only inspects string literals) satisfied without per-call noise.
function setClass( el, classes ) {
	el.className = classes;
	return el;
}

// Reflect a checkbox state into the input (logic/tests) and the AG Grid wrapper (visual).
function setCheckState( box, input, checked, indeterminate ) {
	input.checked = checked;
	input.indeterminate = indeterminate;
	box.classList.toggle( 'ag-checked', checked && !indeterminate );
	box.classList.toggle( 'ag-indeterminate', indeterminate );
}

// Build a set-filter item's checkbox + value label, mirroring AG Grid's themed ag-checkbox
// markup. Starts checked (the filter opens with every value selected).
function makeItemCheckbox( text ) {
	const field = setClass( document.createElement( 'div' ),
		'ag-labeled ag-label-align-right ag-checkbox ag-input-field ag-set-filter-item-checkbox' );
	const wrapper = setClass( document.createElement( 'span' ),
		'ag-wrapper ag-input-wrapper ag-checkbox-input-wrapper ag-checked ext-aggrid-setfilter__box' );
	const input = setClass( document.createElement( 'input' ),
		'ag-input-field-input ag-checkbox-input ext-aggrid-setfilter__cb' );
	input.type = 'checkbox';
	// A name keeps the browser from flagging an unlabelled form control.
	input.name = 'aggrid-setfilter-value';
	input.checked = true;
	wrapper.appendChild( input );
	const value = setClass( document.createElement( 'span' ),
		'ag-label ag-set-filter-item-value ext-aggrid-setfilter__text' );
	value.textContent = text;
	field.appendChild( wrapper );
	field.appendChild( value );
	return { field, wrapper, input };
}

/**
 * AG Grid IFilterComp. One instance per filtered column.
 */
class SetFilter {
	init( params ) {
		this.params = params;
		const valuesSource = params.colDef &&
			params.colDef.filterParams &&
			params.colDef.filterParams.valuesSource;
		if ( typeof valuesSource === 'function' ) {
			this.serverBacked = true;
			this.initServerBacked( valuesSource );
		} else {
			this.serverBacked = false;
			this.initClientSide();
		}
	}

	initClientSide() {
		this.counts = deriveValues( this.params.api, this.params.column );
		this.allKeys = Array.from( this.counts.keys() );
		// Inactive to start: every value selected.
		this.selected = new Set( this.allKeys );
		this.items = [];
		this.gui = this.buildGui();
	}

	initServerBacked( valuesSource ) {
		// Initialise to an empty state so buildGui works synchronously.
		this.counts = new Map();
		this.allKeys = [];
		this.selected = new Set();
		this.items = [];
		// Build the GUI shell immediately (AG Grid calls getGui() right after init).
		this.gui = this.buildGui();
		// Add a loading indicator to the list until the promise resolves.
		const list = this.gui.querySelector( '.ag-set-filter-list' );
		const loading = document.createElement( 'div' );
		loading.className = 'ext-aggrid-setfilter__loading';
		loading.textContent = mw.msg( 'aggrid-setfilter-loading' );
		list.appendChild( loading );

		valuesSource().then( ( result ) => {
			const values = ( result && result.values ) || [];
			const partial = !!( result && result.partial );

			// Remove loading indicator.
			if ( loading.parentNode ) {
				loading.parentNode.removeChild( loading );
			}

			// Normalise to { key, label } and sort alphabetically by label. When the
			// server caps the set (partial), this alphabetises an arbitrary truncated
			// slice — the values are still incomplete; the partial note below says so.
			const sorted = values.map( ( v ) => {
				const key = String( v.key );
				return {
					key,
					label: v.label !== undefined && v.label !== null ? String( v.label ) : key
				};
			} );
			sorted.sort( ( a, b ) => compareLabels( a.label, b.label ) );

			// Build the counts map: key → null (counts unknown from server).
			this.counts = new Map();
			sorted.forEach( ( v ) => {
				this.counts.set( v.key, null );
			} );
			this.allKeys = sorted.map( ( v ) => v.key );
			this.selected = new Set( this.allKeys );

			// Populate the list with server-provided values.
			this.items = [];
			sorted.forEach( ( v ) => {
				const cb = makeItemCheckbox( v.label );
				cb.input.addEventListener( 'change', () => this.onToggle( v.key, cb.input.checked ) );
				// Pass null for count so no count suffix is rendered.
				const row = this.buildRow( 'ext-aggrid-setfilter__item--value', cb, null );
				this.items.push(
					{ key: v.key, label: v.label, row, box: cb.wrapper, input: cb.input }
				);
				list.appendChild( row );
			} );

			this.syncSelectAll();

			if ( partial ) {
				const note = document.createElement( 'div' );
				note.className = 'ext-aggrid-setfilter__partial';
				note.textContent = mw.msg( 'aggrid-setfilter-partial' );
				this.gui.appendChild( note );
			}
		} ).catch( ( e ) => {
			mw.log.error( '[ext.aggrid] SetFilter: failed to fetch values', e );
			if ( loading.parentNode ) {
				loading.parentNode.removeChild( loading );
			}
			const errEl = document.createElement( 'div' );
			errEl.className = 'ext-aggrid-setfilter__error';
			errEl.textContent = mw.msg( 'aggrid-setfilter-values-error' );
			const list2 = this.gui.querySelector( '.ag-set-filter-list' );
			if ( list2 ) {
				list2.appendChild( errEl );
			}
		} );
	}

	getGui() {
		return this.gui;
	}

	// Active iff at least one value is unchecked. (Ignores stale keys a setModel might carry.)
	isFilterActive() {
		return this.allKeys.some( ( key ) => !this.selected.has( key ) );
	}

	doesFilterPass( params ) {
		if ( this.serverBacked ) {
			return true;
		}
		const key = keyOf( displayValue( this.params.api, this.params.column, params.node ) );
		return this.selected.has( key );
	}

	getModel() {
		if ( !this.isFilterActive() ) {
			return null;
		}
		const selected = Array.from( this.selected );
		if ( this.serverBacked ) {
			// The backend pushes this into an SMW query, which caps the number of
			// conditions (one per value). Send whichever side is smaller: when the
			// user only unchecks a few values, an "exclude" model is far shorter
			// (and stays within the query-size limit) than listing every selected one.
			const unselected = this.allKeys.filter( ( key ) => !this.selected.has( key ) );
			if ( unselected.length < selected.length ) {
				return { values: unselected, exclude: true };
			}
		}
		return { values: selected };
	}

	setModel( model ) {
		if ( !model || !Array.isArray( model.values ) ) {
			this.selected = new Set( this.allKeys );
		} else if ( model.exclude ) {
			const excluded = new Set( model.values );
			this.selected = new Set( this.allKeys.filter( ( key ) => !excluded.has( key ) ) );
		} else {
			this.selected = new Set( model.values );
		}
		this.refreshSelectionUi();
	}

	buildGui() {
		const root = setClass( document.createElement( 'div' ), 'ag-set-filter ext-aggrid-setfilter' );

		// Search — AG Grid's mini-filter (themed spacing + input chrome).
		const mini = setClass( document.createElement( 'div' ),
			'ag-mini-filter ag-text-field ag-input-field ext-aggrid-setfilter__search-field' );
		const inputWrapper = setClass( document.createElement( 'div' ),
			'ag-wrapper ag-input-wrapper ag-text-field-input-wrapper' );
		const search = setClass( document.createElement( 'input' ),
			'ag-input-field-input ag-text-field-input ext-aggrid-setfilter__search' );
		search.type = 'text';
		search.name = 'aggrid-setfilter-search';
		search.setAttribute( 'placeholder', mw.msg( 'aggrid-setfilter-search-placeholder' ) );
		search.addEventListener( 'input', () => this.onSearch( search.value ) );
		inputWrapper.appendChild( search );
		mini.appendChild( inputWrapper );
		root.appendChild( mini );

		const list = setClass( document.createElement( 'div' ),
			'ag-set-filter-list ext-aggrid-setfilter__list' );

		// Select-all row (tri-state).
		this.selectAll = makeItemCheckbox( mw.msg( 'aggrid-setfilter-select-all' ) );
		this.selectAll.input.addEventListener( 'change',
			() => this.onSelectAll( this.selectAll.input.checked ) );
		list.appendChild( this.buildRow( 'ext-aggrid-setfilter__item--all', this.selectAll, null ) );

		// One row per value, sorted alphabetically by label; the blanks bucket is
		// pinned last (its position should not depend on the blanks message wording).
		const values = [];
		this.counts.forEach( ( count, key ) => {
			values.push( {
				key,
				count,
				label: key === BLANK_KEY ? mw.msg( 'aggrid-setfilter-blanks' ) : key
			} );
		} );
		values.sort( ( a, b ) => {
			if ( a.key === BLANK_KEY ) {
				return b.key === BLANK_KEY ? 0 : 1;
			}
			if ( b.key === BLANK_KEY ) {
				return -1;
			}
			return compareLabels( a.label, b.label );
		} );
		values.forEach( ( v ) => {
			const cb = makeItemCheckbox( v.label );
			cb.input.addEventListener( 'change', () => this.onToggle( v.key, cb.input.checked ) );
			const row = this.buildRow( 'ext-aggrid-setfilter__item--value', cb, v.count );
			this.items.push(
				{ key: v.key, label: v.label, row, box: cb.wrapper, input: cb.input }
			);
			list.appendChild( row );
		} );

		root.appendChild( list );
		return root;
	}

	// Build one set-filter item as a <label> (so the whole row toggles its checkbox) wrapping
	// the AG Grid checkbox + value. count === null omits the trailing count (the select-all row).
	buildRow( modifier, cb, count ) {
		const row = setClass( document.createElement( 'label' ),
			`ag-set-filter-item ext-aggrid-setfilter__item ${ modifier }` );
		row.appendChild( cb.field );
		if ( count !== null ) {
			const countEl = setClass(
				document.createElement( 'span' ), 'ext-aggrid-setfilter__count'
			);
			countEl.textContent = `(${ count })`;
			row.appendChild( countEl );
		}
		return row;
	}

	onToggle( key, checked ) {
		if ( checked ) {
			this.selected.add( key );
		} else {
			this.selected.delete( key );
		}
		const item = this.items.find( ( i ) => i.key === key );
		if ( item ) {
			setCheckState( item.box, item.input, checked, false );
		}
		this.syncSelectAll();
		this.params.filterChangedCallback();
	}

	onSelectAll( checked ) {
		this.items.forEach( ( item ) => {
			if ( item.row.hidden ) {
				return;
			}
			if ( checked ) {
				this.selected.add( item.key );
			} else {
				this.selected.delete( item.key );
			}
			setCheckState( item.box, item.input, checked, false );
		} );
		this.syncSelectAll();
		this.params.filterChangedCallback();
	}

	onSearch( term ) {
		const q = term.trim().toLowerCase();
		this.items.forEach( ( item ) => {
			item.row.hidden = q !== '' && !item.label.toLowerCase().includes( q );
		} );
		this.syncSelectAll();
	}

	// Set the select-all checkbox to checked / unchecked / indeterminate from the visible rows.
	syncSelectAll() {
		const visible = this.items.filter( ( item ) => !item.row.hidden );
		const checked = visible.filter( ( item ) => item.input.checked );
		const allChecked = visible.length > 0 && checked.length === visible.length;
		const someChecked = checked.length > 0 && checked.length < visible.length;
		setCheckState( this.selectAll.wrapper, this.selectAll.input, allChecked, someChecked );
	}

	refreshSelectionUi() {
		this.items.forEach( ( item ) => {
			setCheckState( item.box, item.input, this.selected.has( item.key ), false );
		} );
		this.syncSelectAll();
	}
}

module.exports = { SetFilter, deriveValues };
