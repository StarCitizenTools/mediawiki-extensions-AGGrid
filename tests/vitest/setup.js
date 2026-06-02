// Minimal MediaWiki client globals for the jsdom test environment.
global.mw = global.mw || {
	log: {
		error: () => {},
		warn: () => {}
	}
};
