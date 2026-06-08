const { buildColumnTypes, withLink } = require( './renderers.js' );
const { SetFilter } = require( './setFilter.js' );

/**
 * Build the extension's AG Grid registry — the column types and components every grid
 * mounts with — and let extensions, skins, or site scripts contribute to either map via
 * the `ext.aggrid.register` hook before it is applied. Built fresh per mount so a late
 * registration applies to the next grid.
 *
 * The registry object handed to hook handlers is { columnTypes, components, withLink }:
 *  - columnTypes — AG Grid column-type bundles, referenced from a colDef by `type`.
 *  - components  — AG Grid named components (renderers / filters / editors), referenced
 *                  by a `cellRenderer` / `filter` / `cellEditor` string.
 *  - withLink    — helper that wraps a renderer's output in a scheme-checked anchor.
 * Handlers mutate the maps in place.
 *
 * @return {{columnTypes: Object, components: Object}} The two maps only — withLink is
 *   exposed to hook handlers, not returned to callers of buildRegistry.
 */
function buildRegistry() {
	const registry = {
		columnTypes: buildColumnTypes(),
		components: { aggridSet: SetFilter },
		withLink: withLink
	};
	if ( typeof mw !== 'undefined' && mw.hook ) {
		mw.hook( 'ext.aggrid.register' ).fire( registry );
	}
	return { columnTypes: registry.columnTypes, components: registry.components };
}

module.exports = { buildRegistry: buildRegistry };
