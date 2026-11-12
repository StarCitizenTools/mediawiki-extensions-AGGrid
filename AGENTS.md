# AGENTS.md

## Overview

AGGrid is a MediaWiki extension (requires MW 1.43+) that provides a Scribunto/Lua library (`mw.ext.aggrid`) for rendering [AG Grid](https://www.ag-grid.com/) data grids on wiki pages. Lua passes an AG Grid `gridOptions` table; PHP emits a placeholder element carrying the config as JSON; a ResourceLoader module hydrates it client-side with the vendored AG Grid Community UMD bundle. Scribunto is a hard dependency.

## Verification

Run only what's relevant to the files you changed.

| Files changed | Command |
| --- | --- |
| `*.php` | `composer preflight` (lint, style, Phan, PHPUnit) |
| `*.js` | `npm run lint:js && npm test` |
| `*.less`, `*.css` | `npm run lint:styles` |
| `i18n/` | `npm run lint:i18n` |

Auto-fix: `composer fix` (PHP), `npm run lint:fix:js` (JS), `npm run lint:fix:styles` (styles).

**Preflight**: `npm run preflight` runs all Node lints + JS tests. `composer preflight` (run in the [dev environment](#dev-environment)) runs all PHP lints, style checks, Phan, and PHPUnit.

**Always run the relevant checks before committing.** Read full output — PHPCS warnings must be fixed, not just errors. The command exits 0 even with warnings, so do not treat exit code alone as a pass.

### Dev environment

PHP checks, PHPUnit and browser tests need a running MediaWiki that serves this checkout. Use the first that applies:

1. An environment the user has named.
2. A disposable one you start yourself with [mwenv](https://github.com/ProfessionalWiki/mwenv), which needs no MediaWiki installation.
3. A MediaWiki installation that contains this checkout, such as MediaWiki-Docker, when the task needs its content or extensions.

Ask the user only if none of these can run, or the task needs something a fresh wiki lacks. Don't destroy an environment you didn't create: its database may hold the user's test content.

Whichever you use, it needs what CI's PHP jobs get (`.github/workflows/ci.yml`): load each extension in `extra-extensions`, and require each package in `extra-composer-packages` into MediaWiki with Composer, without loading it.

On arm64 hosts, Scribunto needs the LuaSandbox PHP extension, because the standalone Lua binary it ships is x86-64 only.

### Phan

`.phan/config.php` adds Scribunto and Semantic MediaWiki to type resolution when they are in MediaWiki's `extensions/` directory. Without SMW, Phan reports undeclared classes in `includes/DataSource/Smw/`. Bucket is not a Composer package, so when it is absent Phan uses `.phan/bucket-stubs/` instead.

### PHPUnit

The Semantic MediaWiki and Bucket data-source tests skip unless those extensions are loaded, as they do in CI.

### Browser testing

When verifying runtime behavior (scripts load, grid renders, interactions):

- Use browser automation (Chrome DevTools MCP, Playwright MCP) against the dev environment's URL before asking the user to test manually.
- Check the browser console for warnings/errors, not just visual correctness.
- **XSS testing for i18n**: when touching interface messages, append `?uselang=x-xss` — if any script executes or markup is injected, the output is not properly escaped. See [Manual:$wgUseXssLanguage](https://www.mediawiki.org/wiki/Manual:$wgUseXssLanguage)

## Coding conventions

### PHP
- All files start with `declare( strict_types=1 );`
- Use native PHP types; PHPDoc only for collection types like `string[]`
- Always use MediaWiki-namespaced imports (`use MediaWiki\Html\Html;`), never legacy shims

### JavaScript
- CommonJS modules: `require()` / `module.exports`

### LESS/CSS
- Styles live in `modules/`

### extension.json
`extension.json` is the source of truth for wiring (ResourceLoader modules, hooks, dependencies).
- When adding/removing files under `modules/`, update the matching `packageFiles`/`styles`/`scripts` list.

### Commits
- Use [Conventional Commits](https://www.conventionalcommits.org/) (`fix:`, `feat:`, `refactor:`); `ci:`/`chore:` for non-user-facing changes.

### i18n
- Any user-facing string needs a key in `i18n/en.json`; every key needs a doc entry in `i18n/qqq.json`.
