// Against the vendored bundle, because csvExport.js relies on AG Grid behaviour the unit
// tests can only mock (the params merge, its inferred formatters), and a bot bumps it.
const { mountGrid } = require( '../../../modules/ext.aggrid/mountGrid.js' );

// `columnTypes` are registered the way a gadget does it, through ext.aggrid.register.
function mount( config, columnTypes ) {
	let api = null;
	global.mw.hook = ( name ) => ( {
		fire: ( first ) => {
			if ( name === 'ext.aggrid.register' ) {
				Object.assign( first.columnTypes, columnTypes );
			} else if ( name === 'ext.aggrid.gridReady' ) {
				api = first;
			}
		}
	} );
	const el = document.createElement( 'div' );
	el.className = 'ext-aggrid';
	el.setAttribute( 'data-mw-aggrid-options', JSON.stringify( config ) );
	document.body.appendChild( el );
	mountGrid( el );
	delete global.mw.hook;
	return api;
}

beforeAll( () => {
	global.agGrid = require( '../../../modules/lib/ag-grid-community/ag-grid-community.min.js' );
} );

afterAll( () => {
	delete global.agGrid;
	document.body.innerHTML = '';
} );

it( 'writes raw values, display text, and guarded formulas', () => {
	const api = mount( {
		columnDefs: [
			{ field: 'ship', headerName: 'Ship', type: 'aggridLink' },
			{ headerName: '=Specs', children: [
				{ field: 'mass', headerName: 'Mass', format: { style: 'number', suffix: ' kg' } }
			] },
			{ field: 'delta', headerName: '+Delta' },
			{ field: 'note', headerName: 'Note' },
			{ field: 'tags', headerName: 'Tags', cellDataType: 'object' },
			{ field: 'rating', headerName: 'Rating', type: 'rating' }
		],
		rowData: [
			{ ship: { text: 'Aurora MR', href: '/wiki/Aurora_MR' }, mass: 1234, delta: -5, note: '=SUM(1)', tags: [ 'a', 'b' ], rating: 2 },
			{ ship: { text: 'Carrack', href: '/wiki/Carrack' }, mass: 4000, delta: 3, note: 'x\n=HYPERLINK("http://example.org")', tags: [ 'c' ], rating: 0 }
		],
		defaultCsvExportParams: {
			columnSeparator: ';',
			suppressQuotes: true,
			processCellCallback: false,
			prependContent: '=1+1'
		}
	}, {
		rating: { valueFormatter: ( p ) => [ 'Bad', 'OK', 'Good' ][ p.value ] }
	} );

	expect( api.getDataAsCsv() ).toBe( [
		'"";"\'=Specs";"";"";"";""',
		'"Ship";"Mass";"\'+Delta";"Note";"Tags";"Rating"',
		'"Aurora MR";"1234";"-5";"\'=SUM(1)";"a, b";"Good"',
		'"Carrack";"4000";"3";"x\n=HYPERLINK(""http://example.org"")";"c";"Bad"'
	].join( '\r\n' ) );
} );
