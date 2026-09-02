const expand = require( '../../../modules/ext.aggrid/expand.js' );

// jsdom (30.x) ships HTMLDialogElement with a reflected `open` property but neither
// showModal() nor close(), so isSupported() is false there and every open/close test
// has to install the two methods first. Modelled on the spec: showModal() sets the
// open attribute, close() clears it and fires `close`. The top layer itself is not
// simulated — nothing here asserts modality, which jsdom cannot answer.
function stubDialog() {
	HTMLDialogElement.prototype.showModal = function () {
		this.setAttribute( 'open', '' );
	};
	HTMLDialogElement.prototype.close = function () {
		this.removeAttribute( 'open' );
		this.dispatchEvent( new window.Event( 'close' ) );
	};
}

function unstubDialog() {
	delete HTMLDialogElement.prototype.showModal;
	delete HTMLDialogElement.prototype.close;
}

// The DOM agGrid.createGrid leaves behind, attached to the document so the
// placeholder reads as connected (close() destroys the grid otherwise).
function makeMounted() {
	const el = document.createElement( 'div' );
	el.className = 'ext-aggrid';
	const root = document.createElement( 'div' );
	root.className = 'ag-root-wrapper';
	el.appendChild( root );
	document.body.appendChild( el );
	return { el, root };
}

// The same, wrapped in the ancestors a wiki puts a grid in: the content wrapper
// TemplateStyles is force-prefixed with, and an author's own wrapper below it.
function makeNested() {
	const { el, root } = makeMounted();
	const content = document.createElement( 'div' );
	content.className = 'mw-content-ltr mw-parser-output';
	const wrapper = document.createElement( 'div' );
	wrapper.className = 't-datagrid';
	content.appendChild( wrapper );
	wrapper.appendChild( el );
	document.body.appendChild( content );
	return { el, root };
}

// Every declaration flatten() forces, as the DOM reports it back.
const FLAT_BOX = {
	height: '100%',
	'min-height': '0px',
	'max-height': 'none',
	width: 'auto',
	'max-width': 'none',
	display: 'block',
	float: 'none',
	margin: '0px',
	padding: '0px',
	border: '0px'
};

function expectFlattened( el ) {
	Object.entries( FLAT_BOX ).forEach( ( [ property, value ] ) => {
		expect( [ property, el.style.getPropertyValue( property ) ] )
			.toEqual( [ property, value ] );
		expect( [ property, el.style.getPropertyPriority( property ) ] )
			.toEqual( [ property, 'important' ] );
	} );
}

function makeApi() {
	return { destroy: vi.fn() };
}

function openVia( item ) {
	item.querySelector( 'button' ).dispatchEvent(
		new window.MouseEvent( 'click', { bubbles: true } )
	);
}

afterEach( () => {
	expand.closeAll();
	document.body.innerHTML = '';
	unstubDialog();
} );

describe( 'normalize', () => {
	it( 'enables with defaults for true', () => {
		expect( expand.normalize( true ) ).toEqual( { label: null } );
	} );

	it( 'enables with defaults for [] (an empty Lua table arrives as a JSON array)', () => {
		expect( expand.normalize( [] ) ).toEqual( { label: null } );
	} );

	it( 'disables for false, null, undefined', () => {
		expect( expand.normalize( false ) ).toBeNull();
		expect( expand.normalize( null ) ).toBeNull();
		expect( expand.normalize( undefined ) ).toBeNull();
	} );

	it( 'disables for garbage shapes (stale parser cache predates PHP validation)', () => {
		expect( expand.normalize( 'yes' ) ).toBeNull();
		expect( expand.normalize( 1 ) ).toBeNull();
		expect( expand.normalize( [ 'x' ] ) ).toBeNull();
	} );

	it( 'reads a string label and ignores any other type', () => {
		expect( expand.normalize( { label: 'Open wide' } ).label ).toBe( 'Open wide' );
		expect( expand.normalize( { label: 5 } ).label ).toBeNull();
	} );
} );

describe( 'isSupported', () => {
	it( 'is false without showModal', () => {
		expect( expand.isSupported() ).toBe( false );
	} );

	it( 'is true once showModal exists', () => {
		stubDialog();
		expect( expand.isSupported() ).toBe( true );
	} );
} );

