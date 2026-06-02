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

**Preflight**: `npm run preflight` runs all Node lints + JS tests. `composer preflight` (run inside a MediaWiki installation) runs all PHP lints, style checks, Phan, and PHPUnit.

**Always run the relevant checks before committing.** Read full output — PHPCS warnings must be fixed, not just errors. The command exits 0 even with warnings, so do not treat exit code alone as a pass.

### Dev environment

The standard dev environment is the MediaWiki Docker setup in the parent `mediawiki/` directory. The user may use a different environment; ask for the URL and how to run commands if unknown.

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/AGGrid && composer preflight"
```

### Phan

Phan requires a full MediaWiki installation at `../../`. `.phan/config.php` includes Scribunto for type resolution.

```sh
docker compose exec mediawiki bash -c "cd /var/www/html/w/extensions/AGGrid && composer phan"
```

### Browser testing

When verifying runtime behavior (scripts load, grid renders, interactions):

- Use browser automation (Chrome DevTools MCP, Playwright MCP) against the dev URL before asking the user to test manually.
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
