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
const SAFE_HREF = /^(?:https?:|\/(?!\/)|\.\/|#)/;

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

// Coerce an absent (null/undefined) value to '', preserving a literal '' or 0 rather
// than treating every falsy value as absent.
function orEmpty( x ) {
	return ( x === undefined || x === null ) ? '' : x;
}

function linkEl( params ) {
	const v = params.value;
	return document.createTextNode( orEmpty( v && v.text ) );
}

function imageEl( params ) {
	const v = params.value;
	const img = document.createElement( 'img' );
	if ( v ) {
		if ( v.src ) {
			img.src = v.src;
		}
		if ( v.width ) {
			img.width = v.width;
		}
		img.alt = v.alt || '';
	}
	return img;
}

// A link-list value carries per-item links and must NOT carry a top-level href: each
// entry is anchored individually here, so a top-level href (which withLink would wrap
// the whole span in) would nest anchors. The Lua linkList helper never emits one.
function linkListEl( params ) {
	const span = document.createElement( 'span' );
	const links = ( params.value && params.value.links ) || [];
	let first = true;
	links.forEach( ( link ) => {
		if ( !link ) {
			return;
		}
		if ( !first ) {
			span.appendChild( document.createTextNode( ', ' ) );
		}
		span.appendChild( anchorWrap( link.href, document.createTextNode( orEmpty( link.text ) ) ) );
		first = false;
	} );
	return span;
}

function linkText( v ) {
	return orEmpty( v && v.text );
}

function imageAlt( v ) {
	return ( v && v.alt ) || '';
}

function listText( v ) {
	return ( ( v && v.links ) || [] )
		.filter( ( l ) => l ).map( ( l ) => orEmpty( l.text ) ).join( ', ' );
}

// Variant tokens become a CSS modifier class, so they must be a safe slug. Anything
// outside [a-z0-9-] is rejected and the badge falls back to the 'neutral' variant —
// defence in depth against a hand-built value injecting a class name.
const BADGE_VARIANT = /^[a-z0-9-]+$/;

// Derive the badge's display label: object form carries `.label`, scalar form is the
// value itself. Drives the rendered text AND the sort/filter/export scalar.
function badgeLabel( v ) {
	return ( v && typeof v === 'object' ) ? orEmpty( v.label ) : orEmpty( v );
}

// Resolve the variant slug. Object form carries `.variant`; scalar form looks the label
// up in params.variantMap (AG Grid merges cellRendererParams onto the renderer params).
// Unknown/unsafe → params.defaultVariant if safe, else 'neutral'.
function badgeVariant( params ) {
	const v = params.value;
	let variant;
	if ( v && typeof v === 'object' ) {
		variant = v.variant;
	} else {
		const map = params.variantMap || {};
		variant = map[ badgeLabel( v ) ];
	}
	if ( typeof variant === 'string' && BADGE_VARIANT.test( variant ) ) {
		return variant;
	}
	if ( typeof params.defaultVariant === 'string' && BADGE_VARIANT.test( params.defaultVariant ) ) {
		return params.defaultVariant;
	}
	return 'neutral';
}

function badgeEl( params ) {
	const span = document.createElement( 'span' );
	// Possible classes constructed below:
	// * ext-aggrid__badge--neutral
	// * ext-aggrid__badge--success
	// * ext-aggrid__badge--warning
	// * ext-aggrid__badge--error
	// * ext-aggrid__badge--notice
	// * ext-aggrid__badge--info (and any safe custom variant from cellRendererParams)
	span.className = 'ext-aggrid__badge ext-aggrid__badge--' + badgeVariant( params );
	span.textContent = badgeLabel( params.value );
	return span;
}

// Build a locale-aware comparator that sorts on a value's derived scalar text.
function compareBy( extract ) {
	return ( a, b ) => String( extract( a ) ).localeCompare( String( extract( b ) ) );
}

// Each entry is a native AG Grid columnType (cellRenderer + sort/filter scalar). The
// cellRenderers here are link-unaware; buildColumnTypes() wraps them with withLink.
// valueFormatter drives display/filter/quick-search/export; comparator drives sort —
// both operate on the derived scalar, never the raw object.
const COLUMN_TYPES = {
	aggridLink: {
		cellRenderer: linkEl,
		valueFormatter: ( p ) => linkText( p.value ),
		comparator: compareBy( linkText )
	},
	aggridImage: {
		cellRenderer: imageEl,
		valueFormatter: ( p ) => imageAlt( p.value ),
		comparator: compareBy( imageAlt )
	},
	aggridLinkList: {
		cellRenderer: linkListEl,
		valueFormatter: ( p ) => listText( p.value ),
		comparator: compareBy( listText )
	},
	aggridBadge: {
		cellRenderer: badgeEl,
		valueFormatter: ( p ) => badgeLabel( p.value ),
		comparator: compareBy( badgeLabel )
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
	// @internal — exported for tests only. Consume the built-in types via
	// buildColumnTypes(), which applies the withLink href modifier.
	COLUMN_TYPES: COLUMN_TYPES
};
