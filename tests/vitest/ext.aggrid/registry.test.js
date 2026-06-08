const { buildRegistry } = require( '../../../modules/ext.aggrid/registry.js' );

describe( 'buildRegistry', () => {
	afterEach( () => {
		delete global.mw.hook;
	} );

	it( 'includes the built-in column types and the set-filter component', () => {
		const reg = buildRegistry();
		expect( typeof reg.columnTypes.aggridLink.cellRenderer ).toBe( 'function' );
		expect( typeof reg.columnTypes.aggridImage.cellRenderer ).toBe( 'function' );
		expect( typeof reg.columnTypes.aggridLinkList.cellRenderer ).toBe( 'function' );
		expect( typeof reg.components.aggridSet ).toBe( 'function' );
	} );

	it( 'returns only the two maps — withLink is for handlers, not callers', () => {
		expect( buildRegistry().withLink ).toBeUndefined();
	} );

	it( 'fires ext.aggrid.register with a registry handlers can extend', () => {
		let seen = null;
		global.mw.hook = vi.fn( () => ( {
			add: vi.fn(),
			fire: ( registry ) => {
				seen = registry;
				registry.columnTypes.myType = { cellRenderer: () => null };
				registry.components.myFilter = function () {};
			}
		} ) );

		const reg = buildRegistry();

		expect( global.mw.hook ).toHaveBeenCalledWith( 'ext.aggrid.register' );
		expect( typeof seen.withLink ).toBe( 'function' );
		expect( reg.columnTypes.myType ).toBeTruthy();
		expect( typeof reg.components.myFilter ).toBe( 'function' );
	} );

	it( 'does not throw when mw.hook is absent', () => {
		delete global.mw.hook;
		expect( () => buildRegistry() ).not.toThrow();
	} );
} );
