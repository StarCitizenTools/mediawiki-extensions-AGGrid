# Extending AGGrid with custom column types

AGGrid's core ships only the cell types that need **server-side resolution** — links and
thumbnails, where MediaWiki must turn a page title or `File:` into a URL before the value
crosses into the browser. Anything that is purely about **rendering** — coloured badges,
custom layouts, icons, progress bars — lives in JavaScript on your wiki, registered through
a hook. Core stays small; you get unlimited rendering freedom.

## The hook

`buildColumnTypes()` fires `ext.aggrid.registerColumnTypes` before every grid mounts. A
handler receives the mutable `types` map and `withLink` (a helper that wraps a renderer's
output in a scheme-checked anchor when the value carries an `href`):

```javascript
mw.hook( 'ext.aggrid.registerColumnTypes' ).add( ( types, withLink ) => {
    types.myType = {
        cellRenderer: ( params ) => { /* params.value -> DOM node */ },
        valueFormatter: ( p ) => '…', // derived scalar for sort/filter/search/export
        comparator: ( a, b ) => 0     // optional; defaults to the formatted scalar
    };
} );
```

Register it from anything that loads before grids mount — a sister extension, a skin, a
gadget, or `MediaWiki:Common.js`. A type registered this way is a **peer of the built-in
types**: you reference it from Lua by name (`type = 'myType'`), exactly like `aggridLink`.

This maps onto AG Grid's own [`columnTypes`](https://www.ag-grid.com/javascript-data-grid/column-definitions/#default-column-definitions)
registry — a column type bundles colDef properties (a `cellRenderer` plus its sort/filter
`valueFormatter`/`comparator`), which is the right shape when a cell needs a renderer *and*
a derived sort value (badges, links, thumbnails). AG Grid's other registry,
[`components`](https://www.ag-grid.com/javascript-data-grid/components/) — named renderers,
filters, and editors referenced by string (`cellRenderer = 'name'`, `filter = 'name'`) — is
not yet surfaced through this hook, so custom *filters* and *editors* can't be registered
this way today.

### Rules for a safe, well-behaved renderer

- **Build DOM, never `innerHTML`.** Use `document.createElement` + `textContent` and typed
  properties. Cell values are author data; treating them as HTML is an XSS hole.
- **Keep a derived scalar.** Sort, filter, quick-search, and CSV export run on
  `valueFormatter`'s output, not your DOM. Return the cell's plain text there.
- **Only data crosses the JSON boundary.** Your renderer (a function) lives in JS; from Lua
  you send only the cell *value* and the column's `type` / `cellRendererParams` (plain data).

## Worked example: a status badge

A coloured status pill is pure styling with no server-side resolution, so it belongs here
rather than in core. Register the renderer and its styles once on your wiki.

**`MediaWiki:Common.js`**

```javascript
mw.hook( 'ext.aggrid.registerColumnTypes' ).add( ( types ) => {
    // The variant becomes a CSS class, so allow only a safe slug.
    const SAFE = /^[a-z0-9-]+$/;

    const labelOf = ( v ) => ( v && typeof v === 'object' ) ? v.label : v;

    types.badge = {
        cellRenderer: ( params ) => {
            const v = params.value;
            // Object form carries its own variant; a scalar value is mapped via
            // cellRendererParams.variantMap (AG Grid merges those onto params).
            let variant = ( v && typeof v === 'object' ) ?
                v.variant :
                ( params.variantMap || {} )[ labelOf( v ) ];
            if ( typeof variant !== 'string' || !SAFE.test( variant ) ) {
                variant = ( typeof params.defaultVariant === 'string' && SAFE.test( params.defaultVariant ) ) ?
                    params.defaultVariant : 'neutral';
            }
            const span = document.createElement( 'span' );
            span.className = 'ext-aggrid-badge ext-aggrid-badge--' + variant;
            const label = labelOf( v );
            span.textContent = label == null ? '' : String( label );
            return span;
        },
        valueFormatter: ( p ) => {
            const label = labelOf( p.value );
            return label == null ? '' : String( label );
        }
    };
} );
```

**`MediaWiki:Common.css`**

```css
.ext-aggrid-badge {
    display: inline-block;
    padding: 1px 8px;
    border-radius: 12px;
    font-size: 0.85em;
    font-weight: bold;
    line-height: 1.5;
    white-space: nowrap;
}
.ext-aggrid-badge--neutral { color: #54595d; background-color: #eaecf0; }
.ext-aggrid-badge--success { color: #096450; background-color: #cef0e5; }
.ext-aggrid-badge--warning { color: #ad5f00; background-color: #fef6e7; }
.ext-aggrid-badge--error   { color: #bf3c2c; background-color: #ffe9e5; }
```

### Using it from Lua

**Inline grids** — give each cell a structured value:

```lua
mw.ext.aggrid.render{
    columnDefs = {
        { field = 'name', headerName = 'Name' },
        { field = 'status', headerName = 'Status', type = 'badge' },
    },
    rowData = {
        { name = 'Aurora', status = { label = 'Flyable', variant = 'success' } },
        { name = 'Idris',   status = { label = 'Concept', variant = 'warning' } },
    },
}
```

…or send a plain value and map it to a variant on the column — handy when the value comes
from data you don't format per-cell:

```lua
{ field = 'status', headerName = 'Status', type = 'badge',
  cellRendererParams = {
      variantMap = { Flyable = 'success', Concept = 'warning' },
      defaultVariant = 'neutral',
  } }
```

**Backend (Semantic MediaWiki) grids** — the same `type` and `cellRendererParams` can be
set on a printout, and AGGrid carries them onto the generated column. The cell value is the
plain SMW value, resolved to a variant by the map:

```lua
mw.ext.aggrid.render{
    source = {
        type = 'smw',
        query = '[[Category:Ship]]',
        printouts = {
            { prop = 'Production state', label = 'Status', type = 'badge',
              cellRendererParams = {
                  variantMap = { Flyable = 'success', Concept = 'warning' },
                  defaultVariant = 'neutral',
              } },
        },
    },
}
```

This is the same mechanism the built-in types use — your `badge` is a first-class column
type on both the inline and backend paths.
