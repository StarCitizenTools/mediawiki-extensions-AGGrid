/* global agGrid */

const { getWikiTheme } = require( './theme.js' );
const { buildRegistry } = require( './registry.js' );
const { makeFormatter } = require( './format.js' );
const quickSearch = require( './quickSearch.js' );

const PLACEHOLDER_SELECTOR = '.ext-aggrid';
const CONFIG_ATTR = 'data-mw-aggrid-options';
// Rows fetched per Infinite Row Model block, and the native pagination page size.
// Each block maps to one offset page of the backend query.
const BLOCK_SIZE = 50;
// Marks a placeholder as already mounted so re-runs (e.g. wikipage.content
// firing on initial + re-rendered content) never call createGrid twice on it.
const INIT_CLASS = 'ext-aggrid--init';

/**
 * Read and parse the gridOptions carried in the placeholder's config attribute.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @return {Object|null} Parsed gridOptions, or null if absent/invalid.
 */
function parseConfig( el ) {
	const raw = el.getAttribute( CONFIG_ATTR );
	if ( !raw ) {
		return null;
	}
	try {
		return JSON.parse( raw );
	} catch ( e ) {
		mw.log.error( '[ext.aggrid] Failed to parse grid config', e );
		return null;
	}
}

/**
 * Read the {pageid, rev, index} fetch handle from the placeholder, or null if
 * it does not carry a complete handle.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @return {Object|null} { pageid, rev, index } or null.
 */
function readHandle( el ) {
	const pageid = el.getAttribute( 'data-mw-aggrid-pageid' );
	const rev = el.getAttribute( 'data-mw-aggrid-rev' );
	const index = el.getAttribute( 'data-mw-aggrid-index' );
	if ( !pageid || !rev || index === null ) {
		return null;
	}
	return { pageid, rev, index };
}

/**
 * Build the REST path for a placeholder that fetches all its rows once, or null
 * if it does not carry a complete handle.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @return {string|null}
 */
function restPath( el ) {
	const handle = readHandle( el );
	if ( !handle ) {
		return null;
	}
	return `/aggrid/v0/grid/${ handle.pageid }/${ handle.rev }/${ handle.index }/rows`;
}

/**
 * Attach declarative valueFormatters: for each colDef carrying a `format` spec,
 * install the matching Intl-backed formatter unless the author already set a
 * valueFormatter. Recurses into column-group children. Applied on both the inline
 * and backend paths via prepareGridOptions.
 *
 * The `format` key is consumed (deleted) once read: it is our own config, not an AG
 * Grid colDef property, and AG Grid warns about unknown colDef properties otherwise.
 *
 * @param {Array} colDefs gridOptions.columnDefs (or a group's children).
 */
function applyFormatters( colDefs ) {
	if ( !Array.isArray( colDefs ) ) {
		return;
	}
	colDefs.forEach( ( colDef ) => {
		if ( !colDef || typeof colDef !== 'object' ) {
			return;
		}
		if ( Array.isArray( colDef.children ) ) {
			applyFormatters( colDef.children );
		}
		if ( colDef.format ) {
			if ( !colDef.valueFormatter ) {
				const formatter = makeFormatter( colDef.format );
				if ( formatter ) {
					colDef.valueFormatter = formatter;
				}
			}
			delete colDef.format;
		}
	} );
}

/**
 * Apply the built-in theme, extension registry (column types + components) to
 * gridOptions, consume the extension's own quickSearch option, and drop the loading
 * skeleton/busy state from the container. Shared by the inline and backend mount
 * paths so both wire the built-ins identically.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions gridOptions to prepare in place.
 * @return {Object|null} Normalized quickSearch config, or null when disabled.
 */
