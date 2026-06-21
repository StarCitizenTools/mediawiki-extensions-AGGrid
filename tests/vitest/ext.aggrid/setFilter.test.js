const { SetFilter, deriveValues } = require( '../../../modules/ext.aggrid/setFilter.js' );

// Build a fake IFilterParams over an array of row data objects. `getValue` maps a row's
// data to the display value AG Grid's getCellValue({ useFormatter: true }) would return.
function makeParams( rows, getValue ) {
	const nodes = rows.map( ( data ) => ( { data } ) );
	const params = {
		column: { id: 'c' },
		colDef: {},
		filterChangedCallback: vi.fn(),
		api: {
			forEachLeafNode: ( cb ) => nodes.forEach( cb ),
			getCellValue: ( p ) => getValue( p.rowNode.data )
		}
	};
	return { params, nodes };
}

// Build a fake IFilterParams with a server valuesSource function.
function makeServerParams( valuesSource ) {
	const params = {
		column: { id: 'c' },
		colDef: {
			filterParams: {
				valuesSource
			}
		},
		filterChangedCallback: vi.fn(),
		api: {
			forEachLeafNode: () => {},
			getCellValue: () => ''
		}
	};
	return params;
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

	it( 'uses a supplied value getter instead of getCellValue', () => {
		const { params } = makeParams( [ { s: 'a' }, { s: 'a' } ], ( d ) => d.s );
		const counts = deriveValues(
			params.api, params.column, ( node ) => node.data.s.toUpperCase()
		);
		expect( counts.get( 'A' ) ).toBe( 2 );
	} );
} );

describe( 'SetFilter filterValueGetter', () => {
	// Rows carry a display scalar (name) and a separate filter facet (mfr). getCellValue
	// returns the name; the filterValueGetter returns the manufacturer.
	function makeGetterParams( rows ) {
		const nodes = rows.map( ( data ) => ( { data } ) );
		const params = {
			column: { id: 'c' },
			colDef: { filterValueGetter: ( p ) => p.data.mfr },
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => p.rowNode.data.name
			}
		};
		return { params, nodes };
	}

	it( 'derives values from the getter, not the formatted cell value', () => {
		const { params } = makeGetterParams( [
			{ name: 'Aurora', mfr: 'RSI' },
			{ name: '300i', mfr: 'Origin' },
			{ name: 'Constellation', mfr: 'RSI' }
		] );
		const f = new SetFilter();
		f.init( params );
		// Value list is manufacturers (RSI x2, Origin x1), not ship names.
		expect( Array.from( f.counts.entries() ) ).toEqual( [ [ 'RSI', 2 ], [ 'Origin', 1 ] ] );
	} );

	it( 'doesFilterPass checks the getter value', () => {
		const { params, nodes } = makeGetterParams( [
			{ name: 'Aurora', mfr: 'RSI' },
			{ name: '300i', mfr: 'Origin' }
		] );
		const f = new SetFilter();
		f.init( params );
		f.setModel( { values: [ 'RSI' ] } );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( true ); // RSI
		expect( f.doesFilterPass( { node: nodes[ 1 ] } ) ).toBe( false ); // Origin
	} );

	it( 'passes AG-Grid-shaped params to the getter', () => {
		const seen = [];
		const nodes = [ { data: { name: 'A', mfr: 'X' } } ];
		const params = {
			column: { id: 'c' },
			colDef: { filterValueGetter: ( p ) => {
				seen.push( p );
				return p.data.mfr;
			} },
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => p.rowNode.data.name
			}
		};
		const f = new SetFilter();
		f.init( params );
		expect( seen[ 0 ] ).toMatchObject( {
			colDef: params.colDef,
			column: params.column,
			node: nodes[ 0 ],
			data: { name: 'A', mfr: 'X' }
		} );
		expect( typeof seen[ 0 ].getValue ).toBe( 'function' );
		expect( seen[ 0 ].api ).toBe( params.api );
	} );

	it( 'collapses blank getter output into the blanks bucket', () => {
		const { params } = makeGetterParams( [
			{ name: 'A', mfr: '' },
			{ name: 'B', mfr: null },
			{ name: 'C', mfr: 'X' }
		] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.get( '' ) ).toBe( 2 );
		expect( f.counts.get( 'X' ) ).toBe( 1 );
	} );

	it( 'ignores a string filterValueGetter (falls back to formatted value)', () => {
		const nodes = [ { data: { name: 'Aurora', mfr: 'RSI' } } ];
		const params = {
			column: { id: 'c' },
			colDef: { filterValueGetter: 'data.mfr' },
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => p.rowNode.data.name
			}
		};
		const f = new SetFilter();
		f.init( params );
		// String form unsupported → value list comes from the formatted name.
		expect( f.counts.has( 'Aurora' ) ).toBe( true );
		expect( f.counts.has( 'RSI' ) ).toBe( false );
	} );
} );

