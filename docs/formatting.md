# Formatting numbers and dates

To format a column's display text, set a serialisable `format` spec on the column. The underlying
value stays a number or date, so sort, filter, and quick-search keep operating on the real value
— only the displayed text changes.

(AG Grid normally formats with a `valueFormatter` function, but functions can't cross from Lua
into JSON — see [the no-functions limit](authoring-grids.md#one-limit-no-functions). The `format`
spec is the serialisable alternative.)

```lua
columnDefs = {
    { field = 'length', headerName = 'Length',
      format = { style = 'number', useGrouping = true, decimals = 0, suffix = ' m' } },
    { field = 'released', headerName = 'Released',
      format = { style = 'date', dateStyle = 'medium' } },
}
-- 1234567 shows as "1,234,567 m" and still sorts numerically
```

## `style = 'number'`

| Key | Default | Meaning |
| --- | --- | --- |
| `useGrouping` | `true` | Thousands separators. |
| `decimals` | — | Fixed number of fraction digits. |
| `prefix` | — | Literal text before the number. |
| `suffix` | — | Literal text after the number. |
| `locale` | viewer's | Locale for grouping/decimal symbols. |

## `style = 'date'`

| Key | Meaning |
| --- | --- |
| `dateStyle` | `'short'`, `'medium'`, `'long'`, or `'full'`. |
| `options` | A full [`Intl.DateTimeFormat`](https://developer.mozilla.org/docs/Web/JavaScript/Reference/Global_Objects/Intl/DateTimeFormat) options table. Use instead of `dateStyle` for finer control. |
| `locale` | Locale for date wording. Defaults to the viewer's. |

Dates must be ISO-8601 (e.g. `2024-03-09`); any other string passes through unchanged.

## Notes

- Omit `locale` to follow the **viewer's** own locale for grouping and date wording. A fixed
  `prefix`/`suffix` always stays literal.
- Non-numeric or empty values pass through unchanged.
- `format` behaves identically on inline grids and on [backend source grids](data-sources.md).
- A Semantic MediaWiki **Quantity** column already arrives formatted with its unit (e.g. `"27 kg"`),
  so `format` is a no-op there. It only takes effect where the source value is a bare number.

## See also

- [Authoring grids](authoring-grids.md) — the inline `gridOptions` model
- [Rich cells](rich-cells.md) — links, thumbnails, and link lists
- [Backend source grids](data-sources.md) — where `format` also applies
