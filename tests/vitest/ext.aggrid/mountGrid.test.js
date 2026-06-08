const { parseConfig, mountGrid, applyFormatters } = require( '../../../modules/ext.aggrid/mountGrid.js' );

function makeEl( json ) {
	const el = document.createElement( 'div' );
	el.className = 'ext-aggrid';
	if ( json !== null ) {
		el.setAttribute( 'data-mw-aggrid-options', json );
	}
	return el;
}

describe( 'parseConfig', () => {

	it( 'parses a valid config attribute', () => {
		const el = makeEl( '{"columnDefs":[{"field":"name"}],"rowData":[]}' );
		expect( parseConfig( el ) ).toEqual( {
			columnDefs: [ { field: 'name' } ],
			rowData: []
		} );
	} );

	it( 'returns null when the config attribute is absent', () => {
		expect( parseConfig( makeEl( null ) ) ).toBeNull();
	} );

	it( 'returns null for invalid JSON', () => {
		expect( parseConfig( makeEl( '{not json' ) ) ).toBeNull();
	} );
} );

describe( 'mountGrid', () => {
	beforeEach( () => {
		global.agGrid = {
			createGrid: vi.fn(),
			themeQuartz: { withParams: () => ( { __wiki: true } ) }
		};
	} );

	afterEach( () => {
		delete global.agGrid;
	} );

	it( 'creates the grid once and marks the element initialised', () => {
		const el = makeEl( '{"columnDefs":[{"field":"name"}],"rowData":[]}' );
		mountGrid( el );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
		expect( el.classList.contains( 'ext-aggrid--init' ) ).toBe( true );
	} );

	it( 'does not mount the same element twice', () => {
		const el = makeEl( '{"columnDefs":[{"field":"name"}],"rowData":[]}' );
		mountGrid( el );
		mountGrid( el );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not mount or mark an element with no config', () => {
		const el = makeEl( null );
		mountGrid( el );
		expect( global.agGrid.createGrid ).not.toHaveBeenCalled();
		expect( el.classList.contains( 'ext-aggrid--init' ) ).toBe( false );
	} );

	it( 'applies the wiki theme when the config has none', () => {
		const el = makeEl( '{"columnDefs":[],"rowData":[]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.theme ).toEqual( { __wiki: true } );
	} );

	it( 'preserves a theme already set in the config', () => {
		const el = makeEl( '{"columnDefs":[],"rowData":[],"theme":"legacy"}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.theme ).toBe( 'legacy' );
	} );

	it( 'removes the skeleton and clears aria-busy on mount', () => {
		const el = makeEl( '{"columnDefs":[],"rowData":[]}' );
		el.setAttribute( 'aria-busy', 'true' );
		const skeleton = document.createElement( 'div' );
		skeleton.className = 'ext-aggrid__skeleton';
		el.appendChild( skeleton );

		mountGrid( el );

		expect( el.querySelector( '.ext-aggrid__skeleton' ) ).toBeNull();
		expect( el.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
	} );

	function makeHandleEl( opts ) {
		const el = document.createElement( 'div' );
		el.className = 'ext-aggrid';
		el.setAttribute( 'data-mw-aggrid-options', opts );
		el.setAttribute( 'data-mw-aggrid-pageid', '7' );
		el.setAttribute( 'data-mw-aggrid-rev', '42' );
		el.setAttribute( 'data-mw-aggrid-index', '0' );
		return el;
	}

	it( 'fetches rows over REST when the placeholder carries a handle', async () => {
		const get = vi.fn().mockResolvedValue( { rows: [ { name: 'Aurora' } ] } );
		const RestMock = vi.fn();
		RestMock.prototype.get = get;
		global.mw.Rest = RestMock;

		const el = makeHandleEl( '{"columnDefs":[{"field":"name"}]}' );
		mountGrid( el );

		// Guard applied before the async fetch resolves.
		expect( el.classList.contains( 'ext-aggrid--init' ) ).toBe( true );

		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		expect( get ).toHaveBeenCalledWith( '/aggrid/v0/grid/7/42/0/rows' );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.rowData ).toEqual( [ { name: 'Aurora' } ] );

		delete global.mw.Rest;
	} );

	it( 'mounts an error overlay when there is neither rowData nor a handle', () => {
		const el = makeEl( '{"columnDefs":[{"field":"name"}]}' );
		const skeleton = document.createElement( 'div' );
		skeleton.className = 'ext-aggrid__skeleton';
		el.appendChild( skeleton );
		el.setAttribute( 'aria-busy', 'true' );

		mountGrid( el );

		expect( el.querySelector( '.ext-aggrid__skeleton' ) ).toBeNull();
		expect( el.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.rowData ).toEqual( [] );
		expect( opts.overlayNoRowsTemplate ).toContain( 'aggrid-error-load' );
	} );

	it( 'wires the built-in rich-cell column types into gridOptions', () => {
		const el = makeEl( '{"columnDefs":[{"field":"name","type":"aggridLink"}],"rowData":[]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( typeof opts.columnTypes.aggridLink.cellRenderer ).toBe( 'function' );
		expect( typeof opts.columnTypes.aggridImage.cellRenderer ).toBe( 'function' );
		expect( typeof opts.columnTypes.aggridLinkList.cellRenderer ).toBe( 'function' );
	} );

	it( 'lets built-in column types win over author-supplied ones of the same name', () => {
		const el = makeEl(
			'{"columnDefs":[],"rowData":[],"columnTypes":{"aggridLink":{"width":99}}}'
		);
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( typeof opts.columnTypes.aggridLink.cellRenderer ).toBe( 'function' );
	} );

	it( 'registers the set filter component under the aggridSet name', () => {
		const el = makeEl( '{"columnDefs":[{"field":"t","filter":"aggridSet"}],"rowData":[]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( typeof opts.components.aggridSet ).toBe( 'function' );
	} );

	it( 'lets the built-in set filter win over an author-supplied one', () => {
		const el = makeEl(
			'{"columnDefs":[],"rowData":[],"components":{"aggridSet":{"x":1},"keep":{"y":2}}}'
		);
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( typeof opts.components.aggridSet ).toBe( 'function' );
		expect( opts.components.keep ).toEqual( { y: 2 } );
	} );

	function makeBackendEl( opts ) {
		const el = makeHandleEl( opts );
		el.setAttribute( 'data-mw-aggrid-source', 'smw' );
		return el;
	}

	// Build an IGetRowsParams-shaped object with spy success/fail callbacks. The
	// vendored v32 bundle invokes successCallback(rows, lastRow) / failCallback().
	function makeRowsParams( over ) {
		return Object.assign( {
			startRow: 0,
			endRow: 50,
			sortModel: [],
			filterModel: {},
			successCallback: vi.fn(),
			failCallback: vi.fn()
		}, over );
	}

	function mockRest( get ) {
		const RestMock = vi.fn();
		RestMock.prototype.get = get;
		global.mw.Rest = RestMock;
	}

	it( 'configures the Infinite Row Model with native pagination and a datasource.getRows', () => {
		global.agGrid.createGrid = vi.fn();

		const el = makeBackendEl( '{"columnDefs":[{"field":"name"}]}' );
		mountGrid( el );

		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.rowModelType ).toBe( 'infinite' );
		expect( opts.pagination ).toBe( true );
		expect( opts.paginationPageSize ).toBe( 50 );
		expect( opts.cacheBlockSize ).toBe( 50 );
		expect( typeof opts.datasource.getRows ).toBe( 'function' );
		// No rowData on the infinite path.
		expect( opts.rowData ).toBeUndefined();
	} );

	it( 'fetches block 0 by offset/limit, then calls successCallback with the total', async () => {
		global.agGrid.createGrid = vi.fn();

		const get = vi.fn().mockResolvedValue( {
			rows: [ { name: 'Aurora' } ], total: 137
		} );
		mockRest( get );

		const el = makeBackendEl( '{"columnDefs":[{"field":"name"}]}' );
		mountGrid( el );

		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		// Native pagination bar; the page-size selector is disabled so the user
		// can't desync paginationPageSize from cacheBlockSize (offset block mapping).
		expect( opts.rowModelType ).toBe( 'infinite' );
		expect( opts.pagination ).toBe( true );
		expect( opts.paginationPageSizeSelector ).toBe( false );
		expect( opts.paginationPageSize ).toBe( opts.cacheBlockSize );
		const params = makeRowsParams( { startRow: 0, endRow: 50 } );
		opts.datasource.getRows( params );

		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		const url = get.mock.calls[ 0 ][ 0 ];
		expect( url ).toContain( '/aggrid/v0/grid/7/42/0/page?' );
		expect( url ).toContain( 'offset=0' );
		expect( url ).toContain( 'size=50' );
		expect( url ).not.toContain( 'cursor=' );
		// total is passed as the absolute last row so the page bar shows all pages.
		expect( params.successCallback ).toHaveBeenCalledWith( [ { name: 'Aurora' } ], 137 );
		expect( params.failCallback ).not.toHaveBeenCalled();

		delete global.mw.Rest;
	} );

	it( 'lets the author opt into infinite scroll with pagination=false', () => {
		global.agGrid.createGrid = vi.fn();
		mockRest( vi.fn().mockResolvedValue( { rows: [], total: 0 } ) );

		const el = makeBackendEl( '{"columnDefs":[{"field":"n"}],"pagination":false}' );
		mountGrid( el );

		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.pagination ).toBe( false );
		expect( opts.rowModelType ).toBe( 'infinite' );

		delete global.mw.Rest;
	} );

	it( 'fails the block when the response has no numeric total', async () => {
		global.agGrid.createGrid = vi.fn();
		mockRest( vi.fn().mockResolvedValue( { rows: [ { n: 1 } ] } ) );

		const el = makeBackendEl( '{"columnDefs":[{"field":"n"}]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		const params = makeRowsParams( { startRow: 0, endRow: 50 } );
		opts.datasource.getRows( params );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		expect( params.successCallback ).not.toHaveBeenCalled();
		expect( params.failCallback ).toHaveBeenCalled();

		delete global.mw.Rest;
	} );

	it( 'requests the next block by its startRow offset', async () => {
		global.agGrid.createGrid = vi.fn();

		const get = vi.fn()
			.mockResolvedValueOnce( { rows: [ { n: 1 } ], total: 200 } )
			.mockResolvedValueOnce( { rows: [ { n: 2 } ], total: 200 } );
		mockRest( get );

		const el = makeBackendEl( '{"columnDefs":[{"field":"n"}]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];

		opts.datasource.getRows( makeRowsParams( { startRow: 0, endRow: 50 } ) );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		opts.datasource.getRows( makeRowsParams( { startRow: 50, endRow: 100 } ) );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		const url = get.mock.calls[ 1 ][ 0 ];
		expect( url ).toContain( 'offset=50' );
		expect( url ).toContain( 'size=50' );

		delete global.mw.Rest;
	} );

	it( 'passes the server total as the absolute last row', async () => {
		global.agGrid.createGrid = vi.fn();

		const get = vi.fn().mockResolvedValue( {
			rows: [ { n: 1 }, { n: 2 } ], total: 2
		} );
		mockRest( get );

		const el = makeBackendEl( '{"columnDefs":[{"field":"n"}]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];

		const first = makeRowsParams( { startRow: 0, endRow: 50 } );
		opts.datasource.getRows( first );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );
		expect( first.successCallback ).toHaveBeenCalledWith( [ { n: 1 }, { n: 2 } ], 2 );

		delete global.mw.Rest;
	} );

	it( 'serializes sortModel and filterModel into the /page request', async () => {
		global.agGrid.createGrid = vi.fn();

		const get = vi.fn().mockResolvedValue( { rows: [], total: 0 } );
		mockRest( get );

		const el = makeBackendEl( '{"columnDefs":[{"field":"Breed"}]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];

		opts.datasource.getRows( makeRowsParams( {
			startRow: 0,
			endRow: 50,
			sortModel: [ { colId: 'Breed', sort: 'asc' } ],
			filterModel: { Breed: { values: [ 'Collie' ] } }
		} ) );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		const url = decodeURIComponent( get.mock.calls[ 0 ][ 0 ] );
		expect( url ).toContain( 'sort=' );
		expect( url ).toContain( '"colId":"Breed"' );
		expect( url ).toContain( 'filter=' );
		expect( url ).toContain( '"values":["Collie"]' );

		delete global.mw.Rest;
	} );

	it( 'gives aggridSet non-_subject columns a filterParams.valuesSource', async () => {
		global.agGrid.createGrid = vi.fn();

		// /values returns the real { key, label } object shape the SetFilter consumes.
		const get = vi.fn().mockResolvedValue( {
			values: [ { key: 'a', label: 'a' }, { key: 'b', label: 'b' } ],
			partial: false
		} );
		mockRest( get );

		const el = makeBackendEl(
			'{"columnDefs":[' +
			'{"field":"genre","filter":"aggridSet"},' +
			'{"field":"_subject","filter":"aggridSet"}' +
			']}'
		);
		mountGrid( el );

		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		const genre = opts.columnDefs[ 0 ];
		const subject = opts.columnDefs[ 1 ];
		expect( typeof genre.filterParams.valuesSource ).toBe( 'function' );
		expect( subject.filterParams ).toBeUndefined();

		// Calling it GETs /values?column=<field> and resolves { values, partial }.
		get.mockClear();
		const result = await genre.filterParams.valuesSource();
		expect( get ).toHaveBeenCalledWith( '/aggrid/v0/grid/7/42/0/values?column=genre' );
		expect( result ).toEqual( {
			values: [ { key: 'a', label: 'a' }, { key: 'b', label: 'b' } ],
			partial: false
		} );

		delete global.mw.Rest;
	} );

	it( 'shows the error overlay for a backend source with an incomplete handle', () => {
		global.agGrid.createGrid = vi.fn();

		const el = makeEl( '{"columnDefs":[{"field":"name"}]}' );
		el.setAttribute( 'data-mw-aggrid-source', 'smw' );

		expect( () => mountGrid( el ) ).not.toThrow();
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.rowData ).toEqual( [] );
		expect( opts.overlayNoRowsTemplate ).toContain( 'aggrid-error-load' );
	} );

	it( 'fails the block when a backend page fetch rejects', async () => {
		global.agGrid.createGrid = vi.fn();

		const get = vi.fn().mockRejectedValue( new Error( 'network' ) );
		mockRest( get );

		const el = makeBackendEl( '{"columnDefs":[{"field":"name"}]}' );
		mountGrid( el );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];

		const params = makeRowsParams( { startRow: 0, endRow: 50 } );
		opts.datasource.getRows( params );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		expect( params.failCallback ).toHaveBeenCalledTimes( 1 );
		expect( params.successCallback ).not.toHaveBeenCalled();

		delete global.mw.Rest;
	} );

	it( 'mounts an error overlay when the row fetch fails', async () => {
		const get = vi.fn().mockRejectedValue( new Error( 'network' ) );
		const RestMock = vi.fn();
		RestMock.prototype.get = get;
		global.mw.Rest = RestMock;

		const el = makeHandleEl( '{"columnDefs":[{"field":"name"}]}' );
		const skeleton = document.createElement( 'div' );
		skeleton.className = 'ext-aggrid__skeleton';
		el.appendChild( skeleton );
		el.setAttribute( 'aria-busy', 'true' );

		mountGrid( el );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );

		expect( el.querySelector( '.ext-aggrid__skeleton' ) ).toBeNull();
		expect( el.hasAttribute( 'aria-busy' ) ).toBe( false );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
		const opts = global.agGrid.createGrid.mock.calls[ 0 ][ 1 ];
		expect( opts.rowData ).toEqual( [] );
		expect( opts.overlayNoRowsTemplate ).toContain( 'aggrid-error-load' );

		delete global.mw.Rest;
	} );
} );

describe( 'applyFormatters', () => {
	it( 'installs a valueFormatter and consumes the format key', () => {
		const colDef = { field: 'len', format: { style: 'number', locale: 'en-US', suffix: ' m' } };
		applyFormatters( [ colDef ] );
		expect( typeof colDef.valueFormatter ).toBe( 'function' );
		expect( colDef.valueFormatter( { value: 1000 } ) ).toBe( '1,000 m' );
		// `format` is our config, not an AG Grid colDef property — it must be removed so
		// AG Grid does not warn about an unknown colDef property.
		expect( 'format' in colDef ).toBe( false );
	} );

	it( 'does not clobber an explicit valueFormatter but still consumes format', () => {
		const vf = () => 'x';
		const colDef = { field: 'a', format: { style: 'number' }, valueFormatter: vf };
		applyFormatters( [ colDef ] );
		expect( colDef.valueFormatter ).toBe( vf );
		expect( 'format' in colDef ).toBe( false );
	} );

	it( 'recurses into column-group children', () => {
		const child = { field: 'c', format: { style: 'number' } };
		applyFormatters( [ { headerName: 'Group', children: [ child ] } ] );
		expect( typeof child.valueFormatter ).toBe( 'function' );
	} );
} );