function prepareGridOptions( el, gridOptions ) {
	// Apply the wiki theme unless the author already chose one.
	if ( !gridOptions.theme ) {
		gridOptions.theme = getWikiTheme();
	}
	// Attach declarative valueFormatters from colDef.format specs.
	applyFormatters( gridOptions.columnDefs );
	// Build the extension registry (column types + components) and let skins/gadgets
	// extend either map via the ext.aggrid.register hook, then apply both to gridOptions.
	// Built-ins win over author-supplied entries of the same name — those can't carry the
	// renderer/component functions across the JSON boundary anyway.
	const registry = buildRegistry();
	gridOptions.columnTypes = Object.assign( {}, gridOptions.columnTypes, registry.columnTypes );
	gridOptions.components = Object.assign( {}, gridOptions.components, registry.components );
	// Consume our own quickSearch option before createGrid — like colDef.format above,
	// it is extension config, not an AG Grid property, and AG Grid warns about unknown
	// gridOptions keys. Whether the config is then wired is the caller's decision:
	// backend mounts never wire it (AG Grid's quick filter is client-side-model-only).
	const quickSearchConfig = quickSearch.normalize( gridOptions.quickSearch );
	delete gridOptions.quickSearch;
	// AG Grid expects an empty container.
	const skeleton = el.querySelector( '.ext-aggrid__skeleton' );
	if ( skeleton ) {
		skeleton.remove();
	}
	el.removeAttribute( 'aria-busy' );
	return quickSearchConfig;
}

/**
 * Create the grid, build the optional quick-search toolbar, and announce the grid so
 * gadgets/skins can grab the api after mount (wire external filters, toolbar controls,
 * programmatic control). Single fire site for every mount path (inline, backend,
 * error). The toolbar is built before the announce so gridReady handlers observe the
 * final grid chrome (a gadget can detect .ext-aggrid-toolbar and skip its own search
 * box). Guarded so the vitest harness — which has no mw.hook — is unaffected, matching
 * registry.js.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions Fully-prepared gridOptions.
 * @param {Object|null} [quickSearchConfig] Normalized quickSearch config to wire.
 * @param {Function} [makeOnQuickSearch] Factory `( api ) => ( value ) => void` building
 *   the quick-search apply handler once the api exists (it needs the api to drive
 *   paging). Omitted on the client/inline paths (the box drives AG Grid's client-side
 *   quick filter); the backend path injects a server-routing factory.
 * @return {Object} The AG Grid GridApi.
 */
function createAndAnnounce( el, gridOptions, quickSearchConfig, makeOnQuickSearch ) {
	// agGrid is the global exposed by the vendored AG Grid bundle.
	const api = agGrid.createGrid( el, gridOptions );
	if ( quickSearchConfig ) {
		const onApply = makeOnQuickSearch ? makeOnQuickSearch( api ) : undefined;
		quickSearch.setup( el, api, quickSearchConfig, onApply );
	}
	if ( typeof mw !== 'undefined' && mw.hook ) {
		mw.hook( 'ext.aggrid.gridReady' ).fire( api, el, gridOptions );
	}
	return api;
}

/**
 * Drop the loading skeleton/busy state and create the grid.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions Fully-populated gridOptions (rowData present).
 */
function finishMount( el, gridOptions ) {
	const quickSearchConfig = prepareGridOptions( el, gridOptions );
	createAndAnnounce( el, gridOptions, quickSearchConfig );
}

/**
 * Mount the grid in an error state: render the column headers from the config
 * with no rows, and surface "data failed to load" via AG Grid's overlay.
 * Terminal — the element keeps INIT_CLASS so it is not retried (lazyMount has
 * already unobserved it).
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions Parsed gridOptions (rowData absent/unusable).
 */
function mountError( el, gridOptions ) {
	gridOptions.rowData = [];
	// No quick-search toolbar on an error mount: a search box over a grid that
	// could not load its rows is a dead control.
	delete gridOptions.quickSearch;
	// AG Grid has no dedicated error overlay; show the message via the no-rows
	// overlay, which is auto-shown for an empty client-side row model. The
	// interface message is escaped before being injected into the overlay HTML.
	gridOptions.overlayNoRowsTemplate =
		`<span class="ext-aggrid__overlay-error">${
			mw.html.escape( mw.msg( 'aggrid-error-load' ) )
		}</span>`;
	finishMount( el, gridOptions );
}

