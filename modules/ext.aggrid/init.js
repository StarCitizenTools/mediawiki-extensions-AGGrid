const { lazyMount } = require( './lazyMount.js' );

// Mount on every content render — initial load and re-rendered content alike
// (VisualEditor save, live preview, AJAX, lazy Tabber panels). lazyMount only
// loads the heavy bundle once a grid nears the viewport; mountGrid skips
// already-mounted placeholders, so repeated firings are cheap and idempotent.
mw.hook( 'wikipage.content' ).add( ( $content ) => {
	lazyMount( $content[ 0 ] );
} );