describe( 'SetFilter logic', () => {
	function mount( rows, getValue ) {
		const ctx = makeParams( rows, getValue );
		const f = new SetFilter();
		f.init( ctx.params );
		return { f, params: ctx.params, nodes: ctx.nodes };
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
		return { f, gui: f.getGui(), params: ctx.params };
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

	// Helper: the value-label text of each rendered row, in DOM order.
	function valueLabels( gui ) {
		return Array.from(
			gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__text' )
		).map( ( el ) => el.textContent );
	}

	it( 'sorts value labels alphabetically regardless of row order', () => {
		const { gui } = mount(
			[ { s: 'banana' }, { s: 'apple' }, { s: 'cherry' } ], ( d ) => d.s
		);
		expect( valueLabels( gui ) ).toEqual( [ 'apple', 'banana', 'cherry' ] );
	} );

	it( 'sorts numerically (natural order), not lexically', () => {
		const { gui } = mount(
			[ { s: 'Item 10' }, { s: 'Item 2' }, { s: 'Item 1' } ], ( d ) => d.s
		);
		expect( valueLabels( gui ) ).toEqual( [ 'Item 1', 'Item 2', 'Item 10' ] );
	} );

	it( 'is case-insensitive when sorting', () => {
		const { gui } = mount(
			[ { s: 'Banana' }, { s: 'apple' }, { s: 'Cherry' } ], ( d ) => d.s
		);
		expect( valueLabels( gui ) ).toEqual( [ 'apple', 'Banana', 'Cherry' ] );
	} );

	it( 'pins the blanks bucket last', () => {
		const { gui } = mount(
			[ { s: 'b' }, { s: '' }, { s: 'a' } ], ( d ) => d.s
		);
		const labels = valueLabels( gui );
		expect( labels[ labels.length - 1 ] ).toBe( 'aggrid-setfilter-blanks' );
		expect( labels.slice( 0, -1 ) ).toEqual( [ 'a', 'b' ] );
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

	// The visual checkbox state lives in ag-checked / ag-indeterminate on the wrapper "box"
	// (AG Grid's theme draws it); the <input> only carries the logical state. Lock in that the
	// two never drift, since that sync is the load-bearing detail of the native-styling markup.
	it( 'keeps the AG Grid wrapper classes in sync with selection', () => {
		const { gui } = mount( [ { s: 'a' }, { s: 'b' } ], ( d ) => d.s );
		const valueBox = gui.querySelector( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__box' );
		const allBox = gui.querySelector( '.ext-aggrid-setfilter__item--all .ext-aggrid-setfilter__box' );
		// Initial: everything checked.
		expect( valueBox.classList.contains( 'ag-checked' ) ).toBe( true );
		expect( allBox.classList.contains( 'ag-checked' ) ).toBe( true );
		// Uncheck one value: its box clears, select-all becomes indeterminate (not checked).
		const cb = gui.querySelector( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__cb' );
		cb.checked = false;
		cb.dispatchEvent( new window.Event( 'change' ) );
		expect( valueBox.classList.contains( 'ag-checked' ) ).toBe( false );
		expect( allBox.classList.contains( 'ag-indeterminate' ) ).toBe( true );
		expect( allBox.classList.contains( 'ag-checked' ) ).toBe( false );
	} );
} );

describe( 'SetFilter server-backed', () => {
	// Helper: wait one microtask tick for Promises to settle.
	function tick() {
		return new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );
	}

	it( 'sets serverBacked=true when valuesSource is a function', () => {
		const params = makeServerParams( () => Promise.resolve( { values: [], partial: false } ) );
		const f = new SetFilter();
		f.init( params );
		expect( f.serverBacked ).toBe( true );
	} );

	it( 'sets serverBacked=false when no valuesSource is given', () => {
		const { params } = makeParams( [ { s: 'a' } ], ( d ) => d.s );
		const f = new SetFilter();
		f.init( params );
		expect( f.serverBacked ).toBe( false );
	} );

	it( 'getGui() returns an element immediately (before the async resolve)', () => {
		const params = makeServerParams( () => new Promise( () => {} ) );
		const f = new SetFilter();
		f.init( params );
		expect( f.getGui() ).toBeInstanceOf( window.HTMLElement );
	} );

	it( 'getGui() returns the same stable element after the async resolve', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' } ], partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		const guiBefore = f.getGui();
		await tick();
		expect( f.getGui() ).toBe( guiBefore );
	} );

	it( 'populates value rows from the server source (not forEachLeafNode)', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		const rows = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value' );
		expect( rows.length ).toBe( 2 );
		expect( rows[ 0 ].textContent ).toContain( 'A' );
		expect( rows[ 1 ].textContent ).toContain( 'B' );
	} );

	it( 'sorts server-backed value labels alphabetically', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			// Sort by label, not key: keys are out of label order on purpose.
			values: [
				{ key: '3', label: 'cherry' },
				{ key: '1', label: 'apple' },
				{ key: '2', label: 'banana' }
			],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		const labels = Array.from(
			gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__text' )
		).map( ( el ) => el.textContent );
		expect( labels ).toEqual( [ 'apple', 'banana', 'cherry' ] );
	} );

	it( 'falls back to the key as label and sorts by it when label is missing', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'banana' }, { key: 'apple', label: 'apple' }, { key: 'cherry' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		const labels = Array.from(
			gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__text' )
		).map( ( el ) => el.textContent );
		expect( labels ).toEqual( [ 'apple', 'banana', 'cherry' ] );
	} );

	it( 'server-backed rows show no (count) suffix', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		const rows = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value' );
		rows.forEach( ( row ) => {
			expect( row.querySelector( '.ext-aggrid-setfilter__count' ) ).toBeNull();
		} );
	} );

	it( 'doesFilterPass always returns true when server-backed', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' } ], partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		// Even for a row whose key would not be in selected, server-backed returns true.
		expect( f.doesFilterPass( { node: { data: { s: 'Z' } } } ) ).toBe( true );
	} );

	it( 'getModel() returns { values } when server-backed filter is active', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		// Uncheck one value to make filter active.
		const gui = f.getGui();
		const cbs = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__cb' );
		cbs[ 0 ].checked = false;
		cbs[ 0 ].dispatchEvent( new window.Event( 'change' ) );

		expect( f.isFilterActive() ).toBe( true );
		expect( f.getModel() ).toEqual( { values: [ 'B' ] } );
	} );

	it( 'getModel() returns an exclude model when fewer values are unchecked', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' }, { key: 'C', label: 'C' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		// Uncheck only C (1 unchecked < 2 selected) → exclude model keeps the SMW query small.
		const gui = f.getGui();
		const cbs = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__cb' );
		cbs[ 2 ].checked = false;
		cbs[ 2 ].dispatchEvent( new window.Event( 'change' ) );

		expect( f.getModel() ).toEqual( { values: [ 'C' ], exclude: true } );
	} );

	it( 'setModel restores selection from an exclude model', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' }, { key: 'C', label: 'C' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		f.setModel( { values: [ 'C' ], exclude: true } );
		// A and B selected, C excluded → re-emitting yields the same exclude model.
		expect( f.getModel() ).toEqual( { values: [ 'C' ], exclude: true } );
		expect( f.doesFilterPass ).toBeTypeOf( 'function' );
	} );

	it( 'selection toggles call filterChangedCallback when server-backed', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' } ],
			partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		const cb = gui.querySelector( '.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__cb' );
		cb.checked = false;
		cb.dispatchEvent( new window.Event( 'change' ) );

		expect( params.filterChangedCallback ).toHaveBeenCalled();
	} );

	it( 'renders the partial note when partial is true', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' } ], partial: true
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		const note = gui.querySelector( '.ext-aggrid-setfilter__partial' );
		expect( note ).not.toBeNull();
		// mw.msg returns the key in test harness.
		expect( note.textContent ).toBe( 'aggrid-setfilter-partial' );
	} );

	it( 'does not render the partial note when partial is false', async () => {
		const params = makeServerParams( () => Promise.resolve( {
			values: [ { key: 'A', label: 'A' } ], partial: false
		} ) );
		const f = new SetFilter();
		f.init( params );
		await tick();

		const gui = f.getGui();
		expect( gui.querySelector( '.ext-aggrid-setfilter__partial' ) ).toBeNull();
	} );

	it( 'handles a rejected valuesSource without throwing', async () => {
		const params = makeServerParams( () => Promise.reject( new Error( 'network' ) ) );
		const f = new SetFilter();
		f.init( params );

		// Should not throw on rejection.
		await expect( tick() ).resolves.toBeUndefined();

		// GUI is still a valid element.
		expect( f.getGui() ).toBeInstanceOf( window.HTMLElement );
		// No value rows rendered.
		const rows = f.getGui().querySelectorAll( '.ext-aggrid-setfilter__item--value' );
		expect( rows.length ).toBe( 0 );
	} );
} );

