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
		global.agGrid = { createGrid: vi.fn() };
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
} );
