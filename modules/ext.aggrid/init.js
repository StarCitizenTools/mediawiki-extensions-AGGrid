const { lazyMount } = require( './lazyMount.js' );
const { closeAll } = require( './expand.js' );

// Mount on every content render — initial load and re-rendered content alike
// (VisualEditor save, live preview, AJAX, lazy Tabber panels). lazyMount only
// loads the heavy bundle once a grid nears the viewport; mountGrid skips
// already-mounted placeholders, so repeated firings are cheap and idempotent.
mw.hook( 'wikipage.content' ).add( ( $content ) => {
	// An expanded grid holds the previous render's DOM inside its dialog: put it back
	// before the placeholder it came from is replaced, or the overlay floats over the
	// new page with an orphaned grid inside it.
	closeAll( $content[ 0 ] );
	lazyMount( $content[ 0 ] );
} );
