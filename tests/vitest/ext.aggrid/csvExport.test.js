const csvExport = require( '../../../modules/ext.aggrid/csvExport.js' );

// The params AG Grid hands processCellCallback: the cell's raw value, plus a
// formatValue() that runs the column's valueFormatter (or returns the value as-is
// when the column has none).
function cellParams( value, colDef ) {
	return {
		value,
		column: { getColDef: () => colDef },
		formatValue: ( v ) => ( colDef.valueFormatter ? colDef.valueFormatter( { value: v } ) : v )
	};
}

describe( 'normalize', () => {
	it( 'enables with defaults for true', () => {
		expect( csvExport.normalize( true ) ).toEqual( { label: null } );
	} );

	it( 'enables with defaults for an empty Lua table (arrives as [])', () => {
		expect( csvExport.normalize( [] ) ).toEqual( { label: null } );
	} );

	it( 'takes the author label from the table form', () => {
		expect( csvExport.normalize( { label: 'Get the data' } ) ).toEqual( { label: 'Get the data' } );
	} );

	it( 'disables for false, absent, and malformed values', () => {
		expect( csvExport.normalize( false ) ).toBeNull();
		expect( csvExport.normalize( undefined ) ).toBeNull();
		expect( csvExport.normalize( 'yes' ) ).toBeNull();
		expect( csvExport.normalize( [ 'x' ] ) ).toBeNull();
	} );
} );

describe( 'defaultParams', () => {
	describe( 'processCellCallback', () => {
		const { processCellCallback } = csvExport.defaultParams( 'Dogs' );

		it( 'exports a rich cell as its display text', () => {
			const colDef = { valueFormatter: ( p ) => p.value.text };
			const value = { text: 'Akita', href: '/wiki/Akita' };
			expect( processCellCallback( cellParams( value, colDef ) ) ).toBe( 'Akita' );
		} );

		it( 'exports the raw value of a column that opts out of export formatting', () => {
			const colDef = {
				valueFormatter: ( p ) => `${ p.value } kg`,
				useValueFormatterForExport: false
			};
			expect( processCellCallback( cellParams( 27, colDef ) ) ).toBe( 27 );
		} );

		it.each( [
			[ '=1+1', '\'=1+1' ],
			[ '+44 20 7946 0000', '\'+44 20 7946 0000' ],
			[ '-2+3', '\'-2+3' ],
			[ '@SUM(A1)', '\'@SUM(A1)' ],
			[ '\t=1+1', '\'\t=1+1' ],
			[ '\r=1+1', '\'\r=1+1' ],
			[ '\n=1+1', '\'\n=1+1' ]
		] )( 'prefixes text starting with a formula character: %j', ( value, expected ) => {
			expect( processCellCallback( cellParams( value, {} ) ) ).toBe( expected );
		} );

		it( 'prefixes the formatted text, not only the raw value', () => {
			const colDef = { valueFormatter: ( p ) => p.value.text };
			const value = { text: '=HYPERLINK("http://example.org")', href: '/wiki/X' };
			expect( processCellCallback( cellParams( value, colDef ) ) )
				.toBe( '\'=HYPERLINK("http://example.org")' );
		} );

		// AG Grid infers a data type for a plain column and gives it a formatter, so
		// formatValue() hands back text even where the author set none: "-5" for a
		// number, "a,b" for an array.
		const inferredNumber = { valueFormatter: ( p ) => String( p.value ) };
		const inferredObject = { valueFormatter: ( p ) => p.value.toString() };

		it( 'exports a number as a number, so a negative one is not prefixed', () => {
			expect( processCellCallback( cellParams( -5, inferredNumber ) ) ).toBe( -5 );
			expect( processCellCallback( cellParams( 1234.5, inferredNumber ) ) ).toBe( 1234.5 );
		} );

		it( 'joins a plain multi-value cell with ", ", like a list cell', () => {
			expect( processCellCallback( cellParams( [ 'a', 'b' ], inferredObject ) ) ).toBe( 'a, b' );
		} );

		it( 'prefixes a multi-value cell whose text starts with a formula character', () => {
			expect( processCellCallback( cellParams( [ '=1+1', 'b' ], inferredObject ) ) ).toBe( '\'=1+1, b' );
		} );

		it( 'leaves text untouched when a formula character is not leading', () => {
			expect( processCellCallback( cellParams( 'a=b', {} ) ) ).toBe( 'a=b' );
			expect( processCellCallback( cellParams( 'Akita', {} ) ) ).toBe( 'Akita' );
		} );
	} );

	it( 'prefixes a column header starting with a formula character', () => {
		const { processHeaderCallback } = csvExport.defaultParams( 'Dogs' );
		const column = {};
		const names = new Map( [ [ column, '=Total' ] ] );
		const api = { getDisplayNameForColumn: ( col, location ) => location === 'csv' && names.get( col ) };
		expect( processHeaderCallback( { column, api } ) ).toBe( '\'=Total' );
		names.set( column, 'Breed' );
		expect( processHeaderCallback( { column, api } ) ).toBe( 'Breed' );
	} );

	it( 'prefixes a column-group header starting with a formula character', () => {
		const { processGroupHeaderCallback } = csvExport.defaultParams( 'Dogs' );
		const columnGroup = {};
		const api = {
			getDisplayNameForColumnGroup: ( group, location ) => ( group === columnGroup && location === 'csv' ? '@Group' : null )
		};
		expect( processGroupHeaderCallback( { columnGroup, api } ) ).toBe( '\'@Group' );
	} );

	it.each( [
		[ 'Dog breeds', 'Dog breeds.csv' ],
		// AG Grid only appends the extension to a name with no dot in it.
		[ 'St. Bernard', 'St. Bernard.csv' ],
		// Characters a title may hold but a file name may not.
		[ 'Ships/Fighters: A*B?"C"\\D', 'Ships_Fighters_ A_B__C__D.csv' ]
	] )( 'names the file after the page title: %j', ( title, expected ) => {
		expect( csvExport.defaultParams( title ).fileName ).toBe( expected );
	} );

	it( 'leaves the file name to AG Grid when there is no page title', () => {
		expect( 'fileName' in csvExport.defaultParams( null ) ).toBe( false );
		expect( 'fileName' in csvExport.defaultParams( '' ) ).toBe( false );
	} );
} );

describe( 'buildItem', () => {
	function makeApi() {
		return { exportDataAsCsv: vi.fn() };
	}

	it( 'labels the button with the default message', () => {
		const button = csvExport.buildItem( makeApi(), { label: null } ).querySelector( 'button' );
		expect( button.getAttribute( 'aria-label' ) ).toBe( 'aggrid-csvexport-label' );
		expect( button.getAttribute( 'title' ) ).toBe( 'aggrid-csvexport-label' );
	} );

	it( 'labels the button with the author label when given', () => {
		const button = csvExport.buildItem( makeApi(), { label: 'Get the data' } ).querySelector( 'button' );
		expect( button.getAttribute( 'aria-label' ) ).toBe( 'Get the data' );
		expect( button.getAttribute( 'title' ) ).toBe( 'Get the data' );
	} );

	it( 'exports with the grid defaults on click, passing no overriding params', () => {
		const api = makeApi();
		csvExport.buildItem( api, { label: null } ).querySelector( 'button' ).dispatchEvent(
			new window.MouseEvent( 'click', { bubbles: true } )
		);
		expect( api.exportDataAsCsv ).toHaveBeenCalledTimes( 1 );
		// Params passed here would override the author's defaultCsvExportParams.
		expect( api.exportDataAsCsv.mock.calls[ 0 ] ).toEqual( [] );
	} );
} );
