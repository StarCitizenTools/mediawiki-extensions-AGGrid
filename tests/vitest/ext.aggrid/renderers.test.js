const {
	anchorWrap, withLink, buildColumnTypes, COLUMN_TYPES, listValues
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

	it( 'rejects protocol-relative // hrefs', () => {
		const node = document.createTextNode( 'x' );
		expect( anchorWrap( '//evil.com', node ) ).toBe( node );
	} );

	it( 'rejects data: and other non-allowlisted schemes', () => {
		const node = document.createTextNode( 'x' );
		expect( anchorWrap( 'data:text/html,<script>1</script>', node ) ).toBe( node );
		expect( anchorWrap( 'DATA:foo', node ) ).toBe( node );
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

	it( 'aggridImage sorts by alt text', () => {
		const t = buildColumnTypes().aggridImage;
		expect( t.comparator( { alt: 'b' }, { alt: 'a' } ) ).toBeGreaterThan( 0 );
		expect( t.comparator( null, null ) ).toBe( 0 );
	} );

	it( 'aggridLinkList sorts by joined link text', () => {
		const t = buildColumnTypes().aggridLinkList;
		expect( t.comparator(
			{ links: [ { text: 'b' } ] }, { links: [ { text: 'a' } ] }
		) ).toBeGreaterThan( 0 );
		expect( t.comparator( null, null ) ).toBe( 0 );
	} );

	it( 'skips null entries in a link list without throwing', () => {
		const t = buildColumnTypes().aggridLinkList;
		let el;
		expect( () => {
			el = t.cellRenderer( { value: { links: [ null, { text: 'A', href: '/wiki/A' } ] } } );
		} ).not.toThrow();
		expect( el.querySelectorAll( 'a' ).length ).toBe( 1 );
		expect( el.textContent ).toBe( 'A' );
	} );

	it( 'does not set img.src when the value has no src', () => {
		const t = buildColumnTypes().aggridImage;
		const img = t.cellRenderer( { value: { width: 120 } } );
		expect( img.hasAttribute( 'src' ) ).toBe( false );
	} );

	it( 'renders empty for null values without throwing', () => {
		Object.keys( COLUMN_TYPES ).forEach( ( name ) => {
			const renderer = buildColumnTypes()[ name ].cellRenderer;
			expect( () => renderer( { value: null } ) ).not.toThrow();
		} );
	} );

	it( 'derives quick-filter text from the same scalar as valueFormatter', () => {
		// AG Grid derives quick-filter text from the RAW value (never valueFormatter);
		// getQuickFilterText maps the object value to its displayed text so quick
		// search matches what the user sees instead of '[object Object]'.
		const types = buildColumnTypes();
		expect( types.aggridLink.getQuickFilterText( { value: { text: 'Aurora' } } ) )
			.toBe( 'Aurora' );
		expect( types.aggridImage.getQuickFilterText( { value: { alt: 'Aurora' } } ) )
			.toBe( 'Aurora' );
		expect( types.aggridLinkList.getQuickFilterText(
			{ value: { links: [ { text: 'A' }, { text: 'B' } ] } }
		) ).toBe( 'A, B' );
		// Absent values degrade to '' (never the string "undefined").
		expect( types.aggridLink.getQuickFilterText( { value: null } ) ).toBe( '' );
	} );

} );

describe( 'listValues', () => {
	it( 'returns each link text for a { links } value', () => {
		expect( listValues( { links: [
			{ text: 'A' }, { text: 'B', href: '/wiki/B' }
		] } ) ).toEqual( [ 'A', 'B' ] );
	} );

	it( 'returns the elements of a plain array', () => {
		expect( listValues( [ 'A', 'B' ] ) ).toEqual( [ 'A', 'B' ] );
	} );

	it( 'returns null for scalars, null, and single rich objects', () => {
		expect( listValues( 'A' ) ).toBeNull();
		expect( listValues( null ) ).toBeNull();
		expect( listValues( undefined ) ).toBeNull();
		expect( listValues( { text: 'A', href: '/x' } ) ).toBeNull();
	} );

	it( 'drops blank/missing item texts and yields [] for an empty list', () => {
		expect( listValues( { links: [] } ) ).toEqual( [] );
		expect( listValues( { links: [ { text: '' }, { text: 'A' }, null ] } ) )
			.toEqual( [ 'A' ] );
		expect( listValues( [ 'A', '', null ] ) ).toEqual( [ 'A' ] );
	} );
} );

describe( 'aggridLinkList mixed text/link items', () => {
	it( 'renders a text-only item as a bare text node beside a linked item', () => {
		const t = buildColumnTypes().aggridLinkList;
		const el = t.cellRenderer( { value: { links: [
			{ text: 'Manufacturing' }, { text: 'Mining', href: '/wiki/Mining' }
		] } } );
		// One anchor (the linked item); the text-only item is not anchored.
		expect( el.querySelectorAll( 'a' ).length ).toBe( 1 );
		expect( el.textContent ).toBe( 'Manufacturing, Mining' );
	} );
} );

describe( 'rich column types opt out of cellDataType inference', () => {
	// Without cellDataType:false, AG Grid infers type 'object' and injects a
	// filterValueGetter returning the joined display string, which the set filter would use
	// instead of the raw { links: [...] } value — collapsing a multi-value cell back into
	// one option. Lock the opt-out in.
	it( 'sets cellDataType:false on every object-valued built-in type', () => {
		const types = buildColumnTypes();
		[ 'aggridLink', 'aggridImage', 'aggridLinkList' ].forEach( ( name ) => {
			expect( types[ name ].cellDataType ).toBe( false );
		} );
	} );
} );
