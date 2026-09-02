const toolbar = require( '../../../modules/ext.aggrid/toolbar.js' );

// Mimic the DOM agGrid.createGrid leaves behind: the placeholder containing the
// grid's root wrapper. ensure() inserts the toolbar as its first child.
function makeMounted() {
	const el = document.createElement( 'div' );
	const root = document.createElement( 'div' );
	root.className = 'ag-root-wrapper';
	const body = document.createElement( 'div' );
	body.className = 'ag-root-wrapper-body';
	root.appendChild( body );
	el.appendChild( root );
	return { el, root, body };
}

describe( 'ensure', () => {
	it( 'inserts the toolbar as the first child of .ag-root-wrapper', () => {
		const { el, root, body } = makeMounted();
		const bar = toolbar.ensure( el );
		expect( bar ).toBe( root.firstChild );
		expect( bar.classList.contains( 'ag-toolbar' ) ).toBe( true );
		expect( bar.classList.contains( 'ext-aggrid-toolbar' ) ).toBe( true );
		expect( bar.nextSibling ).toBe( body );
	} );

	it( 'is idempotent — a second control reuses the same toolbar', () => {
		const { el, root } = makeMounted();
		const first = toolbar.ensure( el );
		const second = toolbar.ensure( el );
		expect( second ).toBe( first );
		expect( root.querySelectorAll( '.ext-aggrid-toolbar' ) ).toHaveLength( 1 );
	} );

	it( 'is a logged no-op when there is no .ag-root-wrapper', () => {
		const el = document.createElement( 'div' );
		const warn = vi.spyOn( global.mw.log, 'warn' );
		expect( toolbar.ensure( el ) ).toBeNull();
		expect( el.querySelector( '.ext-aggrid-toolbar' ) ).toBeNull();
		expect( warn ).toHaveBeenCalled();
		warn.mockRestore();
	} );

	it( 'does not claim a toolbar nested deeper in the grid', () => {
		// AG Grid renders popups and panels inside the root wrapper; only a direct
		// child is our toolbar.
		const { el, root } = makeMounted();
		const stray = document.createElement( 'div' );
		stray.className = 'ext-aggrid-toolbar';
		root.querySelector( '.ag-root-wrapper-body' ).appendChild( stray );
		const bar = toolbar.ensure( el );
		expect( bar ).not.toBe( stray );
		expect( bar ).toBe( root.firstChild );
	} );
} );

describe( 'addItem', () => {
	it( 'appends items in call order', () => {
		const { el } = makeMounted();
		const bar = toolbar.ensure( el );
		const a = document.createElement( 'div' );
		const b = document.createElement( 'div' );
		toolbar.addItem( bar, a );
		toolbar.addItem( bar, b );
		expect( Array.from( bar.children ) ).toEqual( [ a, b ] );
	} );

	it( 'marks an end-aligned item with the AG Grid and extension hooks', () => {
		const { el } = makeMounted();
		const bar = toolbar.ensure( el );
		const item = document.createElement( 'div' );
		toolbar.addItem( bar, item, { end: true } );
		expect( item.classList.contains( 'ag-toolbar-right-start' ) ).toBe( true );
		expect( item.classList.contains( 'ext-aggrid-toolbar__item--end' ) ).toBe( true );
	} );

	it( 'leaves a plain item unmarked', () => {
		const { el } = makeMounted();
		const bar = toolbar.ensure( el );
		const item = document.createElement( 'div' );
		toolbar.addItem( bar, item, {} );
		expect( item.classList.contains( 'ext-aggrid-toolbar__item--end' ) ).toBe( false );
	} );
} );
