/* global agGrid */

// AG Grid theme colour params mapped to Codex design tokens (CSS variables).
// The grid follows the wiki light/dark/OS scheme and skin colour
// customisations through the cascade — no JS theme-syncing. Fallbacks are the
// REL1_43 light token values, so skins without Codex tokens still render a
// usable light grid. Typography inherits the page font; AG Grid's concrete
// fontSize/spacing defaults are kept (it needs them for row-height layout).
const THEME_PARAMS = {
	backgroundColor: 'var(--background-color-base, #fff)',
	foregroundColor: 'var(--color-base, #202122)',
	borderColor: 'var(--border-color-base, #a2a9b1)',
	chromeBackgroundColor: 'var(--background-color-neutral-subtle, #f8f9fa)',
	headerBackgroundColor: 'var(--background-color-neutral-subtle, #f8f9fa)',
	headerTextColor: 'var(--color-base, #202122)',
	accentColor: 'var(--color-progressive, #36c)',
	rowHoverColor: 'var(--background-color-interactive-subtle, #f8f9fa)',
	selectedRowBackgroundColor: 'var(--background-color-progressive-subtle, #f1f4fd)',
	fontFamily: 'inherit',
	// Inherit the page color-scheme rather than AG Grid's default of "light", so
	// the wrapper doesn't pin itself to light and light-dark() color tokens
	// resolve to the page's current mode inside the grid.
	browserColorScheme: 'inherit'
};

let cachedTheme = null;

/**
 * The shared AG Grid theme, built once from themeQuartz.
 *
 * @return {Object} An AG Grid Theme object.
 */
function getWikiTheme() {
	if ( !cachedTheme ) {
		cachedTheme = agGrid.themeQuartz.withParams( THEME_PARAMS );
	}
	return cachedTheme;
}

module.exports = { THEME_PARAMS: THEME_PARAMS, getWikiTheme: getWikiTheme };
