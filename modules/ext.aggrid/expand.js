// Built-in opt-in expand control: a toolbar button that lifts the grid into a
// viewport-filling modal <dialog>, for wide grids squeezed into a narrow content column.
//
// A modal is not just a look: top-layer promotion ignores every ancestor stacking
// context, transform, filter and `contain`, and modality supplies focus containment and
// background inertness — so the overlay needs no knowledge of the skin around it, and
// never applies `inert` to anything it does not own.
//
// The grid's CHILDREN move into the dialog, not the .ext-aggrid placeholder, which stays
// in flow carrying whatever height the wiki gave it.

const { setClass } = require( './toolbar.js' );

const DIALOG_CLASS = 'ext-aggrid-expand';
const ROOT_CLASS = 'ext-aggrid-expand-root';
const HOST_CLASS = 'ext-aggrid-expand__host';

// Keys whose default action is to scroll, and the axis each one scrolls on. Space is
// deliberately absent: on a button its default is activation, not scrolling, and on a
// grid cell AG Grid claims it.
const SCROLL_KEYS = {
	PageUp: 'y', PageDown: 'y', Home: 'y', End: 'y',
	ArrowUp: 'y', ArrowDown: 'y', ArrowLeft: 'x', ArrowRight: 'x'
};
const BACKWARD_KEYS = /^(PageUp|ArrowUp|Home|ArrowLeft)$/;

// Geometry forced on every box the dialog builds: the replayed ancestors and the host
// they wrap. Each carries a real element's classes so the wiki's selectors keep
// matching, but it is scaffolding, not a box — and the chain is pinned to the dialog's
// height, so anything a wiki rule adds around it, or clamps it to, ends up outside the
// dialog's `overflow: hidden`. A 16px content margin was enough to clip away AG Grid's
// horizontal scrollbar; a max-height would cap the window-filling view instead.
//
// Layout only, deliberately: the inherited properties the chain exists to carry —
// colour, font, direction — are left to the wiki's rules.
//
// Inline !important is the only declaration that outranks a wiki rule of unknown
// specificity, which may itself be !important.
const FLAT_BOX = {
	height: '100%',
	'min-height': '0',
	'max-height': 'none',
	width: 'auto',
	'max-width': 'none',
	display: 'block',
	float: 'none',
	margin: '0',
	padding: '0',
	border: '0'
};

/**
 * Force FLAT_BOX on an element.
 *
 * @param {HTMLElement} el
 * @return {HTMLElement} el, for chaining.
 */
function flatten( el ) {
	Object.entries( FLAT_BOX ).forEach( ( [ property, value ] ) => {
		el.style.setProperty( property, value, 'important' );
	} );
	return el;
}

// Every open expansion, so a page re-render can collapse them all before the
// placeholders it is about to replace are torn out from under them.
const openStates = new Set();

let overlayRoot = null;

/**
 * Normalize the author's expand gridOption into a config, or null when disabled.
 *
 * Accepted: true; { label? }; and [], an empty Lua table arriving as a JSON array.
 * Anything else disables the button — LuaLibrary rejects bad shapes at parse time, but
 * parser-cache entries can predate that validation, so this stays defensive.
 *
 * @param {*} raw gridOptions.expand as parsed from the placeholder JSON.
 * @return {Object|null} { label: string|null } or null.
 */
function normalize( raw ) {
	if ( raw === true || ( Array.isArray( raw ) && raw.length === 0 ) ) {
		return { label: null };
	}
	if ( !raw || typeof raw !== 'object' || Array.isArray( raw ) ) {
		return null;
	}
	return { label: typeof raw.label === 'string' ? raw.label : null };
}

/**
 * Whether this browser can show a modal dialog. Where it cannot, no button is built —
 * better than one that does nothing.
 *
 * @return {boolean}
 */
function isSupported() {
	// The compat rule flags the reference even though this is the feature detection.
	/* eslint-disable-next-line compat/compat */
	return typeof HTMLDialogElement === 'function' && typeof HTMLDialogElement.prototype.showModal === 'function';
}

