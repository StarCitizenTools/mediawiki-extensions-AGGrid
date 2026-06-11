# AGGrid

Build sortable, filterable [AG Grid](https://www.ag-grid.com/) data tables on wiki pages,
straight from Lua. Put clickable links and thumbnails inside cells, page through large datasets,
and query rows from [Semantic MediaWiki](https://www.semantic-mediawiki.org/) or
[Bucket](https://www.mediawiki.org/wiki/Extension:Bucket).

## ✨ Highlights

- **Interactive tables on wiki pages** — readers sort, filter, search, and page through the data
  in place, instead of scrolling a static wikitable.
- **Scales to large datasets** — page through thousands of rows, or query them on the server so
  the page stays light.
- **Shows your structured data** — turn [Semantic MediaWiki](https://www.semantic-mediawiki.org/)
  or [Bucket](https://www.mediawiki.org/wiki/Extension:Bucket) data into a live table, sorted,
  filtered, and paged on the server.
- **Rich cells** — clickable page links and thumbnails inside cells, resolved server-side.
- **Authored in Lua** — define grids in a Scribunto module; no hand-built HTML or JavaScript.
- **Fits your wiki** — matches the active skin's light/dark colours automatically, and extends
  from JavaScript when you need a custom renderer or filter.

## 📋 Requirements

- MediaWiki 1.43 or later
- [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto)

## 📦 Installation

1. Drop the extension in `extensions/AGGrid` and load it from `LocalSettings.php`:

   ```php
   wfLoadExtension( 'AGGrid' );
   ```

2. Run the database updater to create the extension's tables (`aggrid_data`, `aggrid_source`):

   ```sh
   php maintenance/run.php update
   ```

   Grids will not render until these tables exist.

## ⚙️ Configuration

All settings are optional.

| Setting | Default | Description |
| --- | --- | --- |
| `$wgAGGridBackendCacheMaxAge` | `600` | Max-age (seconds) for backend-source grid REST responses (the row pages and column values). Higher cuts backend load but is a hard staleness ceiling — there's no purge when the underlying data changes. |
| `$wgAGGridBucketMaxValues` | `1000` | Maximum rows scanned when listing a Bucket column's set-filter values; the list is marked partial when reached. |

## 🚀 Quick start

Pass a `gridOptions` table to `render`:

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

`gridOptions` mirrors AG Grid's
[`gridOptions`](https://www.ag-grid.com/javascript-data-grid/grid-options/) object one to one, so
anything JSON-serialisable from their docs works here. See
[Authoring grids](docs/authoring-grids.md) for the full picture.

## 🔀 Two ways to supply data

- **Inline** — supply `columnDefs` and `rowData` directly, as above. Best for small, hand-written
  or Lua-generated tables. → [Authoring grids](docs/authoring-grids.md)
- **Backend source** — supply a `source` descriptor and the rows come from Semantic MediaWiki or
  Bucket, queried and paged on the server. Best for large or already-stored datasets. →
  [Backend source grids](docs/data-sources.md)

## 📚 Documentation

| Guide | What it covers |
| --- | --- |
| [Authoring grids](docs/authoring-grids.md) | The inline `gridOptions` model, the no-functions limit, pagination, theming, limits |
| [Rich cells](docs/rich-cells.md) | Links, thumbnails, linked thumbnails, and link lists |
| [Formatting](docs/formatting.md) | `format` specs for numbers and dates |
| [Filters](docs/filters.md) | The `aggridSet` set filter and the quick-search box |
| [Backend source grids](docs/data-sources.md) | Querying rows from Semantic MediaWiki and Bucket |
| [Extending with JavaScript](docs/extending-column-types.md) | Custom column types, renderers, filters, and the grid API |

## 🔎 See also

- [Extension page on mediawiki.org](https://www.mediawiki.org/wiki/Extension:AGGrid)
- [AG Grid documentation](https://www.ag-grid.com/javascript-data-grid/)

## ⚖️ License

GPL-3.0-or-later. Bundles AG Grid Community (MIT); see `modules/lib/ag-grid-community/LICENSE.txt`.