/**
 * Build a set-filter values source: a function the SetFilter calls to fetch the
 * distinct values for one column from the backend.
 *
 * @param {string} valuesUrl Base /values REST URL.
 * @param {string} field The column field whose values to fetch.
 * @return {Function} () => Promise<Array> resolving to the column's values.
 */
function makeValuesSource( valuesUrl, field ) {
	return () => new mw.Rest().get( `${ valuesUrl }?column=${ encodeURIComponent( field ) }` )
		.then( ( d ) => ( { values: ( d && d.values ) || [], partial: !!( d && d.partial ) } ) );
}

/**
 * Build the query string for a /page request.
 *
 * @param {number} offset Zero-based index of the first row to fetch.
 * @param {number} limit Number of rows to request (the block size).
 * @param {Array} sortModel AG Grid sort model; serialized only when non-empty.
 * @param {Object} filterModel AG Grid filter model; serialized only when non-empty.
 * @param {string} [quickSearchTerm] Free-text quick-search term; sent as `q` only when non-empty.
 * @return {string} The query string (no leading '?'), always carrying offset and limit.
 */
function buildPageQuery( offset, limit, sortModel, filterModel, quickSearchTerm ) {
	const params = new URLSearchParams();
	params.set( 'offset', String( offset ) );
	// The REST endpoint names the page-size parameter "size".
	params.set( 'size', String( limit ) );
	if ( Array.isArray( sortModel ) && sortModel.length ) {
		params.set( 'sort', JSON.stringify( sortModel ) );
	}
	if ( filterModel && Object.keys( filterModel ).length ) {
		params.set( 'filter', JSON.stringify( filterModel ) );
	}
	if ( quickSearchTerm ) {
		params.set( 'q', quickSearchTerm );
	}
	return params.toString();
}

/**
 * Mount a backend (SMW) grid using AG Grid's Infinite Row Model over the backend's
 * offset pagination, driving AG Grid's native pagination bar. Each block
 * [startRow, endRow) is fetched by offset/limit, and the total count returned by the
 * server is passed as the absolute last row so the page bar shows all pages and
 * jump-to-page works. Sort/filter are delegated to the server: changing either purges
 * the cache and re-requests from offset 0 automatically (offset paging is stateless,
 * so no reset bookkeeping is needed).
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 * @param {Object} gridOptions Parsed gridOptions (no rowData; carries a backend handle).
 */
