const { makeFormatter } = require( '../../../modules/ext.aggrid/format.js' );

describe( 'makeFormatter — number', () => {
	const fmt = ( spec, value ) => makeFormatter( spec )( { value } );

	it( 'groups thousands and appends a unit suffix', () => {
		expect( fmt( { style: 'number', locale: 'en-US', suffix: ' m' }, 1234 ) ).toBe( '1,234 m' );
	} );

	it( 'honours useGrouping=false', () => {
		expect( fmt( { style: 'number', locale: 'en-US', useGrouping: false }, 1234 ) ).toBe( '1234' );
	} );

	it( 'pins fraction digits via decimals and supports a prefix', () => {
		expect( fmt( { style: 'number', locale: 'en-US', decimals: 2, prefix: '$' }, 5 ) ).toBe( '$5.00' );
	} );

	it( 'passes non-numeric, null and empty values through safely', () => {
		expect( fmt( { style: 'number' }, 'n/a' ) ).toBe( 'n/a' );
		expect( fmt( { style: 'number' }, null ) ).toBe( '' );
		expect( fmt( { style: 'number' }, '' ) ).toBe( '' );
	} );
} );

describe( 'makeFormatter — date and guards', () => {
	it( 'formats an ISO date string', () => {
		const out = makeFormatter( { style: 'date', dateStyle: 'short', locale: 'en-US' } )(
			{ value: '2024-03-09' }
		);
		expect( out ).toMatch( /3\/9\/(20)?24/ );
	} );

	// Also exercises the spec.options pass-through (overrides dateStyle); timeZone keeps
	// the assertion stable regardless of the host timezone.
	it( 'formats a full ISO datetime string via options pass-through', () => {
		const out = makeFormatter( {
			style: 'date',
			locale: 'en-US',
			options: { dateStyle: 'short', timeZone: 'UTC' }
		} )( { value: '2024-03-09T12:00:00Z' } );
		expect( out ).toMatch( /3\/9\/(20)?24/ );
	} );

	it( 'passes an invalid date through as its string', () => {
		expect( makeFormatter( { style: 'date' } )( { value: 'not-a-date' } ) ).toBe( 'not-a-date' );
	} );

	it( 'returns null for an unknown or missing spec', () => {
		expect( makeFormatter( null ) ).toBe( null );
		expect( makeFormatter( { style: 'bogus' } ) ).toBe( null );
	} );
} );