describe( 'SetFilter itemRenderer', () => {
	function makeRendererParams( rows, itemRenderer ) {
		const nodes = rows.map( ( data ) => ( { data } ) );
		const params = {
			column: { id: 'c' },
			colDef: { filterParams: { itemRenderer } },
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => p.rowNode.data.s
			}
		};
		return { params, nodes };
	}

	function tick() {
		return new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );
	}

	it( 'renders value cells with the custom node', () => {
		const { params } = makeRendererParams( [ { s: 'apple' }, { s: 'banana' } ], ( item ) => {
			const span = document.createElement( 'span' );
			span.className = 'glyph';
			span.textContent = `★${ item.label }`;
			return span;
		} );
		const f = new SetFilter();
		f.init( params );
		const gui = f.getGui();
		const glyphs = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value .glyph' );
		expect( glyphs.length ).toBe( 2 );
		expect( glyphs[ 0 ].textContent ).toBe( '★apple' );
	} );

	it( 'passes { label, key, count } to the renderer', () => {
		const seen = [];
		const { params } = makeRendererParams( [ { s: 'apple' }, { s: 'apple' } ], ( item ) => {
			seen.push( item );
			return document.createElement( 'span' );
		} );
		const f = new SetFilter();
		f.init( params );
		expect( seen[ 0 ] ).toEqual( { label: 'apple', key: 'apple', count: 2 } );
	} );

	it( 'does not render the select-all row with the renderer', () => {
		const { params } = makeRendererParams( [ { s: 'apple' } ], () => {
			const span = document.createElement( 'span' );
			span.className = 'glyph';
			return span;
		} );
		const f = new SetFilter();
		f.init( params );
		const gui = f.getGui();
		expect( gui.querySelector( '.ext-aggrid-setfilter__item--all .glyph' ) ).toBeNull();
	} );

	it( 'falls back to a text node when the renderer returns falsy', () => {
		const { params } = makeRendererParams( [ { s: 'apple' } ], () => null );
		const f = new SetFilter();
		f.init( params );
		const gui = f.getGui();
		const text = gui.querySelector(
			'.ext-aggrid-setfilter__item--value .ext-aggrid-setfilter__text'
		);
		expect( text.textContent ).toBe( 'apple' );
	} );

	it( 'search still matches on the label text when a renderer is used', () => {
		const { params } = makeRendererParams( [ { s: 'apple' }, { s: 'banana' } ], ( item ) => {
			const span = document.createElement( 'span' );
			span.textContent = item.label;
			return span;
		} );
		const f = new SetFilter();
		f.init( params );
		const gui = f.getGui();
		const search = gui.querySelector( '.ext-aggrid-setfilter__search' );
		search.value = 'ban';
		search.dispatchEvent( new window.Event( 'input' ) );
		const rows = gui.querySelectorAll( '.ext-aggrid-setfilter__item--value' );
		expect( rows[ 0 ].hidden ).toBe( true ); // apple
		expect( rows[ 1 ].hidden ).toBe( false ); // banana
		expect( f.isFilterActive() ).toBe( false );
	} );

	it( 'renders server-backed value rows with the custom node', async () => {
		const itemRenderer = ( item ) => {
			const span = document.createElement( 'span' );
			span.className = 'glyph';
			span.textContent = item.label;
			return span;
		};
		const params = {
			column: { id: 'c' },
			colDef: { filterParams: {
				itemRenderer,
				valuesSource: () => Promise.resolve( {
					values: [ { key: 'A', label: 'A' }, { key: 'B', label: 'B' } ], partial: false
				} )
			} },
			filterChangedCallback: vi.fn(),
			api: { forEachLeafNode: () => {}, getCellValue: () => '' }
		};
		const f = new SetFilter();
		f.init( params );
		await tick();
		const glyphs = f.getGui().querySelectorAll( '.ext-aggrid-setfilter__item--value .glyph' );
		expect( glyphs.length ).toBe( 2 );
	} );
} );

