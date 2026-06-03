// Minimal MediaWiki client globals for the jsdom test environment.
global.mw = global.mw || {
	log: {
		error: () => {},
		warn: () => {}
	},
	msg: ( key ) => key,
	html: {
		escape: ( s ) => s
	}
};