/**
 * The container every expand dialog is appended to.
 *
 * MediaWiki's shared overlay container when core provides one, `document.body`
 * otherwise. Two things here are load-bearing:
 *
 *  - The dialog is NEVER a direct child of either. OOUI's global WindowManager lives in
 *    the teleport target, and toggleIsolation() walks up to <body> setting `inert` on
 *    every sibling at each level; this wrapper absorbs that instead, and a modal dialog
 *    inside an inert subtree stays live. Do not "simplify" the wrapper away.
 *  - The reference is taken at click time. ready.js fires wikipage.content — which is
 *    what mounts grids — before calling teleportTarget.attach(), so at mount time the
 *    target is still detached and showModal() would throw.
 *
 * @return {HTMLElement}
 */
function getOverlayRoot() {
	if ( overlayRoot && overlayRoot.isConnected ) {
		return overlayRoot;
	}
	let target = null;
	try {
		// A ResourceLoader module, declared in extension.json's dependencies — there
		// is no file on disk for the resolver to find.
		/* eslint-disable-next-line n/no-missing-require */
		target = require( 'mediawiki.page.ready' ).teleportTarget;
	} catch ( e ) {
		// No core overlay container available; document.body is the fallback.
	}
	const parent = ( target && target.isConnected ) ? target : document.body;
	overlayRoot = setClass( document.createElement( 'div' ), ROOT_CLASS );
	parent.appendChild( overlayRoot );
	return overlayRoot;
}

/**
 * Read the grid's scroll offsets so they survive the move: reinserting a subtree resets
 * scrollTop/scrollLeft on every scrollable descendant.
 *
 * Found by state, not by class name — AG Grid's scroller moved between releases (36.x
 * uses `.ag-grid-viewport`, earlier ones `.ag-body-viewport`) and the bundle is bumped
 * by a bot whose pull requests do not run CI, so a selector would go quietly dead.
 *
 * @param {HTMLElement} scope
 * @return {Map} Element -> { top, left }, consumed by restoreScroll().
 */
function captureScroll( scope ) {
	const scroll = new Map();
	Array.prototype.forEach.call( scope.querySelectorAll( '*' ), ( el ) => {
		if ( el.scrollTop || el.scrollLeft ) {
			scroll.set( el, { top: el.scrollTop, left: el.scrollLeft } );
		}
	} );
	return scroll;
}

/**
 * Reapply a captureScroll() snapshot.
 *
 * @param {Map} scroll
 */
function restoreScroll( scroll ) {
	scroll.forEach( ( pos, el ) => {
		el.scrollTop = pos.top;
		el.scrollLeft = pos.left;
	} );
}

/**
 * Point the button at its opposite action. The button travels into the dialog with the
 * rest of the chrome, so it is the exit as well as the entrance; name and icon change
 * together rather than the state riding on aria-pressed.
 *
 * @param {Object} state
 * @param {boolean} expanded
 */
function setButtonState( state, expanded ) {
	const label = expanded ?
		mw.msg( 'aggrid-expand-collapse-label' ) :
		( state.config.label || mw.msg( 'aggrid-expand-label' ) );
	state.button.setAttribute( 'aria-label', label );
	state.button.setAttribute( 'title', label );
	// Only advertise the dialog while pressing the button would actually open one;
	// expanded, it collapses in place.
	if ( expanded ) {
		state.button.removeAttribute( 'aria-haspopup' );
	} else {
		state.button.setAttribute( 'aria-haspopup', 'dialog' );
	}
	setClass( state.icon, expanded ? 'ag-icon ag-icon-minimize' : 'ag-icon ag-icon-maximize' );
}

/**
 * Escape closes an open filter popup first, and the view only once nothing inside
 * still owns the key.
 *
 * The popup is closed from here rather than left to AG Grid: its own filters handle
 * Escape, but the extension's set filter (setFilter.js) has no key handling, so
 * deferring would swallow the key with nothing to show for it and strand the reader
 * in a view whose only keyboard exit no longer works. The key is consumed only if a
 * popup actually went away, so a future filter that closes itself cannot dead-end
 * either.
 *
 * Not the `cancel` event: preventDefault() on it is budgeted by the browser (twice on
 * the first expand, once after), so the view would collapse under a reader who pressed
 * Escape a few times.
 *
 * @param {Object} state
 * @return {Function} keydown handler; capture phase, so it runs before AG Grid's own.
 */
function makeEscapeHandler( state ) {
	return ( e ) => {
		if ( e.key !== 'Escape' || !state.host ||
			!state.host.querySelector( '.ag-popup-child' ) ) {
			return;
		}
		if ( state.api && typeof state.api.hidePopupMenu === 'function' ) {
			state.api.hidePopupMenu();
		}
		if ( !state.host.querySelector( '.ag-popup-child' ) ) {
			e.preventDefault();
		}
	};
}