describe( 'buildItem', () => {
	it( 'renders no button where modal dialogs are unavailable', () => {
		const { el } = makeMounted();
		expect( expand.buildItem( el, makeApi(), { label: null } ) ).toBeNull();
	} );

	it( 'builds an AG-Grid-shaped toolbar button', () => {
		stubDialog();
		const { el } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		expect( item.classList.contains( 'ag-toolbar-button-wrapper' ) ).toBe( true );
		const button = item.querySelector( 'button.ag-toolbar-button' );
		expect( button.type ).toBe( 'button' );
		expect( button.getAttribute( 'aria-haspopup' ) ).toBe( 'dialog' );
		// setup.js's mw.msg echoes the key.
		expect( button.getAttribute( 'aria-label' ) ).toBe( 'aggrid-expand-label' );
		expect( item.querySelector( '.ag-icon-maximize' ) ).not.toBeNull();
		// No aria-pressed: the label carries the state, and the control opens a
		// dialog rather than toggling something in place.
		expect( button.hasAttribute( 'aria-pressed' ) ).toBe( false );
	} );

	it( 'prefers the author label, set as an attribute (no HTML)', () => {
		stubDialog();
		const { el } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: '<b>Wide</b>' } );
		const button = item.querySelector( 'button' );
		expect( button.getAttribute( 'aria-label' ) ).toBe( '<b>Wide</b>' );
		expect( button.querySelector( 'b' ) ).toBeNull();
	} );
} );

