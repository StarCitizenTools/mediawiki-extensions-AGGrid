# AGGrid

A MediaWiki extension that renders [AG Grid](https://www.ag-grid.com/) data grids.

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

## Requirements

- MediaWiki 1.43+
- [Scribunto](https://www.mediawiki.org/wiki/Extension:Scribunto)

## License

GPL-3.0-or-later. Bundles AG Grid Community (MIT) — see `modules/lib/ag-grid-community/LICENSE.txt`.