describe( 'SetFilter multi-value cells', () => {
	// Build params over raw cell values (objects/arrays). getCellValue returns the raw
	// value regardless of useFormatter — listValues splits {links}/arrays before any
	// formatted fallback is reached.
	function makeMultiParams( cells ) {
		const nodes = cells.map( ( v ) => ( { data: { v } } ) );
		const params = {
			column: { id: 'c' },
			colDef: {},
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => p.rowNode.data.v
			}
		};
		return { params, nodes };
	}

	function tags( arr ) {
		return { links: arr.map( ( t ) => ( { text: t } ) ) };
	}

	it( 'splits a { links } cell into one key per value with per-row counts', () => {
		const { params } = makeMultiParams( [
			tags( [ 'Manufacturing', 'Mining' ] ),
			tags( [ 'Manufacturing', 'Retail' ] )
		] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.get( 'Manufacturing' ) ).toBe( 2 );
		expect( f.counts.get( 'Mining' ) ).toBe( 1 );
		expect( f.counts.get( 'Retail' ) ).toBe( 1 );
	} );

	it( 'counts a repeated value within one row only once', () => {
		const { params } = makeMultiParams( [ tags( [ 'A', 'A', 'B' ] ) ] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.get( 'A' ) ).toBe( 1 );
		expect( f.counts.get( 'B' ) ).toBe( 1 );
	} );

	it( 'doesFilterPass is ANY-of across the cell values', () => {
		const { params, nodes } = makeMultiParams( [
			tags( [ 'Manufacturing', 'Mining' ] ),
			tags( [ 'Retail' ] )
		] );
		const f = new SetFilter();
		f.init( params );
		f.setModel( { values: [ 'Manufacturing' ] } );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( true );
		expect( f.doesFilterPass( { node: nodes[ 1 ] } ) ).toBe( false );
	} );

	it( 'hides a multi-value row only when all its values are deselected', () => {
		const { params, nodes } = makeMultiParams( [ tags( [ 'A', 'B' ] ) ] );
		const f = new SetFilter();
		f.init( params );
		f.setModel( { values: [ 'B' ] } );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( true );
		f.setModel( { values: [] } );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( false );
	} );

	it( 'sends an empty { links } cell to the blanks bucket', () => {
		const { params, nodes } = makeMultiParams( [ tags( [ 'A' ] ), { links: [] } ] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.get( '' ) ).toBe( 1 );
		f.setModel( { values: [ 'A' ] } );
		expect( f.doesFilterPass( { node: nodes[ 1 ] } ) ).toBe( false );
	} );

	it( 'enumerates an array returned by filterValueGetter', () => {
		const nodes = [
			{ data: { tags: [ 'A', 'B' ] } },
			{ data: { tags: [ 'A' ] } }
		];
		const params = {
			column: { id: 'c' },
			colDef: { filterValueGetter: ( p ) => p.data.tags },
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => p.rowNode.data
			}
		};
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.get( 'A' ) ).toBe( 2 );
		expect( f.counts.get( 'B' ) ).toBe( 1 );
		f.setModel( { values: [ 'B' ] } );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( true );
		expect( f.doesFilterPass( { node: nodes[ 1 ] } ) ).toBe( false );
	} );
} );