describe( 'expanding and collapsing', () => {
	beforeEach( stubDialog );

	it( 'moves the grid into a dialog outside the placeholder', () => {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );

		openVia( item );

		const dialog = document.querySelector( 'dialog.ext-aggrid-expand' );
		expect( dialog.open ).toBe( true );
		expect( dialog.getAttribute( 'aria-label' ) ).toBe( 'aggrid-expand-dialog-label' );
		// The grid root — and the toolbar inside it — travelled with the move.
		const host = dialog.querySelector( '.ext-aggrid-expand__host' );
		expect( host.contains( root ) ).toBe( true );
		expect( el.contains( root ) ).toBe( false );
		// The placeholder itself stays in the page, so a wiki's height rule on it
		// keeps applying and the page does not jump.
		expect( el.isConnected ).toBe( true );
	} );

	it( 'never parents the dialog directly to the overlay container', () => {
		// OOUI's WindowManager inerts every sibling on its walk to <body>; the
		// wrapper absorbs that instead of the dialog.
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		openVia( item );

		const dialog = document.querySelector( 'dialog.ext-aggrid-expand' );
		expect( dialog.parentElement.classList.contains( 'ext-aggrid-expand-root' ) )
			.toBe( true );
		expect( dialog.parentElement.parentElement ).toBe( document.body );
	} );

	it( 'replays the placeholder\'s ancestor classes inside the dialog', () => {
		// A wiki's rules are scoped through the content wrapper — TemplateStyles is
		// always force-prefixed with .mw-parser-output — so leaving page content would
		// otherwise drop all of them.
		const { el, root } = makeNested();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		openVia( item );

		const chain = [];
		let node = document.querySelector( '.ext-aggrid-expand__host' );
		while ( node && node.tagName !== 'DIALOG' ) {
			chain.unshift( node.className );
			node = node.parentElement;
		}
		expect( chain ).toEqual( [
			'mw-content-ltr mw-parser-output',
			't-datagrid',
			'ext-aggrid',
			'ext-aggrid-expand__host'
		] );
		// Classes only: nothing that could duplicate a real element's identity.
		const replayed = document.querySelector( 'dialog .t-datagrid' );
		expect( replayed.id ).toBe( '' );
	} );

	it( 'flattens every box it builds, wrappers and host alike', () => {
		// jsdom does no layout, so this can only check the declarations. What they buy:
		// a margin on any layer moves the whole 100%-height stack down and pushes its
		// bottom edge — where AG Grid parks the horizontal scrollbar — out under the
		// dialog's `overflow: hidden`; a max-height caps the window-filling view.
		const { el, root } = makeNested();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		openVia( item );

		[
			'mw-parser-output',
			't-datagrid',
			'ext-aggrid',
			'ext-aggrid-expand__host'
		].forEach( ( className ) => {
			expectFlattened( document.querySelector( `dialog .${ className }` ) );
		} );
	} );

	it( 'flattens the host even with no ancestors to replay', () => {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		openVia( item );

		expectFlattened( document.querySelector( '.ext-aggrid-expand__host' ) );
	} );

	it( 'swaps the button to its collapse state and back', () => {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		const button = item.querySelector( 'button' );

		openVia( item );
		expect( button.getAttribute( 'aria-label' ) ).toBe( 'aggrid-expand-collapse-label' );
		expect( item.querySelector( '.ag-icon-minimize' ) ).not.toBeNull();

		openVia( item );
		expect( button.getAttribute( 'aria-label' ) ).toBe( 'aggrid-expand-label' );
		expect( item.querySelector( '.ag-icon-maximize' ) ).not.toBeNull();
	} );

	it( 'returns the grid to its placeholder and discards the dialog on collapse', () => {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );

		openVia( item );
		openVia( item );

		expect( el.contains( root ) ).toBe( true );
		expect( document.querySelector( 'dialog.ext-aggrid-expand' ) ).toBeNull();
		// The shared overlay wrapper is reused rather than churned.
		expect( document.querySelectorAll( '.ext-aggrid-expand-root' ) ).toHaveLength( 1 );
	} );

	it( 'collapses when the dialog closes natively (Escape, native close)', () => {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		openVia( item );

		document.querySelector( 'dialog.ext-aggrid-expand' ).close();

		expect( el.contains( root ) ).toBe( true );
		expect( item.querySelector( '.ag-icon-maximize' ) ).not.toBeNull();
	} );

	it( 'closeAll() collapses every open grid', () => {
		const a = makeMounted();
		const b = makeMounted();
		const itemA = expand.buildItem( a.el, makeApi(), { label: null } );
		const itemB = expand.buildItem( b.el, makeApi(), { label: null } );
		a.root.appendChild( itemA );
		b.root.appendChild( itemB );
		openVia( itemA );
		openVia( itemB );
		expect( document.querySelectorAll( 'dialog.ext-aggrid-expand' ) ).toHaveLength( 2 );

		expand.closeAll();

		expect( document.querySelectorAll( 'dialog.ext-aggrid-expand' ) ).toHaveLength( 0 );
		expect( a.el.contains( a.root ) ).toBe( true );
		expect( b.el.contains( b.root ) ).toBe( true );
	} );

	it( 'returns focus to the button on collapse', () => {
		// The dialog cannot do this for us: at showModal() time the button had already
		// been moved into the not-yet-shown dialog, so the element the UA remembers as
		// previously focused is <body>.
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		const button = item.querySelector( 'button' );

		openVia( item );
		expect( document.activeElement ).toBe( button );
		document.querySelector( 'dialog.ext-aggrid-expand' ).close();
		expect( document.activeElement ).toBe( button );
	} );

	it( 'advertises the dialog only while pressing the button would open one', () => {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		const button = item.querySelector( 'button' );

		expect( button.getAttribute( 'aria-haspopup' ) ).toBe( 'dialog' );
		openVia( item );
		expect( button.hasAttribute( 'aria-haspopup' ) ).toBe( false );
		openVia( item );
		expect( button.getAttribute( 'aria-haspopup' ) ).toBe( 'dialog' );
	} );

	it( 'closeAll( root ) leaves grids outside the re-rendered content expanded', () => {
		// wikipage.content fires for any re-render on the page — another Tabber panel,
		// a DiscussionTools widget. Only the content actually being replaced should
		// collapse; yanking a reader out of an unrelated expanded grid is a bug.
		const inside = makeMounted();
		const outside = makeMounted();
		const itemIn = expand.buildItem( inside.el, makeApi(), { label: null } );
		const itemOut = expand.buildItem( outside.el, makeApi(), { label: null } );
		inside.root.appendChild( itemIn );
		outside.root.appendChild( itemOut );
		openVia( itemIn );
		openVia( itemOut );

		// A re-render scoped to the first grid's placeholder only.
		expand.closeAll( inside.el );

		expect( inside.el.contains( inside.root ) ).toBe( true );
		expect( document.querySelectorAll( 'dialog.ext-aggrid-expand' ) ).toHaveLength( 1 );
	} );

	it( 'closeAll( root ) still collapses a grid whose placeholder was replaced', () => {
		const { el, root } = makeMounted();
		const api = makeApi();
		const item = expand.buildItem( el, api, { label: null } );
		root.appendChild( item );
		openVia( item );

		// The re-render detached this placeholder and rendered fresh content elsewhere.
		el.remove();
		const fresh = document.createElement( 'div' );
		document.body.appendChild( fresh );
		expand.closeAll( fresh );

		expect( document.querySelector( 'dialog.ext-aggrid-expand' ) ).toBeNull();
		expect( api.destroy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'destroys the grid when the page re-rendered underneath it', () => {
		const { el, root } = makeMounted();
		const api = makeApi();
		const item = expand.buildItem( el, api, { label: null } );
		root.appendChild( item );
		openVia( item );

		// What wikipage.content does: the placeholder this grid came from is replaced.
		el.remove();
		expand.closeAll();

		expect( api.destroy ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( 'dialog.ext-aggrid-expand' ) ).toBeNull();
	} );

	it( 'puts the grid back if showModal throws', () => {
		const { el, root } = makeMounted();
		HTMLDialogElement.prototype.showModal = function () {
			throw new Error( 'not allowed' );
		};
		const item = expand.buildItem( el, makeApi(), { label: null } );
		root.appendChild( item );
		const error = vi.spyOn( global.mw.log, 'error' );

		openVia( item );

		expect( el.contains( root ) ).toBe( true );
		expect( document.querySelector( 'dialog.ext-aggrid-expand' ) ).toBeNull();
		expect( error ).toHaveBeenCalled();
		error.mockRestore();
	} );
} );

