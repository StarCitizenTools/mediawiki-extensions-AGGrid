const { THEME_PARAMS, getWikiTheme } = require( '../../../modules/ext.aggrid/theme.js' );

describe( 'THEME_PARAMS', () => {
	const expected = {
		backgroundColor: 'var(--background-color-base, #fff)',
		foregroundColor: 'var(--color-base, #202122)',
		borderColor: 'var(--border-color-base, #a2a9b1)',
		chromeBackgroundColor: 'var(--background-color-neutral-subtle, #f8f9fa)',
		headerBackgroundColor: 'var(--background-color-neutral-subtle, #f8f9fa)',
		headerTextColor: 'var(--color-base, #202122)',
		accentColor: 'var(--color-progressive, #36c)',
		rowHoverColor: 'var(--background-color-interactive-subtle, #f8f9fa)',
		selectedRowBackgroundColor: 'var(--background-color-progressive-subtle, #f1f4fd)'
	};

	it( 'maps every colour param to a Codex token with a fallback', () => {
		Object.keys( expected ).forEach( ( key ) => {
			expect( THEME_PARAMS[ key ] ).toBe( expected[ key ] );
		} );
	} );

	it( 'inherits typography rather than setting a font', () => {
		expect( THEME_PARAMS.fontFamily ).toBe( 'inherit' );
		expect( THEME_PARAMS.fontSize ).toBeUndefined();
	} );
} );

describe( 'getWikiTheme', () => {
	it( 'builds the theme from themeQuartz.withParams and memoises it', () => {
		const built = { id: 'wiki-theme' };
		const withParams = vi.fn( () => built );
		global.agGrid = { themeQuartz: { withParams } };

		expect( getWikiTheme() ).toBe( built );
		expect( getWikiTheme() ).toBe( built );
		expect( withParams ).toHaveBeenCalledTimes( 1 );
		expect( withParams ).toHaveBeenCalledWith( THEME_PARAMS );

		delete global.agGrid;
	} );
} );
