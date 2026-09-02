const { normalize, buildItem } = require( '../../../modules/ext.aggrid/quickSearch.js' );
const toolbar = require( '../../../modules/ext.aggrid/toolbar.js' );

// The wiring mountGrid does around buildItem: ensure the toolbar container exists,
// then append the item to it. Kept here so these tests exercise the box in the DOM
// position it actually occupies.
function setup( el, api, config, onApply ) {
	const bar = toolbar.ensure( el );
	if ( !bar ) {
		return;
	}
	toolbar.addItem( bar, buildItem( api, config, onApply ) );
}

describe( 'normalize', () => {
	it( 'enables with defaults for true', () => {
		expect( normalize( true ) ).toEqual( { placeholder: null, debounceMs: 200 } );
	} );

	it( 'enables with defaults for [] (an empty Lua table arrives as a JSON array)', () => {
		expect( normalize( [] ) ).toEqual( { placeholder: null, debounceMs: 200 } );
	} );

	it( 'disables for false, null, undefined', () => {
		expect( normalize( false ) ).toBeNull();
		expect( normalize( null ) ).toBeNull();
		expect( normalize( undefined ) ).toBeNull();
	} );

	it( 'disables for garbage shapes (stale parser cache predates PHP validation)', () => {
		expect( normalize( 'yes' ) ).toBeNull();
		expect( normalize( 1 ) ).toBeNull();
		expect( normalize( [ 'x' ] ) ).toBeNull();
	} );

	it( 'reads placeholder and rounds/clamps debounceMs', () => {
		expect( normalize( { placeholder: 'Find…', debounceMs: 300.4 } ) )
			.toEqual( { placeholder: 'Find…', debounceMs: 300 } );
		expect( normalize( { debounceMs: -5 } ).debounceMs ).toBe( 0 );
		expect( normalize( { debounceMs: 99999 } ).debounceMs ).toBe( 5000 );
	} );

	it( 'ignores non-string placeholder and non-numeric debounceMs', () => {
		expect( normalize( { placeholder: 5 } ).placeholder ).toBeNull();
		expect( normalize( { debounceMs: 'fast' } ).debounceMs ).toBe( 200 );
	} );
} );