function mountBackend( el, gridOptions ) {
	const handle = readHandle( el );
	if ( !handle ) {
		// A backend grid can't render without a stored query (e.g. a preview
		// placeholder that carries data-mw-aggrid-source but no pageid/rev/index).
		mountError( el, gridOptions );
		return;
	}
	const base = `/aggrid/v0/grid/${ handle.pageid }/${ handle.rev }/${ handle.index }`;
	const pageUrl = `${ base }/page`;
	const valuesUrl = `${ base }/values`;

	// Inject the set-filter value source for backend set-filter columns. The
	// _subject column is the row's own page title and has no server value list.
	( gridOptions.columnDefs || [] ).forEach( ( colDef ) => {
		if ( colDef.filter === 'aggridSet' && colDef.field && colDef.field !== '_subject' ) {
			colDef.filterParams = Object.assign( {}, colDef.filterParams, {
				valuesSource: makeValuesSource( valuesUrl, colDef.field )
			} );
		}
	} );

	// The active quick-search term lives here so the datasource reads the latest
	// value on each block fetch; the toolbar mutates it and purges the cache below.
	const state = { q: '' };

	const datasource = {
		getRows( params ) {
			const offset = params.startRow;
			const limit = params.endRow - params.startRow;
			const query = buildPageQuery(
				offset, limit, params.sortModel, params.filterModel, state.q
			);
			new mw.Rest().get( `${ pageUrl }?${ query }` )
				.then( ( data ) => {
					const rows = ( data && data.rows ) || [];
					// total is the absolute row count across all pages; passed as
					// lastRow so the grid knows the final page. A missing/non-numeric
					// total would make AG Grid treat the block as "not last" and page
					// forever, so treat that as a load failure instead.
					if ( !data || typeof data.total !== 'number' ) {
						mw.log.error( '[ext.aggrid] grid page response missing total' );
						params.failCallback();
						return;
					}
					params.successCallback( rows, data.total );
				} )
				.catch( ( e ) => {
					mw.log.error( '[ext.aggrid] failed to fetch grid page', e );
					params.failCallback();
				} );
		}
	};

	// prepareGridOptions consumes the quickSearch option. Unlike the client paths
	// (which drive AG Grid's client-model-only quick filter), the backend wires the
	// box to a server round-trip: typing updates state.q, resets to the first page,
	// and purges the infinite cache so the new total and rows reload from offset 0.
	const quickSearchConfig = prepareGridOptions( el, gridOptions );
	// Built once the api exists (createAndAnnounce supplies it): on each apply, stash
	// the term, jump to the first page, and purge the infinite cache so the new total
	// and rows reload from offset 0.
	const makeOnQuickSearch = ( api ) => ( value ) => {
		state.q = value;
		api.paginationGoToFirstPage();
		api.purgeInfiniteCache();
	};
	// Configure the Infinite Row Model over the offset datasource. The server's
	// total count drives the page bar; offset paging is stateless so AG Grid can
	// request any page directly.
	gridOptions.rowModelType = 'infinite';
	gridOptions.cacheBlockSize = BLOCK_SIZE;
	// Default to AG Grid's native pagination bar, but let the author opt into
	// infinite scroll by setting pagination=false in their gridOptions.
	if ( gridOptions.pagination === undefined ) {
		gridOptions.pagination = true;
	}
	gridOptions.paginationPageSize = BLOCK_SIZE;
	// The page size must stay equal to cacheBlockSize for the offset block mapping;
	// hide AG Grid's page-size selector so the user can't desync them.
	gridOptions.paginationPageSizeSelector = false;
	gridOptions.datasource = datasource;

	// The Infinite Row Model fetches block 0 automatically on mount.
	createAndAnnounce( el, gridOptions, quickSearchConfig, makeOnQuickSearch );
}

/**
 * Mount an AG Grid into a single placeholder. No-op if already mounted.
 *
 * Inline/preview placeholders carry rowData and mount immediately. Otherwise the
 * rows are fetched once over REST (client-side row model) before mounting.
 *
 * @param {HTMLElement} el The .ext-aggrid container.
 */
function mountGrid( el ) {
	if ( el.classList.contains( INIT_CLASS ) ) {
		return;
	}
	const gridOptions = parseConfig( el );
	if ( !gridOptions ) {
		return;
	}
	// Mark before any async work so a concurrent/re-entrant pass can't double-mount.
	el.classList.add( INIT_CLASS );

	const source = el.getAttribute( 'data-mw-aggrid-source' );
	if ( source ) {
		mountBackend( el, gridOptions );
		return;
	}

	if ( Array.isArray( gridOptions.rowData ) ) {
		finishMount( el, gridOptions );
		return;
	}

	const path = restPath( el );
	if ( !path ) {
		mw.log.error( '[ext.aggrid] placeholder has neither rowData nor a fetch handle' );
		mountError( el, gridOptions );
		return;
	}
	new mw.Rest().get( path )
		.then( ( data ) => {
			gridOptions.rowData = ( data && data.rows ) || [];
			finishMount( el, gridOptions );
		} )
		.catch( ( e ) => {
			mw.log.error( '[ext.aggrid] failed to fetch grid rows', e );
			mountError( el, gridOptions );
		} );
}

/**
 * Mount every AG Grid placeholder within a root (defaults to the document).
 *
 * @param {HTMLElement|Document} [root] Scope to search; defaults to document.
 */
function mountAll( root ) {
	const scope = root || document;
	Array.prototype.forEach.call(
		scope.querySelectorAll( PLACEHOLDER_SELECTOR ),
		mountGrid
	);
}

module.exports = { parseConfig, mountGrid, mountAll, applyFormatters };
