# Rich cells: links, thumbnails, and link lists

To show a clickable wikilink or thumbnail in a cell, you can't pass an AG Grid renderer
function from Lua (see [the no-functions limit](authoring-grids.md#one-limit-no-functions)).
Instead, AGGrid ships ready-made **column types**: build a structured cell value with one helper,
then tag its column with the matching column helper.

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

## Build the cell value

| Function | Returns | Description |
| --- | --- | --- |
| `aggrid.link( target, text? )` | `{ text, href }` or `nil` | A wikilink to page `target`. `text` defaults to the title's display text. `nil` if the title can't be parsed. |
| `aggrid.thumb( file, width, opts? )` | `{ src, width, alt, href? }` or `nil` | A thumbnail of `file` (a `File:` title) at `width` px. `opts.link` makes it a *linked* thumbnail; `opts.alt` overrides the alt text (default: the file's page title). `nil` if the file is missing. |
| `aggrid.linkList( targets )` | `{ links = { … } }` | A comma-separated row of wikilinks from a list of page titles. Unparseable titles are skipped. |

## Tag the column

Each column helper returns a `colDef` with the right renderer `type` already set, and lets you use
the shorter `header` key in place of AG Grid's `headerName`. Any other `colDef` key passes straight
through. `type` is managed by the helper, so setting it yourself has no effect.

| Function | Column type | Pairs with |
| --- | --- | --- |
| `aggrid.linkColumn( spec )` | `aggridLink` | `link()` values |
| `aggrid.imageColumn( spec )` | `aggridImage` | `thumb()` values |
| `aggrid.linkListColumn( spec )` | `aggridLinkList` | `linkList()` values |

Prefer to write it by hand? Set `type` directly: `{ field = 'name', type = 'aggridLink' }`.

## Good to know

- **Linking is a modifier, not a separate type.** Any cell value can carry an `href`, and the
  renderer wraps it in a link — that's how `aggrid.thumb( file, width, { link = … } )` becomes a
  *linked* thumbnail.
- **Sort, filter, and quick-search use the text, not the markup.** They read each cell's
  underlying text (link text, alt text, joined list text) while the cell shows the rich content —
  so a [set filter](filters.md#set-filter) on a link column lists the link *text*.
- **Links and thumbnails resolve on the server** during the page parse, so the browser makes no
  extra requests to render a cell, and output is escaped by default (only `http(s):`,
  root-relative, `./`, and `#` link targets are allowed).

## See also

- [Authoring grids](authoring-grids.md) — the inline `gridOptions` model
- [Formatting](formatting.md) — number and date `format` specs
- [Filters](filters.md) — how filtering reads cell text
- [Extending with JavaScript](extending-column-types.md) — custom renderers for anything beyond links and thumbnails
