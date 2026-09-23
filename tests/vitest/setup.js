// Minimal MediaWiki client globals for the jsdom test environment.
global.mw = global.mw || {
	config: {
		get: () => null
	},
	log: {
		error: () => {},
		warn: () => {}
	},
	msg: ( key ) => key,
	html: {
		escape: ( s ) => s
	}
};
