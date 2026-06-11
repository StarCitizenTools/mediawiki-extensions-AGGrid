# Backend source grids (Semantic MediaWiki & Bucket)

An ordinary grid carries its rows inline in `rowData`. A **backend source grid** instead describes
a *query*, and the extension fetches rows from a structured-data backend on demand — paging,
sorting, and filtering all happen on the server. Use one when the data already lives in
[Semantic MediaWiki](https://www.semantic-mediawiki.org/) or
[Bucket](https://www.mediawiki.org/wiki/Extension:Bucket), or when a dataset is too large to
inline (the inline limit is 5,000 rows).

For a source grid you set `gridOptions.source` instead of `columnDefs` or `rowData` — the columns
are built automatically from the backend's data types.

```lua
mw.ext.aggrid.render{
    source = { type = 'smw', query = '[[Category:Ship]]', printouts = { 'Has manufacturer' } },
}
```

> When `source` is present, any `columnDefs` / `rowData` you also pass are **ignored** — the
> grid is built entirely from the query. Each backend needs its extension installed: `'smw'`
> requires [Semantic MediaWiki](https://www.semantic-mediawiki.org/), `'bucket'` requires
> [Bucket](https://www.mediawiki.org/wiki/Extension:Bucket).

## The `source` descriptor

`source.type` selects the backend and the rest of the table describes the query:

| `type` | Backend | Describe the query with |
| --- | --- | --- |
| `'smw'` | Semantic MediaWiki | `query`, `printouts`, `mainlabel` |
| `'bucket'` | Bucket | `bucket`, `fields`, `join`, `where`, `orderBy` |

Both backends give you server-side sort, column filters, the [set filter](filters.md#set-filter),
and paging, and the reported total reflects the **whole** result set — not just the page on
screen. [`format`](formatting.md) specs work exactly as they do on inline grids.

---

## Semantic MediaWiki source (`type = 'smw'`)

| Field | Type | Notes |
| --- | --- | --- |
| `query` | string \| table | An `#ask` condition string, or a list of fragments joined with spaces. Required. |
| `printouts` | table | The columns: one entry per property. At least one required. |
| `mainlabel` | string \| nil | Header for the subject (page) column. `'-'` removes that column. Defaults to a `Page` column. |

Each `printouts` entry is one of:

- a property name — `'Has population'`
- a `'Property=Label'` string — `'Has population=Pop'`
- a table for the extra options:
  `{ prop = 'Has population', label = 'Pop', filterProp = …, type = …, cellRendererParams = …, format = … }`

`type`, `cellRendererParams`, and `format` are the same column options as on an inline grid
(see [rich cells](rich-cells.md) and [formatting](formatting.md)). Column data types — number,
date, page link, etc. — are derived automatically from each property's SMW datatype.

```lua
local aggrid = require( 'mw.ext.aggrid' )
local p = {}

function p.ships()
    return aggrid.render{
        source = {
            type = 'smw',
            -- A list of fragments, joined with spaces, reads more clearly than one long string.
            query = { '[[Category:Ship]]', '[[Has cargo capacity::+]]' },
            printouts = {
                'Has manufacturer',
                'Has cargo capacity=Cargo',
                { prop = 'Has model', label = 'Model', filterProp = 'Has manufacturer' },
            },
            mainlabel = 'Ship',
        },
    }
end

return p
```

### `filterProp` — filter on a different property than the column shows

By default a column's [set filter](filters.md#set-filter) lists and matches the same property
the column displays and sorts on. Set `filterProp` to filter on a *different* property: the
column still shows and sorts on `prop`, but its filter lists and matches `filterProp`'s values. In the example above, the **Model** column displays each ship's model but
filters by manufacturer.

### Quick search on SMW grids

The [quick-search box](filters.md#quick-search) works on SMW source grids. The term is sent to
the server and matched as a substring across the page name and the text and page columns. Number
columns match the value exactly, and date columns match by SMW's precision range (so a bare year
or month matches the whole period). Paging and totals reflect the whole result set. Substring
matching follows the database's `LIKE` case sensitivity — case-insensitive on MySQL/MariaDB,
case-sensitive on SQLite.

---

## Bucket source (`type = 'bucket'`)

| Field | Type | Notes |
| --- | --- | --- |
| `bucket` | string | The primary bucket name. Required. |
| `fields` | table | The columns: one entry per field. At least one required. |
| `join` | table \| nil | Joins to other buckets. |
| `where` | table \| nil | A base scope applied to every page of results. |
| `orderBy` | table \| nil | The default sort. |

Each `fields` entry is one of:

- a field name — `'value'`
- a qualified `'bucket.field'` for a joined field — `'skill.category'`
- a table for the extra options:
  `{ field = 'value', label = 'Value', filter = false, type = …, cellRendererParams = …, format = … }`

`page_name` is an ordinary `Page` field — list it to show a page-link column. Column data types
are read from the bucket's schema. A dotted field name becomes a dot-free column id internally
(`skill.category` → `skill__category`), which only matters if you reference the column id from
JavaScript.

- **`join`** — a list of `{ bucket = 'skill', on = { 'item.skill_required', 'skill.skill_name' } }`.
- **`where`** — a list of `{ field, operator, value }` conditions, ANDed together. The operators
  are the strings `'='`, `'!='`, `'>='`, `'<='`, `'>'`, `'<'`. Bucket has no substring/`LIKE`
  operator.
- **`orderBy`** — `{ field = 'value', direction = 'DESC' }`. The field must be one of `fields`.

```lua
local aggrid = require( 'mw.ext.aggrid' )
local p = {}

function p.items()
    return aggrid.render{
        source = {
            type = 'bucket',
            bucket = 'item',
            fields = {
                'page_name',
                { field = 'item_type', label = 'Type' },
                { field = 'value', label = 'Value', format = { style = 'number', suffix = ' gp' } },
                { field = 'skill.category', label = 'Skill type' },
            },
            join = {
                { bucket = 'skill', on = { 'item.skill_required', 'skill.skill_name' } },
            },
            where = {
                { 'members', '=', false },
            },
            orderBy = { field = 'value', direction = 'DESC' },
        },
    }
end

return p
```

### No quick search on Bucket grids

Bucket's query language has no `LIKE` operator, so the [quick-search box](filters.md#quick-search)
is not available on Bucket source grids — a `quickSearch` option is accepted but ignored. Column
filters (set filters and number filters) still work.

---

## What works on a source grid

| Capability | SMW | Bucket |
| --- | --- | --- |
| Server-side sort | ✅ | ✅ |
| Number / range column filter | ✅ | ✅ |
| [Set filter](filters.md#set-filter) (distinct values) | ✅ | ✅ |
| Text "contains" column filter | ✅ | ❌ (no `LIKE`) |
| [Quick search](filters.md#quick-search) | ✅ | ❌ (no `LIKE`) |
| `filterProp` facet | ✅ | — |
| Paging with whole-result-set totals | ✅ | ✅ |
| [`format`](formatting.md) specs | ✅ | ✅ |

On saved pages the rows are served from a cacheable REST endpoint, so a source grid adds no
weight to the page HTML itself.

## See also

- [Authoring grids](authoring-grids.md) — the inline `gridOptions` model
- [Filters](filters.md) — the set filter and quick-search box
- [Formatting](formatting.md) — number and date `format` specs
- [README](../README.md) — installation and configuration
