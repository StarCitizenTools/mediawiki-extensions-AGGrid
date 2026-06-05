const { parseConfig, mountGrid } = require( '../../../modules/ext.aggrid/mountGrid.js' );

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