/**
 * Rebuild the placeholder's ancestor chain inside the dialog, as empty divs carrying
 * only each ancestor's classes.
 *
 * A wiki styles its grids through descendant selectors rooted in the content wrapper —
 * TemplateStyles is *always* force-prefixed with `.mw-parser-output`, and authors add
 * their own wrapper below it (`.mw-parser-output .my-table .ag-cell { … }`). The dialog
 * has to live outside page content to clear the skin's own chrome, which would
 * otherwise drop every one of those rules the moment a reader expands. Replaying the
 * chain keeps them matching, and carries nothing else across: no ids, no content, no
 * inline styles, so nothing can duplicate a real element or its behaviour.
 *
 * @param {HTMLElement} el The .ext-aggrid placeholder, still in the page.
 * @param {HTMLElement} host The node the grid's children are about to move into.
 * @return {HTMLElement} The outermost node to put in the dialog.
 */
function replayAncestors( el, host ) {
	const classes = [];
	// The placeholder's own classes first, then upwards. Bounded by mw-parser-output,
	// beyond which selectors are the skin's business, not the wiki's.
	for ( let node = el; node && node !== document.body; node = node.parentElement ) {
		if ( node.className && typeof node.className === 'string' ) {
			classes.push( node.className );
		}
		if ( node.classList.contains( 'mw-parser-output' ) ) {
			break;
		}
	}
	let inner = host;
	classes.forEach( ( className ) => {
		const wrapper = flatten( setClass( document.createElement( 'div' ), className ) );
		wrapper.appendChild( inner );
		inner = wrapper;
	} );
	return inner;
}

/**
 * Whether this element can absorb the wheel: it scrolls on the wheel's axis AND has
 * room left in that direction. The second half is the point — a scroller at its limit
 * is exactly where the browser hands the rest of the delta to the next one up.
 *
 * @param {HTMLElement} el
 * @param {WheelEvent} e
 * @return {boolean}
 */
