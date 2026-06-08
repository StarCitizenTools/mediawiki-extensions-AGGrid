# AGGrid

Build sortable, filterable [AG Grid](https://www.ag-grid.com/) data tables on wiki pages, straight from Lua. Put clickable links and thumbnails inside cells, and page through large datasets.

You write a standard AG Grid `gridOptions` table in Lua and call one function. The extension renders a lightweight placeholder, then hydrates it in the browser with the bundled AG Grid Community library. Grids load lazily as they scroll into view.

## ✨ Highlights

- Author full AG Grid `gridOptions` in Lua; existing AG Grid knowledge carries straight over.
- Clickable wikilinks, thumbnails, linked thumbnails, and link lists inside cells.
- Sort, filter (including a built-in **set filter**), quick-search, and CSV export all work on the underlying values.
- Lazy-loads, and on saved pages serves rows from a cacheable REST endpoint.

## 📋 Requirements

- MediaWiki 1.43 or later
- [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto) (required)

## 📦 Installation

Drop the extension in `extensions/AGGrid` and load it from `LocalSettings.php`:

```php
wfLoadExtension( 'AGGrid' );
```

## 🚀 Quick start

Pass a `gridOptions` table to `render`:

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

`gridOptions` mirrors AG Grid's [`gridOptions`](https://www.ag-grid.com/javascript-data-grid/grid-options/) object one to one, so anything JSON-serialisable from their docs works here. `columnDefs` and `rowData` are required.

One limit to know up front: function options such as `cellRenderer` and `comparator` can't cross into JSON. For links and thumbnails, reach for the rich-cell helpers below.

## 🔗 Links, thumbnails, and other rich cells

To show a clickable link or a thumbnail in a cell, you can't pass an AG Grid renderer function from Lua. Instead, AGGrid ships ready-made **column types**: build a structured cell value with a helper, then tag the column with its matching helper.

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

What makes this safe and fast:

- **Links and thumbnails resolve on the server**, during the page parse, so the browser never makes extra requests to render a cell.
- **Linking is a modifier, not a separate type.** Any cell value can carry an `href`, and the renderer wraps it in a link. That is how a thumbnail becomes a *linked* thumbnail: `aggrid.thumb( file, width, { link = ... } )`.
- **Sorting and filtering use the text, not the markup.** Sort, filter, quick-search, and CSV export read each cell's underlying text (link text, alt text, joined list text), while the cell shows the rich content.
- **Output is escaped by default.** Renderers build DOM with `textContent` and typed properties, never `innerHTML`, and only allow `http(s):`, root-relative, `./`, and `#` link targets.

## 🔽 Set filter

AG Grid Community has no built-in set filter (the checkbox list of a column's values), so AGGrid ships one. Enable it per column with `filter = 'aggridSet'`:

```lua
mw.ext.aggrid.render{
    columnDefs = {
        aggrid.linkColumn{ field = 'name', header = 'Name', filter = 'aggridSet' },
        { field = 'type', headerName = 'Type', filter = 'aggridSet' },
        { field = 'price', headerName = 'Price', filter = 'agNumberColumnFilter' },
    },
    rowData = rows,
}
```

The popup lists each unique value with a row count, a search box for long lists, a tri-state "select all", and a `(Blanks)` entry for empty cells. On rich columns (`linkColumn`, `imageColumn`, `linkListColumn`) it filters on the displayed text, matching how sort and quick-search behave. The value list is taken from all loaded rows; it is not narrowed by other columns' active filters.

## 📖 Lua API (`mw.ext.aggrid`)

### Render

| Function | Returns | Description |
| --- | --- | --- |
| `render( gridOptions )` | wikitext | Renders a grid. `columnDefs` and `rowData` are required. |

### Build cell values

| Function | Returns | Description |
| --- | --- | --- |
| `link( target, text? )` | `{ text, href }` or `nil` | A wikilink to page `target`. `text` defaults to the title's display text. Returns `nil` if the title can't be parsed. |
| `thumb( file, width, opts? )` | `{ src, width, alt, href? }` or `nil` | A thumbnail of `file` (a `File:` title) at `width` px. `opts.link` makes it a linked thumbnail; `opts.alt` overrides the alt text (default: the file's page title). Returns `nil` if the file is missing. |
| `linkList( targets )` | `{ links = { … } }` | A comma-separated row of wikilinks from a list of page titles. Unparseable titles are skipped. |

### Tag columns

Each helper returns a `colDef` with the right renderer `type` already set, and lets you use the shorter `header` key in place of AG Grid's `headerName`. Any other `colDef` keys pass straight through. `type` is managed by the helper, so setting it yourself has no effect.

| Function | Column type | Pairs with |
| --- | --- | --- |
| `linkColumn( spec )` | `aggridLink` | `link()` values |
| `imageColumn( spec )` | `aggridImage` | `thumb()` values |
| `linkListColumn( spec )` | `aggridLinkList` | `linkList()` values |

Prefer to write it by hand? Set the type directly: `{ field = 'name', type = 'aggridLink' }`.

## 🎨 Theming

Grids pick up the wiki's colours from the active skin. The AG Grid theme maps to MediaWiki's Codex design tokens, so on skins that expose those tokens, light, dark, and OS colour schemes (and skin colour overrides) flow through the CSS cascade with no configuration. On skins that don't provide Codex tokens, the grid falls back to a readable light theme. Either way, override it by setting `gridOptions.theme`.

## 🧩 Add your own cell types

Core ships only the cell types that need **server-side resolution** (links and thumbnails). Anything that is purely about *rendering* — badges, custom layouts, icons — lives in JavaScript: other extensions, skins, or site scripts (`MediaWiki:Common.js`) register extra column types before grids mount, and you reference them from Lua by name.

```javascript
mw.hook( 'ext.aggrid.registerColumnTypes' ).add( ( types, withLink ) => {
    types.myType = {
        cellRenderer: ( params ) => {
            const span = document.createElement( 'span' );
            span.textContent = ( params.value && params.value.label ) || '';
            return span;
        },
        valueFormatter: ( p ) => ( p.value && p.value.label ) || ''
    };
} );
```

The handler receives the type map and `withLink`, an optional helper that wraps a renderer's output in a scheme-checked link. Build DOM safely: use `textContent` and typed properties, never `innerHTML` on cell values, and return a plain scalar from `valueFormatter` so sort, filter, search, and export keep working.

For a complete, copy-pasteable recipe — a coloured status **badge** (renderer + CSS), used on both inline and Semantic MediaWiki grids — see [`docs/extending-column-types.md`](docs/extending-column-types.md).

## 📏 Limits

Inline `rowData` is capped at 5,000 rows. For larger datasets, use a structured-data backend. On saved pages, rows are served from a cacheable REST endpoint rather than inlined into the page HTML.

## 🔎 See also

- [Extension page on mediawiki.org](https://www.mediawiki.org/wiki/Extension:AGGrid)
- [AG Grid documentation](https://www.ag-grid.com/javascript-data-grid/)

## ⚖️ License

GPL-3.0-or-later. Bundles AG Grid Community (MIT); see `modules/lib/ag-grid-community/LICENSE.txt`.
