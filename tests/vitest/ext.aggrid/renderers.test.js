const {
	anchorWrap, withLink, buildColumnTypes, COLUMN_TYPES
} = require( '../../../modules/ext.aggrid/renderers.js' );

describe( 'anchorWrap', () => {
	it( 'wraps a node in an anchor for safe schemes', () => {
		const out = anchorWrap( '/wiki/Aurora_MR', document.createTextNode( 'Aurora MR' ) );
		expect( out.tagName ).toBe( 'A' );
		expect( out.getAttribute( 'href' ) ).toBe( '/wiki/Aurora_MR' );
		expect( out.textContent ).toBe( 'Aurora MR' );
	} );

	it( 'rejects unsafe or non-string hrefs, returning the node bare', () => {
		const node = document.createTextNode( 'x' );
		// eslint-disable-next-line no-script-url
		expect( anchorWrap( 'javascript:alert(1)', node ) ).toBe( node );
		expect( anchorWrap( undefined, node ) ).toBe( node );
	} );
} );

describe( 'withLink', () => {
	it( 'wraps only when the value carries an href', () => {
		const render = ( p ) => document.createTextNode( p.value.text );
		const wrapped = withLink( render );
		expect( wrapped( { value: { text: 'a' } } ).nodeType ).toBe( 3 ); // text node
		expect( wrapped( { value: { text: 'a', href: '/wiki/A' } } ).tagName ).toBe( 'A' );
	} );
} );

describe( 'built-in column types', () => {
	it( 'aggridLink renders an anchor and formats to text', () => {
		const t = buildColumnTypes().aggridLink;
		const el = t.cellRenderer( { value: { text: 'Aurora', href: '/wiki/Aurora' } } );
		expect( el.tagName ).toBe( 'A' );
		expect( el.textContent ).toBe( 'Aurora' );
		expect( t.valueFormatter( { value: { text: 'Aurora' } } ) ).toBe( 'Aurora' );
		expect( t.comparator( { text: 'b' }, { text: 'a' } ) ).toBeGreaterThan( 0 );
	} );

	it( 'aggridImage renders an img, wraps when linked, formats to alt', () => {
		const t = buildColumnTypes().aggridImage;
		const plain = t.cellRenderer( { value: { src: '/t.jpg', width: 120, alt: 'Aurora' } } );
		expect( plain.tagName ).toBe( 'IMG' );
		expect( plain.getAttribute( 'src' ) ).toBe( '/t.jpg' );
		expect( plain.width ).toBe( 120 );
		expect( plain.alt ).toBe( 'Aurora' );
		const linked = t.cellRenderer( { value: { src: '/t.jpg', width: 120, href: '/wiki/A' } } );
		expect( linked.tagName ).toBe( 'A' );
		expect( linked.querySelector( 'img' ) ).not.toBeNull();
		expect( t.valueFormatter( { value: { alt: 'Aurora' } } ) ).toBe( 'Aurora' );
	} );

	it( 'aggridLinkList renders comma-separated anchors and joins text', () => {
		const t = buildColumnTypes().aggridLinkList;
		const el = t.cellRenderer( { value: { links: [
			{ text: 'A', href: '/wiki/A' }, { text: 'B', href: '/wiki/B' }
		] } } );
		expect( el.querySelectorAll( 'a' ).length ).toBe( 2 );
		expect( el.textContent ).toBe( 'A, B' );
		expect( t.valueFormatter( { value: { links: [ { text: 'A' }, { text: 'B' } ] } } ) )
			.toBe( 'A, B' );
	} );

	it( 'renders empty for null values without throwing', () => {
		Object.keys( COLUMN_TYPES ).forEach( ( name ) => {
			const renderer = buildColumnTypes()[ name ].cellRenderer;
			expect( () => renderer( { value: null } ) ).not.toThrow();
		} );
	} );

	it( 'fires the registration hook when mw.hook is present', () => {
		const add = vi.fn();
		const fire = vi.fn();
		global.mw.hook = vi.fn( () => ( { add, fire } ) );
		buildColumnTypes();
		expect( global.mw.hook ).toHaveBeenCalledWith( 'ext.aggrid.registerColumnTypes' );
		expect( fire ).toHaveBeenCalled();
		delete global.mw.hook;
	} );
} );