describe( 'Escape handling', () => {
	beforeEach( stubDialog );

	function expandedWith( extra, api ) {
		const { el, root } = makeMounted();
		const item = expand.buildItem( el, api || makeApi(), { label: null } );
		root.appendChild( item );
		openVia( item );
		const dialog = document.querySelector( 'dialog.ext-aggrid-expand' );
		if ( extra ) {
			dialog.querySelector( '.ext-aggrid-expand__host' ).appendChild( extra );
		}
		return dialog;
	}

	function pressEscape( dialog ) {
		const e = new window.KeyboardEvent( 'keydown', {
			key: 'Escape', bubbles: true, cancelable: true
		} );
		dialog.dispatchEvent( e );
		return e;
	}

	it( 'lets Escape reach the dialog when nothing nested owns it', () => {
		const dialog = expandedWith( null );
		expect( pressEscape( dialog ).defaultPrevented ).toBe( false );
	} );

	it( 'closes an open popup and consumes that Escape', () => {
		// A filter or column menu renders into the grid root, inside the moved
		// subtree; that Escape belongs to the popup, not to the expanded view.
		const popup = document.createElement( 'div' );
		popup.className = 'ag-popup-child';
		const api = makeApi();
		api.hidePopupMenu = vi.fn( () => popup.remove() );
		const dialog = expandedWith( popup, api );

		expect( pressEscape( dialog ).defaultPrevented ).toBe( true );
		expect( api.hidePopupMenu ).toHaveBeenCalled();
	} );

	it( 'lets Escape through when the popup refuses to close', () => {
		// The extension's own set filter has no key handling, so if nothing actually
		// closed we must not swallow the key — that would strand the reader in a view
		// whose only keyboard exit no longer works.
		const popup = document.createElement( 'div' );
		popup.className = 'ag-popup-child';
		const api = makeApi();
		api.hidePopupMenu = vi.fn();
		const dialog = expandedWith( popup, api );

		expect( pressEscape( dialog ).defaultPrevented ).toBe( false );
	} );

	it( 'drops a scroll key nothing inside can act on', () => {
		// The page behind a modal still scrolls on PageDown otherwise: focus lands on
		// the collapse button, which sits in no scroll container.
		const dialog = expandedWith( null );
		const e = new window.KeyboardEvent( 'keydown', {
			key: 'PageDown', bubbles: true, cancelable: true
		} );
		dialog.dispatchEvent( e );
		expect( e.defaultPrevented ).toBe( true );
	} );

	it( 'leaves scroll keys alone in the quick-search box', () => {
		const input = document.createElement( 'input' );
		expandedWith( input );
		input.focus();
		const e = new window.KeyboardEvent( 'keydown', {
			key: 'Home', bubbles: true, cancelable: true
		} );
		input.dispatchEvent( e );
		// Home must still move the caret rather than being eaten as a scroll key.
		expect( e.defaultPrevented ).toBe( false );
	} );

	it( 'never cancels ctrl+wheel, which is the browser zoom gesture', () => {
		const dialog = expandedWith( null );
		const e = new window.WheelEvent( 'wheel', {
			deltaY: 100, ctrlKey: true, bubbles: true, cancelable: true
		} );
		dialog.dispatchEvent( e );
		expect( e.defaultPrevented ).toBe( false );
	} );

	it( 'ignores other keys', () => {
		const popup = document.createElement( 'div' );
		popup.className = 'ag-popup-child';
		const dialog = expandedWith( popup );
		const e = new window.KeyboardEvent( 'keydown', {
			key: 'a', bubbles: true, cancelable: true
		} );
		dialog.dispatchEvent( e );
		expect( e.defaultPrevented ).toBe( false );
	} );
} );