function absorbsWheel( el, e ) {
	// A pixel of slack: scroll offsets are fractional under browser zoom and on
	// hidpi displays, so a scroller parked at its end rarely reports an exact match.
	const slack = 1;
	const style = window.getComputedStyle( el );
	if ( e.deltaY ) {
		const room = el.scrollHeight - el.clientHeight;
		if ( room > slack && ( /auto|scroll/ ).test( style.overflowY ) &&
			( e.deltaY < 0 ? el.scrollTop > slack : el.scrollTop < room - slack ) ) {
			return true;
		}
	}
	if ( e.deltaX ) {
		const room = el.scrollWidth - el.clientWidth;
		if ( room > slack && ( /auto|scroll/ ).test( style.overflowX ) &&
			( e.deltaX < 0 ? el.scrollLeft > slack : el.scrollLeft < room - slack ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Whether a key press belongs to the element rather than to scrolling: typing, and
 * caret movement in the quick search box.
 *
 * @param {HTMLElement} el
 * @return {boolean}
 */
function isEditable( el ) {
	const tag = el.tagName;
	return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || !!el.isContentEditable;
}

/**
 * Whether anything between `target` and the dialog can take this scroll. Shared by both
 * guards below.
 *
 * @param {HTMLElement} dialog
 * @param {EventTarget} target
 * @param {Object} delta { deltaX, deltaY }; a real WheelEvent, or a synthetic pair of
 *   unit deltas standing in for a scroll key's direction.
 * @return {boolean}
 */
function nothingCanScroll( dialog, target, delta ) {
	for ( let node = target; node && node !== dialog; node = node.parentNode ) {
		if ( node.nodeType === Node.ELEMENT_NODE && absorbsWheel( node, delta ) ) {
			return false;
		}
	}
	return true;
}

/**
 * Keep a wheel nothing inside the dialog can act on from scrolling the page behind it.
 *
 * `overscroll-behavior: contain` (see ext.aggrid.less) is the primary fix, but Gecko
 * does not apply it to a scroll container with no scrollable overflow of its own, which
 * a dialog sized to the viewport never has. The wheel is only ever dropped, never
 * scrolled by hand, so where the CSS already worked this changes nothing.
 *
 * @param {Object} state
 * @return {Function} wheel handler; must be registered with `{ passive: false }`,
 *   or preventDefault() is ignored and the page scrolls anyway.
 */
function makeWheelGuard( state ) {
	return ( e ) => {
		const dialog = state.dialog;
		// ctrl+wheel is the browser's zoom gesture, and how a trackpad pinch arrives.
		// Nothing here scrolls in response, so the walk below would cancel it and take
		// zoom away from the reader for as long as the grid is expanded (WCAG 1.4.4).
		if ( e.ctrlKey ) {
			return;
		}
		if ( !dialog || !dialog.contains( e.target ) ) {
			return;
		}
		if ( nothingCanScroll( dialog, e.target, e ) ) {
			e.preventDefault();
		}
	};
}

/**
 * The same containment for the keyboard, which neither half of the wheel fix covers:
 * `overscroll-behavior` governs wheel and touch only, and a wheel listener never sees a
 * key. Focus lands on the collapse button, which is in no scroll container, so a single
 * PageDown there scrolled the page behind the modal to its end in Firefox and WebKit.
 *
 * Editable targets are skipped so arrows and Home/End still move the caret in the quick
 * search box, and modified presses so a browser or AT shortcut is never swallowed.
 *
 * @param {Object} state
 * @return {Function} keydown handler.
 */
function makeScrollKeyGuard( state ) {
	return ( e ) => {
		const dialog = state.dialog;
		const axis = SCROLL_KEYS[ e.key ];
		if ( !dialog || !axis || e.ctrlKey || e.metaKey || e.altKey ) {
			return;
		}
		const target = e.target;
		if ( !dialog.contains( target ) || isEditable( target ) ) {
			return;
		}
		// Unit deltas: only the direction matters to absorbsWheel().
		const back = BACKWARD_KEYS.test( e.key );
		const delta = axis === 'y' ?
			{ deltaY: back ? -1 : 1, deltaX: 0 } :
			{ deltaX: back ? -1 : 1, deltaY: 0 };
		if ( nothingCanScroll( dialog, target, delta ) ) {
			e.preventDefault();
		}
	};
}

/**
 * Move the grid into a modal dialog.
 *
 * @param {Object} state
 */
function open( state ) {
	if ( state.dialog || !isSupported() ) {
		return;
	}
	const root = getOverlayRoot();
	const dialog = setClass( document.createElement( 'dialog' ), DIALOG_CLASS );
	dialog.setAttribute( 'aria-label', mw.msg( 'aggrid-expand-dialog-label' ) );
	// The host is a child of the innermost replayed wrapper, so a wiki's structural
	// selector (`.mw-parser-output .ext-aggrid > div`) reaches it exactly as it reaches
	// them; it gets the same flattening. See FLAT_BOX.
	const host = flatten( setClass( document.createElement( 'div' ), HOST_CLASS ) );

	// Read before the move: a page whose content direction differs from the document's
	// would otherwise flip, the dialog being outside the content wrapper.
	host.setAttribute( 'dir', window.getComputedStyle( state.el ).direction );

	const snapshot = captureScroll( state.el );
	dialog.appendChild( replayAncestors( state.el, host ) );
	root.appendChild( dialog );
	while ( state.el.firstChild ) {
		host.appendChild( state.el.firstChild );
	}

	try {
		dialog.showModal();
	} catch ( e ) {
		// Put the grid back rather than stranding it in a dialog that never opened.
		while ( host.firstChild ) {
			state.el.appendChild( host.firstChild );
		}
		dialog.remove();
		mw.log.error( '[ext.aggrid] could not open the expanded view', e );
		restoreScroll( snapshot );
		return;
	}

	state.dialog = dialog;
	state.host = host;
	openStates.add( state );
	dialog.addEventListener( 'keydown', state.onEscape, true );
	// Not passive: preventDefault() is these handlers' whole job, and a passive
	// listener has it ignored.
	dialog.addEventListener( 'wheel', state.onWheel, { passive: false } );
	dialog.addEventListener( 'keydown', state.onScrollKey, { passive: false } );
	// Guarded on identity: `close` is dispatched as a task, so a queued event from a
	// previous dialog must not tear down the one that replaced it.
	dialog.addEventListener( 'close', () => {
		if ( state.dialog === dialog ) {
			close( state );
		}
	} );
	restoreScroll( snapshot );
	// Tracked for the whole session rather than read back in close(): by the time the
	// `close` event fires the dialog is display:none and every descendant reports
	// scrollTop 0. Capture phase because scroll events do not bubble.
	state.scroll = snapshot;
	dialog.addEventListener( 'scroll', state.onScroll, true );
	setButtonState( state, true );
	state.button.focus();
}

/**
 * Move the grid back into its placeholder and discard the dialog.
 *
 * @param {Object} state
 */
function close( state ) {
	const { dialog, host } = state;
	if ( !dialog ) {
		return;
	}
	dialog.removeEventListener( 'keydown', state.onEscape, true );
	dialog.removeEventListener( 'wheel', state.onWheel, { passive: false } );
	dialog.removeEventListener( 'keydown', state.onScrollKey, { passive: false } );
	dialog.removeEventListener( 'scroll', state.onScroll, true );
	// Maintained live by onScroll — see the note in open().
	const snapshot = state.scroll || captureScroll( host );
	const reconnected = state.el.isConnected;
	if ( reconnected ) {
		while ( host.firstChild ) {
			state.el.appendChild( host.firstChild );
		}
	}
	if ( dialog.open ) {
		dialog.close();
	}
	// Our own dialog only; the shared container's other tenants stay.
	dialog.remove();
	state.dialog = null;
	state.host = null;
	state.scroll = null;
	openStates.delete( state );
	if ( reconnected ) {
		restoreScroll( snapshot );
		setButtonState( state, false );
		// The dialog cannot do this for us: at showModal() the button had already moved
		// into the unshown dialog and blurred, so the UA remembers <body>. Without it
		// every collapse drops a keyboard or screen-reader user at the top of the page.
		state.button.focus();
	} else if ( state.api && typeof state.api.destroy === 'function' ) {
		// The page re-rendered and a fresh placeholder was mounted in ours: drop this
		// grid rather than leak a live GridApi into a detached tree.
		state.api.destroy();
		state.api = null;
	}
}

/**
 * Collapse open expansions. Called from init.js before a re-render mounts the
 * replacement content, so an overlay is never left floating over a page whose
 * placeholder it no longer belongs to.
 *
 * @param {HTMLElement} [root] The content being re-rendered. Only expansions it
 *   touches collapse — placeholder already detached, or inside `root` — so a re-render
 *   elsewhere on the page leaves an unrelated expanded grid alone. Omit for all.
 */
function closeAll( root ) {
	Array.from( openStates ).forEach( ( state ) => {
		if ( !root || !state.el.isConnected || root.contains( state.el ) ) {
			close( state );
		}
	} );
}

/**
 * Build the expand toolbar item, or null where modal dialogs are unavailable.
 *
 * @param {HTMLElement} el The .ext-aggrid container (post-createGrid).
 * @param {Object} api The AG Grid GridApi.
 * @param {Object} config Normalized config from normalize().
 * @return {HTMLElement|null} The toolbar item.
 */
function buildItem( el, api, config ) {
	if ( !isSupported() ) {
		return null;
	}
	const item = setClass( document.createElement( 'div' ),
		'ag-toolbar-item ag-toolbar-button-wrapper ext-aggrid-toolbar__item' );
	const button = setClass( document.createElement( 'button' ),
		'ag-toolbar-button ext-aggrid-toolbar__button' );
	button.type = 'button';
	// aria-haspopup is set by setButtonState below; the button's state is carried by
	// its own label, which changes with it, rather than by aria-pressed.
	const icon = document.createElement( 'span' );
	icon.setAttribute( 'aria-hidden', 'true' );
	button.appendChild( icon );

	const state = { el, api, config, button, icon, dialog: null, host: null, scroll: null };
	state.onEscape = makeEscapeHandler( state );
	state.onWheel = makeWheelGuard( state );
	state.onScrollKey = makeScrollKeyGuard( state );
	state.onScroll = ( e ) => {
		if ( state.scroll ) {
			state.scroll.set( e.target, { top: e.target.scrollTop, left: e.target.scrollLeft } );
		}
	};
	setButtonState( state, false );

	button.addEventListener( 'click', () => {
		if ( state.dialog ) {
			close( state );
		} else {
			open( state );
		}
	} );

	item.appendChild( button );
	return item;
}

module.exports = { normalize, isSupported, buildItem, closeAll };
