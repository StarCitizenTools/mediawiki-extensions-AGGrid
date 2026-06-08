# Extending AGGrid with custom column types and components

This guide shows you how to add your own column types and components to AGGrid from
JavaScript, with a complete worked example at the end.

Core ships only the cell types that need **server-side resolution** — links and
thumbnails, where MediaWiki must turn a page title or `File:` into a URL before the value
crosses into the browser. Anything that is purely about **rendering** — coloured badges,
custom layouts, icons, progress bars — lives in JavaScript on your wiki, registered through
a hook. Core stays small; you get unlimited rendering freedom.

## The hook

`registry.js` fires `ext.aggrid.register` before every grid mounts, handing each handler a
single **registry** object with AG Grid's two native registries plus a helper:

```javascript
mw.hook( 'ext.aggrid.register' ).add( ( reg ) => {
    // columnTypes — colDef bundles (renderer + sort/filter scalar), used via type=
    reg.columnTypes.myType = {
        cellRenderer: ( params ) => { /* params.value -> DOM node */ },
        valueFormatter: ( p ) => '…', // derived scalar for sort/filter/search/export
        comparator: ( a, b ) => 0     // optional; defaults to the formatted scalar
    };
    // components — named renderers / filters / editors, used via cellRenderer/filter/cellEditor=
    reg.components.myFilter = MyFilterComponent;
    // reg.withLink( render ) — wraps a renderer's output in a scheme-checked anchor
} );
```

Register from anything that loads before grids mount — a sister extension, a skin, a
gadget, or `MediaWiki:Common.js`. Your entries are **peers of the built-ins**: reference a
column type from Lua by name (`type = 'myType'`, like `aggridLink`) and a component by name
(`filter = 'myFilter'`, like the built-in `aggridSet`).

### Which registry do I use?

Both are AG Grid's own registries. Pick by what the cell needs:

- **[`columnTypes`](https://www.ag-grid.com/javascript-data-grid/column-definitions/#default-column-definitions)**
  bundles colDef properties — a `cellRenderer` plus its sort/filter
  `valueFormatter`/`comparator`. Use it when a cell needs a renderer *and* a derived sort
  value (badges, links, thumbnails).
- **[`components`](https://www.ag-grid.com/javascript-data-grid/components/)** registers a
  single named renderer, **filter**, or editor, referenced by string. Use it for a custom
  filter or editor, or a bare renderer with no sort-scalar needs.

### Example: a custom filter component

AG Grid Community ships few filters; register your own and reference it with `filter = 'name'`:

```javascript
mw.hook( 'ext.aggrid.register' ).add( ( reg ) => {
    // A minimal AG Grid filter component (see AG Grid's IFilterComp docs for the full API).
    class EvenOddFilter {
        init( params ) {
            this.params = params;
            this.checkbox = document.createElement( 'input' );
            this.checkbox.type = 'checkbox';
            this.checkbox.addEventListener( 'change', () => params.filterChangedCallback() );
            const label = document.createElement( 'label' );
            label.append( this.checkbox, ' Even only' );
            this.eGui = document.createElement( 'div' );
            this.eGui.appendChild( label );
        }
        getGui() { return this.eGui; }
        isFilterActive() { return this.checkbox.checked; }
        doesFilterPass( p ) {
            // getCellValue is the v32+ value API the extension uses elsewhere (see setFilter.js).
            const value = this.params.api.getCellValue( { rowNode: p.node, colKey: this.params.column } );
            return Number( value ) % 2 === 0;
        }
        getModel() { return this.checkbox.checked ? { even: true } : null; }
        setModel( model ) { this.checkbox.checked = !!( model && model.even ); }
    }
    reg.components.evenOnly = EvenOddFilter;
} );
```

From Lua: `{ field = 'count', filter = 'evenOnly' }`.

### Rules for a safe, well-behaved renderer

- **Build DOM, never `innerHTML`.** Use `document.createElement` + `textContent` and typed
  properties. Cell values are author data; treating them as HTML is an XSS hole.
- **Keep a derived scalar.** Sort, filter, quick-search, and CSV export run on
  `valueFormatter`'s output, not your DOM. Return the cell's plain text there.
- **Only data crosses the JSON boundary.** Your renderer (a function) lives in JS; from Lua
  you send only the cell *value* and the column's `type` / `cellRendererParams` (plain data).

## Worked example: a status badge

A coloured status pill is pure styling with no server-side resolution, so it belongs here
rather than in core. Register the renderer and its styles once on your wiki, then use the
`badge` type from any grid.

Register the renderer as a column type, mapping the cell value to a safe variant slug:

**`MediaWiki:Common.js`**

```javascript
mw.hook( 'ext.aggrid.register' ).add( ( reg ) => {
    const types = reg.columnTypes;
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

Then style each variant. These colours follow MediaWiki's Codex palette:

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

With `badge` registered, reference it by `type` like any built-in column type. You can feed
it values two ways.

**Inline grids** — give each cell a structured value carrying its own variant:

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

Or send a plain value and map it to a variant on the column — handy when the value comes
from data you don't format per cell:

```lua
{ field = 'status', headerName = 'Status', type = 'badge',
  cellRendererParams = {
      variantMap = { Flyable = 'success', Concept = 'warning' },
      defaultVariant = 'neutral',
  } }
```

**Backend (Semantic MediaWiki) grids** — set the same `type` and `cellRendererParams` on a
printout, and AGGrid carries them onto the generated column. The cell value is the plain SMW
value, resolved to a variant by the map:

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
