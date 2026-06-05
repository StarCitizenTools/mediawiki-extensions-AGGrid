// Built-in AG Grid column types for rich cells (links, images, link lists).
//
// Renderers are PURE and SYNCHRONOUS: value -> DOM, never any network. Link hrefs and
// thumbnail src are resolved server-side (Lua/PHP) before the JSON boundary, so a
// renderer only builds safe DOM from an already-resolved value. Sort, filter, quick
// search and CSV export operate on a derived scalar via valueFormatter, never on the
// object. "Linked" is an orthogonal modifier: any value carrying an href is wrapped in
// an anchor by withLink, so content types stay additive instead of combinatorial.

// Only safe link schemes. hrefs are MediaWiki-generated server-side; this is defence in
// depth against a hand-built value.
const SAFE_HREF = /^(?:https?:|\/|\.\/|#)/;

/**
 * Wrap a node in an anchor when href is a safe scheme; otherwise return it unwrapped.
 *
 * @param {string} href
 * @param {Node} el
 * @return {Node}
 */
function anchorWrap( href, el ) {
	if ( typeof href !== 'string' || !SAFE_HREF.test( href ) ) {
		return el;
	}
	const a = document.createElement( 'a' );
	a.href = href;
	a.appendChild( el );
	return a;
}

/**
 * Decorate a pure cellRenderer so a value carrying an href is anchor-wrapped.
 *
 * @param {Function} render params -> Node
 * @return {Function}
 */
function withLink( render ) {
	return function ( params ) {
		const el = render( params );
		return ( params.value && params.value.href ) ?
			anchorWrap( params.value.href, el ) :
			el;
	};
}

function linkEl( params ) {
	const v = params.value;
	return document.createTextNode( v && v.text ? v.text : '' );
}

function imageEl( params ) {
	const v = params.value;
	const img = document.createElement( 'img' );
	if ( v ) {
		img.src = v.src;
		if ( v.width ) {
			img.width = v.width;
		}
		img.alt = v.alt || '';
	}
	return img;
}

function linkListEl( params ) {
	const span = document.createElement( 'span' );
	const links = ( params.value && params.value.links ) || [];
	links.forEach( ( link, i ) => {
		if ( i ) {
			span.appendChild( document.createTextNode( ', ' ) );
		}
		span.appendChild( anchorWrap( link.href, document.createTextNode( link.text || '' ) ) );
	} );
	return span;
}

function byText( a, b ) {
	return String( ( a && a.text ) || '' ).localeCompare( String( ( b && b.text ) || '' ) );
}

// Each entry is a native AG Grid columnType (cellRenderer + sort/filter scalar). The
// cellRenderers here are link-unaware; buildColumnTypes() wraps them with withLink.
const COLUMN_TYPES = {
	aggridLink: {
		cellRenderer: linkEl,
		valueFormatter: function ( p ) {
			return ( p.value && p.value.text ) || '';
		},
		comparator: byText
	},
	aggridImage: {
		cellRenderer: imageEl,
		valueFormatter: function ( p ) {
			return ( p.value && p.value.alt ) || '';
		}
	},
	aggridLinkList: {
		cellRenderer: linkListEl,
		valueFormatter: function ( p ) {
			return ( ( p.value && p.value.links ) || [] )
				.map( ( l ) => l.text ).join( ', ' );
		}
	}
};

/**
 * Assemble the AG Grid columnTypes map: built-ins (cellRenderers wrapped for the href
 * modifier) plus any added by extensions/skins via the registration hook. Built fresh
 * per mount so late-registered types apply to the next grid.
 *
 * @return {Object} columnTypes keyed by type name.
 */
function buildColumnTypes() {
	const types = {};
	Object.keys( COLUMN_TYPES ).forEach( ( name ) => {
		types[ name ] = Object.assign( {}, COLUMN_TYPES[ name ], {
			cellRenderer: withLink( COLUMN_TYPES[ name ].cellRenderer )
		} );
	} );
	// Handlers receive the mutable map and the withLink helper so their own renderers
	// can opt into the href modifier too.
	if ( typeof mw !== 'undefined' && mw.hook ) {
		mw.hook( 'ext.aggrid.registerColumnTypes' ).fire( types, withLink );
	}
	return types;
}

module.exports = {
	anchorWrap: anchorWrap,
	withLink: withLink,
	buildColumnTypes: buildColumnTypes,
	COLUMN_TYPES: COLUMN_TYPES
};
