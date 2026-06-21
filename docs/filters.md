# Filters: set filter and quick search

AG Grid Community's built-in column filters (text, number, date) all work as usual. On top of
them, AGGrid adds a **set filter** and a grid-wide **quick-search box**.

## Set filter

Enable a **set filter** — a checkbox list of a column's distinct values — per column with
`filter = 'aggridSet'`. AG Grid Community has no built-in set filter, so AGGrid ships one:

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

The popup lists each unique value with its row count, plus a search box for long lists, a tri-state
"select all", and a `(Blanks)` entry for empty cells. On [rich columns](rich-cells.md)
(`linkColumn`, `imageColumn`, `linkListColumn`) it filters on the **displayed text**, matching how
sort and quick-search behave.

- On an **inline** grid, the value list comes from all loaded rows; other columns' active filters
  do not narrow it.
- On a [backend source grid](data-sources.md), the distinct values are fetched from the server.

### Multi-value columns

A cell that holds **multiple values** — a [`linkList` / `tagList`](rich-cells.md) cell
(`{ links = … }`) or a plain array — is split into one checkbox per distinct value. A row passes
when **any** of its values is selected, so to hide a row tagged both `A` and `B` you must deselect
both. Each value's count is the number of rows that contain it, so the counts can sum to more than
the row total.

Only **structured** multi-value cells split: the value must be a `linkList` / `tagList` cell or an
array. A plain delimited string such as `"A, B"` stays a single option — AGGrid does not guess
delimiters.

### Filtering on a different facet

To make a column *filter* on a different value than it *shows*, use one of two approaches,
depending on the grid:

- On a [Semantic MediaWiki source grid](data-sources.md#filterprop--filter-on-a-different-property-than-the-column-shows),
  set `filterProp` on the printout — declarative, in Lua.
- On any grid, a JavaScript column type can set `filterValueGetter` (and draw icons beside each
  value with `filterParams.itemRenderer`). See
  [extending with JavaScript](extending-column-types.md#rich-set-filters-and-the-grid-api).

## Quick search

Set `quickSearch = true` to render a themed, localised search box above the grid, wired to
AG Grid's quick filter:

```lua
mw.ext.aggrid.render{
    quickSearch = true,
    -- or: quickSearch = { placeholder = 'Find ships…', debounceMs = 300 },
    columnDefs = { … },
    rowData = rows,
}
```

`quickSearch = true` uses a default placeholder and a 200 ms debounce; pass a table to override
`placeholder` and `debounceMs` (capped at 5000). Every whitespace-separated word must match
somewhere, mirroring AG Grid's own quick filter.

Where the matching happens depends on the grid:

| Grid | Quick search |
| --- | --- |
| Inline | Filters the loaded rows in the browser. |
| [SMW source](data-sources.md#quick-search-on-smw-grids) | Sent to the server; matches a substring across the page name and text/page columns (number columns match exactly, date columns by precision range), so paging and totals reflect the whole result set. `LIKE` case sensitivity follows the database (case-insensitive on MySQL/MariaDB, case-sensitive on SQLite). |
| [Bucket source](data-sources.md#no-quick-search-on-bucket-grids) | Not available — Bucket has no `LIKE` operator, so a `quickSearch` option is ignored. |

## See also

- [Authoring grids](authoring-grids.md) — the inline `gridOptions` model
- [Rich cells](rich-cells.md) — how filtering reads cell text
- [Backend source grids](data-sources.md) — server-side filtering and `filterProp`
- [Extending with JavaScript](extending-column-types.md) — `filterValueGetter` and set-filter icons
