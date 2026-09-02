# Authoring grids in Lua

To build a grid, pass a standard AG Grid
[`gridOptions`](https://www.ag-grid.com/javascript-data-grid/grid-options/) table to
`mw.ext.aggrid.render`. The table maps one-to-one onto AG Grid's `gridOptions` object, so
anything JSON-serialisable from their docs works here too.

```lua
mw.ext.aggrid.render{
    columnDefs = {
        { field = 'name', headerName = 'Name' },
        { field = 'price', headerName = 'Price', filter = 'agNumberColumnFilter' },
    },
    rowData = {
        { name = 'Aurora', price = 25 },
        { name = 'Mustang', price = 30 },
    },
    pagination = true,
}
```

## Two ways to supply data

- **Inline** — supply `columnDefs` and `rowData` directly, as above. Both are required. Best for
  small, static tables written by hand or generated in Lua.
- **Backend source** — supply a [`source` descriptor](data-sources.md) instead, and the rows
  come from Semantic MediaWiki or Bucket, queried on the server. Best for large or already-stored
  datasets.

This page covers inline grids; see [backend source grids](data-sources.md) for the other mode.

## One limit: no functions

`gridOptions` is serialised to JSON, so **function** options can't cross from Lua —
`cellRenderer`, `valueFormatter`, `comparator`, and the like are dropped. Every case where you'd
reach for a function has a serialisable alternative:

| You'd normally write… | Instead use… |
| --- | --- |
| `cellRenderer` for a link or thumbnail | a [rich-cell helper](rich-cells.md) (`link`, `thumb`, `linkList`) + its column type |
| `valueFormatter` to format a number/date | a [`format` spec](formatting.md) |
| a custom `cellRenderer` / `filter` / `comparator` | a [JavaScript component](extending-column-types.md), referenced from Lua by name |

## Pagination

`pagination = true` turns on AG Grid's native pager; tune it with the standard AG Grid pagination
options (`paginationPageSize`, etc.). See
[AG Grid pagination](https://www.ag-grid.com/javascript-data-grid/row-pagination/).

## Theming

Grids match your wiki's skin automatically — colours follow the active skin's light/dark/OS
scheme with no configuration. To use a different look, set the standard AG Grid `theme` option;
see [AG Grid theming](https://www.ag-grid.com/javascript-data-grid/theming/).

## The full-window view

Wide grids rarely fit a wiki's content column. Set `expand = true` to add a button for an
expanded view that fills the browser window.

```lua
mw.ext.aggrid.render{
    columnDefs = { --[[ … ]] },
    rowData = { --[[ … ]] },
    expand = true,
}
```

Pass a table to override the button's label:

```lua
expand = { label = 'Open full width' },
```

## Limits

Inline `rowData` is capped at **5,000 rows**. For larger datasets, use a
[backend source grid](data-sources.md). On saved pages, inline rows are served from a cacheable
REST endpoint rather than written into the page HTML.

## Reference

| Function | Returns | Description |
| --- | --- | --- |
| `mw.ext.aggrid.render( gridOptions )` | wikitext | Renders a grid. Pass either `columnDefs` + `rowData` (inline) or a [`source`](data-sources.md) descriptor. |

Beyond AG Grid's own options, `gridOptions` accepts these additional ones:

| Option | Type | Default | Description |
| --- | --- | --- | --- |
| `expand` | boolean \| table | `false` | Full-window view button. `{ label = '…' }` overrides its label. |
| `quickSearch` | boolean \| table | `false` | [Quick-search box](filters.md). `{ placeholder = '…', debounceMs = 300 }`. |

## See also

- [Rich cells](rich-cells.md) — links, thumbnails, and link lists
- [Formatting](formatting.md) — number and date `format` specs
- [Filters](filters.md) — the set filter and quick-search box
- [Backend source grids](data-sources.md) — SMW and Bucket
- [Extending with JavaScript](extending-column-types.md) — custom renderers, filters, and editors
