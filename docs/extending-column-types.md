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
- **Keep a derived scalar.** Sort, filter, and CSV export run on `valueFormatter`'s
  (and `comparator`'s) output, not your DOM. Return the cell's plain text there.
  Quick search is the exception: it runs on `getQuickFilterText`, so define that
  alongside `valueFormatter` for object values — without it the raw value is
  stringified.
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

## Rich set filters and the grid API

A rich column often needs to **sort and search on one value but filter on another**, show icons
in its filter list, or hand a gadget control of the grid. Three optional extension points cover
these, all reachable from the same `ext.aggrid.register` context. Skip any one and the grid
behaves exactly as before.

### Filter on a different facet: `filterValueGetter`

By default the set filter derives its value list — and decides what passes — from a column's
display scalar (its `valueFormatter` output, the same value that sort and CSV export use).
Set a **function** [`filterValueGetter`](https://www.ag-grid.com/javascript-data-grid/value-getters/)
on the colDef (or column type) to filter on a different facet instead:

```javascript
reg.columnTypes.ship = {
    // Sort / export on the ship name…
    valueFormatter: ( p ) => p.data.name,
    // …but list manufacturers in the set filter.
    filterValueGetter: ( p ) => p.data.manufacturer,
    filter: 'aggridSet'
};
```

The getter receives AG Grid's
[`ValueGetterParams`](https://www.ag-grid.com/javascript-data-grid/value-getters/) shape
(`{ api, colDef, column, node, data, getValue }`), so a getter written against the AG Grid docs
works unchanged. A `null`/empty return collapses into the `(Blanks)` bucket like any other
value. Quick search follows the facet too: such a column quick-searches on what
`getQuickFilterText` returns for the facet scalar — for the built-in rich types that means
the column is excluded from quick search, since their extractors return `''` for non-object
input.

Two boundaries, by design:

- **Function form only.** AG Grid also accepts a string expression (`'data.manufacturer'`), but
  AGGrid doesn't evaluate expressions — a string is ignored and the column falls back to its
  display scalar. The getter reaches the colDef through this hook anyway, so write a function.
- **Backend (SMW) grids declare the facet in Lua instead.** On a Semantic MediaWiki source
  grid the value list comes from the server and filtering happens in the SMW query, so a JS
  getter has nothing to influence. Set `filterProp` on the printout entry instead — the
  server lists and filters on that property while the column keeps displaying and sorting
  on its own:

  ```lua
  printouts = {
      { prop = 'Has name', label = 'Name', filterProp = 'Has manufacturer' },
  },
  ```

  The filter UI follows the facet's datatype (a page-valued facet gets the set filter, a
  number facet the number filter), and sorting stays on the display property.

### Icons in the filter list: `filterParams.itemRenderer`

Set-filter rows are plain text by default. Supply a `filterParams.itemRenderer` to render each
**value** row yourself — for example, a brand glyph beside each manufacturer:

```javascript
reg.columnTypes.ship = {
    valueFormatter: ( p ) => p.data.name,
    filterValueGetter: ( p ) => p.data.manufacturer,
    filter: 'aggridSet',
    filterParams: {
        // ( { label, key, count } ) -> Node. Build DOM; you own escaping, as with a renderer.
        itemRenderer: ( { label } ) => {
            const el = document.createElement( 'span' );
            el.className = 'my-brand my-brand--' + label.toLowerCase().replace( /[^a-z0-9]+/g, '-' );
            el.textContent = label;
            return el;
        }
    }
};
```

The renderer applies to value rows only — never the tri-state "select all" — and the
mini-filter search box and "select all" keep operating on the label **text**, so search still
works regardless of what you render. Return a falsy value to fall back to the plain text node.
As with a `cellRenderer`, build DOM and never assign user data to `innerHTML`.

### A handle to the grid after mount: `ext.aggrid.gridReady`

AGGrid fires `ext.aggrid.gridReady` once for every grid, right after it mounts (on the inline,
Semantic MediaWiki, and error paths alike), handing you the live AG Grid
[`GridApi`](https://www.ag-grid.com/javascript-data-grid/grid-api/) plus the placeholder
element and the resolved `gridOptions`.

For a global quick-search box you normally don't need the hook at all — set the
`quickSearch` gridOption in Lua and the extension renders a built-in, localised one.
If your gadget wires its own search UI, skip grids that already have the built-in box
(it is in the DOM by the time the hook fires; note the `gridOptions` handed to the
hook no longer carries `quickSearch` — it is consumed before the grid is created,
like `colDef.format` — so detect via the DOM, not the options):

```javascript
mw.hook( 'ext.aggrid.gridReady' ).add( ( api, el, gridOptions ) => {
    if ( el.querySelector( '.ext-aggrid-toolbar' ) ) {
        return; // this grid opted into the built-in quickSearch box
    }
    const search = document.createElement( 'input' );
    search.type = 'search';
    search.placeholder = 'Filter…';
    search.addEventListener( 'input', () => {
        api.setGridOption( 'quickFilterText', search.value );
    } );
    el.parentNode.insertBefore( search, el );
} );
```

Use it for external filters, toolbar controls, programmatic selection or export, or anything
else the `GridApi` exposes. Like `wikipage.content`, the hook replays its most recent fire to
handlers added later, so a late subscriber still receives the API. The hook also fires when a
grid's rows fail to load — it still mounts as an empty grid with an error overlay — so guard on
`api.getDisplayedRowCount()` if you only want grids that have data.

### Putting it together: a composite "entity card" column

Combine the three with a renderer for a self-contained rich column — registered once on your
wiki, then used by `type` from any grid:

```javascript
mw.hook( 'ext.aggrid.register' ).add( ( reg ) => {
    reg.columnTypes.entityCard = {
        // Thumbnail + manufacturer eyebrow + name, all in one cell.
        cellRenderer: ( p ) => {
            const card = document.createElement( 'div' );
            card.className = 'entity-card';
            const eyebrow = document.createElement( 'span' );
            eyebrow.className = 'entity-card__mfr';
            eyebrow.textContent = p.data.manufacturer || '';
            const title = document.createElement( 'span' );
            title.className = 'entity-card__name';
            title.textContent = p.data.name || '';
            card.append( eyebrow, title );
            return card;
        },
        valueFormatter: ( p ) => p.data.name,        // sort / export on the name
        filter: 'aggridSet',
        filterValueGetter: ( p ) => p.data.manufacturer, // but filter on the manufacturer
        filterParams: {
            itemRenderer: ( { label } ) => {
                const el = document.createElement( 'span' );
                el.className = 'entity-card__brand';
                el.textContent = label;
                return el;
            }
        }
    };
} );
```

From Lua, the column carries the fields the renderer and getter read:

```lua
mw.ext.aggrid.render{
    columnDefs = {
        { field = 'name', headerName = 'Ship', type = 'entityCard' },
    },
    rowData = {
        { name = 'Aurora MR', manufacturer = 'RSI' },
        { name = 'Constellation', manufacturer = 'RSI' },
        { name = '300i', manufacturer = 'Origin' },
    },
}
```

The grid sorts on the ship name, while the set filter lists `Origin` and `RSI` with your
brand markup — no extension code specific to your wiki. Because of the `filterValueGetter`,
quick search matches the manufacturer facet; add a `getQuickFilterText` returning
`p.data.name` to the type if you want it on the name instead.
