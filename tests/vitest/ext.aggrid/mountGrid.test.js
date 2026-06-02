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
} );
