# AGGrid

A MediaWiki extension that renders [AG Grid](https://www.ag-grid.com/) data grids on wiki
pages via a [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto)/Lua library
(`mw.ext.aggrid`).

Lua builds an AG Grid `gridOptions` table; PHP emits a placeholder carrying the config as
JSON; a ResourceLoader module hydrates it client-side with the vendored AG Grid Community
bundle. The grid lazy-loads as it nears the viewport and follows the wiki's light/dark
colour scheme via Codex design tokens.

## Requirements

- MediaWiki 1.43+
- [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto) (hard dependency)

## Installation

Place the extension in `extensions/AGGrid` and add to `LocalSettings.php`:

```php
wfLoadExtension( 'AGGrid' );
```

## Usage

```lua
mw.ext.aggrid.render( {
    columnDefs = {
        { field = 'name', headerName = 'Name' },
        { field = 'price', headerName = 'Price', filter = 'agNumberColumnFilter' },
    },
    rowData = {
        { name = 'Aurora', price = 25 },
        { name = 'Mustang', price = 30 },
    },
    pagination = true,
} )
```

The table passed to `render` is an AG Grid [`gridOptions`](https://www.ag-grid.com/javascript-data-grid/grid-options/) object, mirrored 1:1. `columnDefs` and `rowData` are required.

Function-based options (`cellRenderer`, `comparator`, …) can't cross the JSON boundary —
use the rich-cell helpers below for links and thumbnails.

## Rich cells: links, thumbnails, and link lists

Cell values that need to be **clickable links** or **thumbnails** can't be expressed as AG
Grid renderer functions from Lua. Instead, the extension ships named **column types** that
render structured cell values into safe DOM. You produce the values with helper functions
and tag each column with the matching column helper:

```lua
local aggrid = require( 'mw.ext.aggrid' )

local p = {}
function p.ships()
    return aggrid.render{
        columnDefs = {
            aggrid.imageColumn{ field = 'pic', header = 'Image' },
            aggrid.linkColumn{ field = 'name', header = 'Name' },
            aggrid.linkListColumn{ field = 'variants', header = 'Variants' },
            { field = 'price', headerName = 'Price', filter = 'agNumberColumnFilter' },
        },
        rowData = {
            {
                pic = aggrid.thumb( 'File:Aurora.jpg', 120, { link = 'Aurora MR' } ),
                name = aggrid.link( 'Aurora MR' ),
                variants = aggrid.linkList{ 'Aurora LN', 'Aurora CL' },
                price = 110,
            },
        },
    }
end
return p
```

Key properties:

- **Resolution is server-side.** Links and thumbnails resolve to URLs during the page
  parse, so the client renderers never make network requests.
- **`href` is an orthogonal modifier.** Any rich cell value may carry an `href`; the
  renderer wraps its output in an anchor. That's how a thumbnail becomes a *linked*
  thumbnail (`aggrid.thumb( file, width, { link = ... } )`).
- **Sort/filter use the text, not the object.** Sorting, filtering, quick-search, and CSV
  export operate on the cell's underlying text (link text, alt text, joined list text),
  while the cell *displays* the rich content.
- **Safe by construction.** Renderers build DOM with `textContent` and typed properties
  (never `innerHTML`), and only allow `http(s):`, root-relative, `./`, and `#` link
  schemes.

## Lua API — `mw.ext.aggrid`

### Rendering

| Function | Returns | Description |
| --- | --- | --- |
| `render( gridOptions )` | wikitext | Render a grid. `columnDefs` and `rowData` are required. |

### Cell value helpers

| Function | Returns | Description |
| --- | --- | --- |
| `link( target, text? )` | `{ text, href }` or `nil` | A wikilink cell to page `target`; `text` defaults to the title's display text. `nil` if the title can't be parsed. |
| `thumb( file, width, opts? )` | `{ src, width, alt, href? }` or `nil` | A thumbnail of `file` (a `File:` title) at `width` px. `opts.link` makes it a linked thumbnail; `opts.alt` overrides the alt text (default: the file's page title). `nil` if the file is missing. |
| `linkList( targets )` | `{ links = { … } }` | A comma-separated list of wikilinks from a sequence of page titles. Unparseable titles are skipped. |

### Column helpers

Each returns a `colDef` with the matching renderer `type` preset, and maps the short
`header` key to AG Grid's `headerName`. Other `colDef` keys pass through; the `type` key is
reserved (always set by the helper).

| Function | Column type | Use with |
| --- | --- | --- |
| `linkColumn( spec )` | `aggridLink` | `link()` values |
| `imageColumn( spec )` | `aggridImage` | `thumb()` values |
| `linkListColumn( spec )` | `aggridLinkList` | `linkList()` values |

Columns can also be written by hand: `{ field = 'name', type = 'aggridLink' }`.

## Theming

The grid uses an AG Grid theme mapped to MediaWiki's Codex design tokens, so it follows the
wiki's light/dark/OS colour scheme and skin colour customisations through the CSS cascade.
Override by setting `gridOptions.theme`.

## Extending with custom column types

Other extensions or skins can register additional column types before grids mount:

```javascript
mw.hook( 'ext.aggrid.registerColumnTypes' ).add( ( types, withLink ) => {
    types.myBadge = {
        cellRenderer: withLink( ( params ) => {
            const span = document.createElement( 'span' );
            span.textContent = ( params.value && params.value.label ) || '';
            return span;
        } ),
        valueFormatter: ( p ) => ( p.value && p.value.label ) || ''
    };
} );
```

`withLink` is the optional anchor-wrapping helper; renderers must build DOM safely (no
`innerHTML` of untrusted values).

## Limits

Inline `rowData` is capped (5000 rows) — for larger datasets use a structured data
backend. On saved pages, row data is served from a cacheable REST endpoint rather than
embedded in the page HTML.

## See also

- Extension page: <https://www.mediawiki.org/wiki/Extension:AGGrid>
- AG Grid documentation: <https://www.ag-grid.com/javascript-data-grid/>

## License

GPL-3.0-or-later. Bundles AG Grid Community (MIT) — see `modules/lib/ag-grid-community/LICENSE.txt`.
