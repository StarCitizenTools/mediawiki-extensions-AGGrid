describe( 'lazyMount', () => {
	let observed, intersectCb, lazyMount, loadAgGrid;

	beforeEach( async () => {
		observed = [];
		intersectCb = null;
		global.agGrid = { createGrid: vi.fn(), themeQuartz: { withParams: () => ( {} ) } };
		global.mw = { config: { get: () => '/w/extensions' }, log: { error: () => {} } };
		// Pretend the bundle is already present so loadAgGrid() resolves without injecting.
		window.agGrid = global.agGrid;
		global.IntersectionObserver = class {
			constructor( cb ) {
				intersectCb = cb;
			}

			observe( el ) {
				observed.push( el );
			}

			unobserve() {}

			disconnect() {}
		};
		// Reset modules so the singleton observer is recreated per test, ensuring
		// the IntersectionObserver constructor (which captures intersectCb) runs fresh.
		vi.resetModules();
		// Concat, not a template literal, so Vite can statically analyse this import.
		// eslint-disable-next-line prefer-template
		( { lazyMount, loadAgGrid } = await import( '../../../modules/ext.aggrid/lazyMount.js?t=' + Date.now() ) );
	} );

	afterEach( () => {
		delete global.agGrid;
		delete global.mw;
		delete global.IntersectionObserver;
		delete window.agGrid;
	} );

	function makeRoot( n ) {
		const root = document.createElement( 'div' );
		for ( let i = 0; i < n; i++ ) {
			const el = document.createElement( 'div' );
			el.className = 'ext-aggrid';
			el.setAttribute( 'data-mw-aggrid-options', '{"columnDefs":[],"rowData":[]}' );
			root.appendChild( el );
		}
		return root;
	}

	it( 'observes each not-yet-mounted placeholder', () => {
		lazyMount( makeRoot( 2 ) );
		expect( observed.length ).toBe( 2 );
	} );

	it( 'loads and mounts a placeholder when it intersects', async () => {
		lazyMount( makeRoot( 1 ) );
		const unobserve = vi.fn();
		intersectCb(
			[ { isIntersecting: true, target: observed[ 0 ] } ],
			{ unobserve }
		);
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );
		expect( unobserve ).toHaveBeenCalledWith( observed[ 0 ] );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does nothing for a non-intersecting entry', () => {
		lazyMount( makeRoot( 1 ) );
		intersectCb( [ { isIntersecting: false, target: observed[ 0 ] } ], { unobserve() {} } );
		expect( global.agGrid.createGrid ).not.toHaveBeenCalled();
	} );

	it( 'falls back to eager mount when IntersectionObserver is unavailable', async () => {
		delete global.IntersectionObserver;
		lazyMount( makeRoot( 2 ) );
		await new Promise( ( r ) => {
			setTimeout( r, 0 );
		} );
		expect( global.agGrid.createGrid ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'resets the memo after a failed load so a second call retries with a new script', async () => {
		// Remove the pre-loaded agGrid so loadAgGrid() actually injects a <script>.
		delete window.agGrid;
		delete global.agGrid;

		const appendSpy = vi.spyOn( document.head, 'appendChild' );

		// First call — promise pending, <script> injected.
		const firstPromise = loadAgGrid();
		const firstScript = appendSpy.mock.calls[ 0 ][ 0 ];

		// Simulate network error — onerror resets the memo.
		firstScript.onerror();

		// The first promise must reject.
		await expect( firstPromise ).rejects.toThrow( 'failed to load AG Grid bundle' );

		// Second call — memo was reset, so a brand-new <script> should be injected.
		loadAgGrid();
		expect( appendSpy ).toHaveBeenCalledTimes( 2 );

		appendSpy.mockRestore();
	} );
} );