describe( 'setup', () => {
	// Mimic the DOM agGrid.createGrid leaves behind: the placeholder containing
	// the grid's root wrapper. setup() inserts the toolbar as its first child.
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

	function makeApi() {
		return { setGridOption: vi.fn() };
	}

	const DEFAULTS = { placeholder: null, debounceMs: 200 };

	it( 'inserts the toolbar as the first child of .ag-root-wrapper', () => {
		const { el, root, body } = makeMounted();
		setup( el, makeApi(), DEFAULTS );
		const bar = root.firstChild;
		expect( bar.classList.contains( 'ag-toolbar' ) ).toBe( true );
		expect( bar.classList.contains( 'ext-aggrid-toolbar' ) ).toBe( true );
		// Deliberately no role="toolbar": it promises roving-tabindex arrow-key
		// navigation we do not implement, over a text input that needs the arrows.
		expect( bar.getAttribute( 'role' ) ).toBeNull();
		expect( bar.nextSibling ).toBe( body );
		// The Enterprise Quick Access Toolbar DOM shape the theme CSS expects.
		expect( bar.querySelector( '.ag-toolbar-item.ag-toolbar-input' ) ).not.toBeNull();
		expect( bar.querySelector( '.ag-toolbar-input-icon .ag-icon-search' ) ).not.toBeNull();
		expect( bar.querySelector( 'input.ag-toolbar-input-field' ) ).not.toBeNull();
	} );

	it( 'is a logged no-op when there is no .ag-root-wrapper', () => {
		const el = document.createElement( 'div' );
		const warn = vi.spyOn( global.mw.log, 'warn' );
		expect( () => setup( el, makeApi(), DEFAULTS ) ).not.toThrow();
		expect( el.querySelector( '.ext-aggrid-toolbar' ) ).toBeNull();
		expect( warn ).toHaveBeenCalled();
		warn.mockRestore();
	} );

	it( 'uses the i18n placeholder and aria-label by default', () => {
		const { el } = makeMounted();
		setup( el, makeApi(), DEFAULTS );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );
		// setup.js's mw.msg echoes the key.
		expect( input.placeholder ).toBe( 'aggrid-quicksearch-placeholder' );
		expect( input.getAttribute( 'aria-label' ) ).toBe( 'aggrid-quicksearch-label' );
		expect( input.name ).toBe( 'aggrid-quicksearch' );
	} );

	it( 'prefers the author placeholder, set as a DOM property (no HTML)', () => {
		const { el } = makeMounted();
		setup( el, makeApi(), { placeholder: '<b>Find…</b>', debounceMs: 200 } );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );
		expect( input.placeholder ).toBe( '<b>Find…</b>' );
		expect( el.querySelector( '.ext-aggrid-toolbar b' ) ).toBeNull();
	} );

	it( 'debounces input into setGridOption(quickFilterText)', () => {
		vi.useFakeTimers();
		const { el } = makeMounted();
		const api = makeApi();
		setup( el, api, DEFAULTS );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );

		input.value = 'au';
		input.dispatchEvent( new window.Event( 'input' ) );
		input.value = 'aur';
		input.dispatchEvent( new window.Event( 'input' ) );
		expect( api.setGridOption ).not.toHaveBeenCalled();

		vi.advanceTimersByTime( 200 );
		expect( api.setGridOption ).toHaveBeenCalledTimes( 1 );
		expect( api.setGridOption ).toHaveBeenCalledWith( 'quickFilterText', 'aur' );
		vi.useRealTimers();
	} );

	it( 'applies synchronously when debounceMs is 0', () => {
		const { el } = makeMounted();
		const api = makeApi();
		setup( el, api, { placeholder: null, debounceMs: 0 } );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );
		input.value = 'x';
		input.dispatchEvent( new window.Event( 'input' ) );
		expect( api.setGridOption ).toHaveBeenCalledWith( 'quickFilterText', 'x' );
	} );

	it( 'clears the input and the filter on Escape', () => {
		vi.useFakeTimers();
		const { el } = makeMounted();
		const api = makeApi();
		setup( el, api, DEFAULTS );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );

		input.value = 'aurora';
		input.dispatchEvent( new window.Event( 'input' ) );
		vi.advanceTimersByTime( 200 );
		api.setGridOption.mockClear();

		const escape = new window.KeyboardEvent( 'keydown', { key: 'Escape', cancelable: true } );
		input.dispatchEvent( escape );
		expect( input.value ).toBe( '' );
		// Clearing the box consumes the key, so an enclosing expand dialog does not
		// also collapse on the same Escape.
		expect( escape.defaultPrevented ).toBe( true );
		vi.advanceTimersByTime( 200 );
		expect( api.setGridOption ).toHaveBeenCalledWith( 'quickFilterText', '' );
		vi.useRealTimers();
	} );

	it( 'lets Escape through when the box is already empty', () => {
		const { el } = makeMounted();
		setup( el, makeApi(), DEFAULTS );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );
		const escape = new window.KeyboardEvent( 'keydown', { key: 'Escape', cancelable: true } );
		input.dispatchEvent( escape );
		// Nothing to clear, so the key belongs to whatever encloses the grid.
		expect( escape.defaultPrevented ).toBe( false );
	} );

	it( 'calls a provided onApply instead of setGridOption(quickFilterText)', () => {
		vi.useFakeTimers();
		const { el } = makeMounted();
		const api = makeApi();
		const onApply = vi.fn();
		setup( el, api, DEFAULTS, onApply );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );

		input.value = 'sed';
		input.dispatchEvent( new window.Event( 'input' ) );
		vi.advanceTimersByTime( 200 );

		expect( onApply ).toHaveBeenCalledTimes( 1 );
		expect( onApply ).toHaveBeenCalledWith( 'sed' );
		// The injected apply takes over: AG Grid's client-side quick filter is not touched.
		expect( api.setGridOption ).not.toHaveBeenCalled();
		vi.useRealTimers();
	} );

	it( 'clears via onApply on Escape when one is provided', () => {
		const { el } = makeMounted();
		const api = makeApi();
		const onApply = vi.fn();
		setup( el, api, { placeholder: null, debounceMs: 0 }, onApply );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );

		input.value = 'x';
		input.dispatchEvent( new window.Event( 'input' ) );
		input.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape' } ) );

		expect( input.value ).toBe( '' );
		expect( onApply ).toHaveBeenLastCalledWith( '' );
		expect( api.setGridOption ).not.toHaveBeenCalled();
	} );

	it( 'Escape cancels a pending debounced apply (no stale re-filter)', () => {
		vi.useFakeTimers();
		const { el } = makeMounted();
		const api = makeApi();
		setup( el, api, DEFAULTS );
		const input = el.querySelector( '.ext-aggrid-toolbar__input' );

		// Type, then Escape BEFORE the debounce fires: the pending 'aur' apply
		// must be cancelled, leaving exactly one call — the clear.
		input.value = 'aur';
		input.dispatchEvent( new window.Event( 'input' ) );
		input.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Escape' } ) );
		vi.advanceTimersByTime( 200 );

		expect( api.setGridOption ).toHaveBeenCalledTimes( 1 );
		expect( api.setGridOption ).toHaveBeenCalledWith( 'quickFilterText', '' );
		vi.useRealTimers();
	} );
} );