describe( 'SetFilter raw vs formatted cell value', () => {
	// A faithful getCellValue honouring useFormatter: false -> the RAW value (structured
	// object or scalar), true -> the FORMATTED display string. This pins the production
	// contract the feature rests on: list-shape is detected on the raw value, while a
	// non-list cell keys on the formatter output.
	function makeFormatterParams( rows ) {
		// rows: [ { raw, formatted } ]
		const nodes = rows.map( ( r ) => ( { data: r } ) );
		const params = {
			column: { id: 'c' },
			colDef: {},
			filterChangedCallback: vi.fn(),
			api: {
				forEachLeafNode: ( cb ) => nodes.forEach( cb ),
				getCellValue: ( p ) => ( p.useFormatter ?
					p.rowNode.data.formatted : p.rowNode.data.raw )
			}
		};
		return { params, nodes };
	}

	it( 'splits a structured { links } raw value, not the joined formatted string', () => {
		const { params } = makeFormatterParams( [
			{ raw: { links: [ { text: 'A' }, { text: 'B' } ] }, formatted: 'A, B' }
		] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.has( 'A' ) ).toBe( true );
		expect( f.counts.has( 'B' ) ).toBe( true );
		expect( f.counts.has( 'A, B' ) ).toBe( false );
	} );

	it( 'keys a single rich (non-list) raw value on its formatted text', () => {
		// A single { text, href } link: raw is an object (listValues -> null), so the
		// formatted text is the one value — never the raw object.
		const { params, nodes } = makeFormatterParams( [
			{ raw: { text: 'Aurora', href: '/wiki/Aurora' }, formatted: 'Aurora' }
		] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.has( 'Aurora' ) ).toBe( true );
		f.setModel( { values: [ 'Aurora' ] } );
		expect( f.doesFilterPass( { node: nodes[ 0 ] } ) ).toBe( true );
	} );

	it( 'keys a formatted scalar on the formatter output, not the raw value', () => {
		// A number column with a formatter: raw 1234 (listValues -> null) -> use formatted
		// "1,234". Guards against "just reuse the raw scalar" — it would key on the wrong text.
		const { params } = makeFormatterParams( [ { raw: 1234, formatted: '1,234' } ] );
		const f = new SetFilter();
		f.init( params );
		expect( f.counts.has( '1,234' ) ).toBe( true );
		expect( f.counts.has( '1234' ) ).toBe( false );
	} );
} );
