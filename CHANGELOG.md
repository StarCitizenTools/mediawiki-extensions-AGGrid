# Changelog

## [0.6.1](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.6.0...v0.6.1) (2026-09-02)


### Bug Fixes

* **expand:** keep the grid flush with the expanded view ([34c489c](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/34c489cdc3d987ba7eb7309005cb4db1c26a9708))

## [0.6.0](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.5.1...v0.6.0) (2026-09-02)


### Features

* **expand:** full-window view for wide grids ([c1b70ee](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/c1b70eea63afa1d17a3152f445e0214064aa6665))


### Bug Fixes

* **deps:** bump ag-grid-community from 36.0.2 to 36.1.0 ([e7f61ef](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/e7f61efae7387bb104f3e4f820cda309f15a2ff3))


### Miscellaneous Chores

* **loader:** register AG Grid's ValidationModule in debug mode ([f0dafbd](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/f0dafbde4163bfa0662e22fda1182ba6c4f391ad))

## [0.5.1](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.5.0...v0.5.1) (2026-08-05)


### Bug Fixes

* **deps:** bump ag-grid-community from 35.3.0 to 36.0.2 ([b424b9b](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/b424b9bf6d3b1be971f8ae6f0a378ace58d1fd85))
* **loader:** build the AG Grid bundle URL server-side ([5e5b90c](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/5e5b90cf25eb353fe7b9ba22b056b52710cfeebb))
* **setfilter:** make the popup search actually hide non-matching values ([#59](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/59)) ([a3694fc](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/a3694fcc7aee3c4e6ddff4db06c263c8be3317fa))

## [0.5.0](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.4.0...v0.5.0) (2026-06-21)


### Features

* **filter:** multi-value set filter (list helper + linkList split) ([#47](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/47)) ([3457a14](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/3457a14f2640093c548ead6d53148f35545bb269))


### Bug Fixes

* **cache:** content-address backend /page + /values cache key by spec hash ([#46](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/46)) ([f9d7125](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/f9d71254ea2103593806fcfb3cfe79aa5d1f282a))
* **cache:** content-address inline grid rows to stop stale CDN serving ([#39](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/39)) ([#43](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/43)) ([b82bfcc](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/b82bfcc6c44e639258b0e3bf6cb57ac5036edeaa))

## [0.4.0](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.3.0...v0.4.0) (2026-06-13)


### Features

* lazily populate grid stores on REST cache-miss ([#31](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/31)) ([#32](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/32)) ([7cd32d6](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/7cd32d6f6c6e2a7fe52def45b77e21ec8789516c))

## [0.3.0](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.2.0...v0.3.0) (2026-06-11)


### Features

* Bucket backend data source ([#25](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/25)) ([5872587](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/58725872160ab0463e760972f216545fa10016ee))
* built-in quick-search box with quickSearch gridOption ([#23](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/23)) ([3906d28](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/3906d28436c1f622c5e69e820abe0eeb36757847))
* gridReady hook, set-filter filterValueGetter and itemRenderer ([#17](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/17)) ([#18](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/18)) ([fef8101](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/fef810141fa0f6adc9e6d42874f5190cdc9365ae))
* quick-search box for backend (SMW) grids ([#24](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/24)) ([2b368d6](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/2b368d6a38c184615d5e8c3f64ea5471e91442a8))
* server-side filter facet with filterProp on backend SMW grids ([#20](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/20)) ([#22](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/22)) ([dfd3ea6](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/dfd3ea670dd1f5c254606996bf434d931d5b48bd))

## [0.2.0](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/compare/v0.1.0...v0.2.0) (2026-06-08)


### Features

* add Semantic MediaWiki as a backend data source ([#11](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/11)) ([0d921ac](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/0d921acc2b1a667331c5a26dab7d6d63e89e1ee6))
* add tracking category for pages using AG Grid ([#16](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/16)) ([d107adc](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/d107adce3fb6123e0131d17da5d65bfaa4e31cb9))
* declarative cell formatting and backend per-column config ([#13](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/13)) ([4e8bf77](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/4e8bf776135f544f60133811d39c23d40236298d))
* fetch grid row data on demand via a cacheable REST API ([#7](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/7)) ([6c43ce4](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/6c43ce4e9388b2a05858c12ee07b75601430ca3f))
* initial AGGrid extension — Scribunto library for AG Grid data grids ([bf66a5f](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/bf66a5fc05b9215484aa46f63c44a200b8028639))
* lazyload AG Grid with intersection observer ([#3](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/3)) ([c6b3794](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/c6b3794c7efbb14827b27bb54465684c63dfdbc1))
* light/dark mode via Codex token mapping ([#2](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/2)) ([be4d20a](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/be4d20a872339ff8a716366524caeb9a0d272683))
* rich cell rendering for links and thumbnails in AG Grid cells ([#6](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/6)) ([#8](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/8)) ([34bb949](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/34bb949715655c326f0ae02d5dd6b3a369cf2464))
* set filter for AG Grid Community ([#10](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/10)) ([3246f11](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/3246f1181cb5e1232d91f381bc47c9dba46cf3bf))
* sort set filter labels alphabetically ([#12](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/12)) ([ddf5c8d](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/ddf5c8d1e7376b349b88e38d7da25640408e13e2))
* unify column-type and component registration under ext.aggrid.register ([#14](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/14)) ([5332be6](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/5332be65f25752e7d101aac0ae35cb5eb27507f0))


### Bug Fixes

* drop footer from loading skeleton ([#4](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/issues/4)) ([c1f587d](https://github.com/StarCitizenTools/mediawiki-extensions-AGGrid/commit/c1f587d2815662868ff07bfd28faf092c7bf06fb))
