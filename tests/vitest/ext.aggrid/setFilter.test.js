const { SetFilter, deriveValues } = require( '../../../modules/ext.aggrid/setFilter.js' );

// Build a fake IFilterParams over an array of row data objects. `getValue` maps a row's
// data to the display value AG Grid's getCellValue({ useFormatter: true }) would return.
function makeParams( rows, getValue ) {
	const nodes = rows.map( ( data ) => ( { data: data } ) );
	const params = {
		column: { id: 'c' },
		filterChangedCallback: vi.fn(),
		api: {
			forEachLeafNode: ( cb ) => nodes.forEach( cb ),
			getCellValue: ( p ) => getValue( p.rowNode.data )
		}
	};
	return { params: params, nodes: nodes };
}

describe( 'deriveValues', () => {
	it( 'tallies unique display values in insertion order', () => {
		const { params } = makeParams(
			[ { s: 'a' }, { s: 'b' }, { s: 'a' } ], ( d ) => d.s
		);
		const counts = deriveValues( params.api, params.column );
		expect( Array.from( counts.entries() ) ).toEqual( [ [ 'a', 2 ], [ 'b', 1 ] ] );
	} );

	it( 'collapses null/undefined/empty into the blank key', () => {
		const { params } = makeParams(
			[ { s: '' }, { s: null }, { s: undefined }, { s: 'x' } ], ( d ) => d.s
		);
		const counts = deriveValues( params.api, params.column );
		expect( counts.get( '' ) ).toBe( 3 );
		expect( counts.get( 'x' ) ).toBe( 1 );
	} );

	it( 'keys on the formatted value from getCellValue', () => {
		// getValue uppercases — proving deriveValues uses the (formatter-applied) cell value.
		const { params } = makeParams( [ { s: 'a' }, { s: 'a' } ], ( d ) => d.s.toUpperCase() );
		const counts = deriveValues( params.api, params.column );
		expect( counts.get( 'A' ) ).toBe( 2 );
	} );
} );

describe( 'SetFilter logic', () => {
	function mount( rows, getValue ) {
		const ctx = makeParams( rows, getValue );
		const f = new SetFilter();
		f.init( ctx.params );
		return { f: f, params: ctx.params, nodes: ctx.nodes };
	}

	it( 'starts inactive with everything selected', () => {
		const { f } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		expect( f.isFilterActive() ).toBe( false );
		expect( f.getModel() ).toBeNull();
	} );

	it( 'doesFilterPass only for selected keys', () => {
		const { f, nodes } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		f.setModel( { values: [ 'a' ] } );
		expect( f.isFilterActive() ).toBe( true );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( true );
		expect( f.doesFilterPass( { node: nodes[ 1 ] } ) ).toBe( false );
	} );

	it( 'getModel reflects the selected set when active', () => {
		const { f } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		f.setModel( { values: [ 'b' ] } );
		expect( f.getModel() ).toEqual( { values: [ 'b' ] } );
	} );

	it( 'setModel(null) selects all and clears the filter', () => {
		const { f } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		f.setModel( { values: [ 'a' ] } );
		f.setModel( null );
		expect( f.isFilterActive() ).toBe( false );
		expect( f.getModel() ).toBeNull();
	} );
} );

describe( 'SetFilter GUI', () => {
	function mount( rows, getValue ) {
		const ctx = makeParams( rows, getValue );
		const f = new SetFilter();
		f.init( ctx.params );
		return { f: f, gui: f.getGui(), params: ctx.params };
	}

	it( 'renders a search box, select-all, and a row per value with counts', () => {
		const { gui } = mount( [ { s: 'a' }, { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		expect( gui.querySelector( '.ext-aggrid-setfilter__search' ) ).not.toBeNull();
		expect( gui.querySelector( '.ext-aggrid-setfilter__item--all' ) ).not.toBeNull();
		const values = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value' );
		expect( values.length ).toBe( 2 );
		expect( values[ 0 ].textContent ).toContain( '(2)' );
	} );

	it( 'shows the blanks message for the empty bucket', () => {
		const { gui } = mount( [ { s: '' }, { s: 'x' } ], ( d ) => d.s );
		// mw.msg returns the key in the test harness.
		expect( gui.textContent ).toContain( 'aggrid-setfilter-blanks' );
	} );

	it( 'search hides non-matching rows without changing selection', () => {
		const { f, gui } = mount( [ { s: 'apple' }, { s: 'banana' } ], ( d ) => d.s );
		const search = gui.querySelector( '.ext-aggrid-setfilter__search' );
		search.value = 'ban';
		search.dispatchEvent( new window.Event( 'input' ) );
		const rows = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value' );
		expect( rows[ 0 ].hidden ).toBe( true );
		expect( rows[ 1 ].hidden ).toBe( false );
		expect( f.isFilterActive() ).toBe( false ); // selection untouched
	} );

	it( 'select-all goes indeterminate when only some are checked', () => {
		const { f, gui } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		const cbs = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__cb' );
		cbs[ 0 ].checked = false;
		cbs[ 0 ].dispatchEvent( new window.Event( 'change' ) );
		const allCb = gui.querySelector( '.ext-aggrid-setfilter__item--all .ext-aggrid-setfilter__cb' );
		expect( allCb.indeterminate ).toBe( true );
		expect( f.getModel() ).toEqual( { values: [ 'b' ] } );
		expect( f.params.filterChangedCallback ).toHaveBeenCalled();
	} );

	it( 'unchecking select-all clears visible values and re-filters', () => {
		const { f, gui } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		const allCb = gui.querySelector( '.ext-aggrid-setfilter__item--all .ext-aggrid-setfilter__cb' );
		allCb.checked = false;
		allCb.dispatchEvent( new window.Event( 'change' ) );
		expect( f.getModel() ).toEqual( { values: [] } );
		expect( f.params.filterChangedCallback ).toHaveBeenCalled();
	} );
} );
