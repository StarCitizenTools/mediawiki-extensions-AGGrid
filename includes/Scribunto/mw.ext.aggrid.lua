local aggrid = {}
local php

function aggrid.setupInterface()
	-- Boilerplate
	aggrid.setupInterface = nil
	php = mw_interface
	mw_interface = nil

	-- Register this library in the "mw" global
	mw = mw or {}
	mw.ext = mw.ext or {}
	mw.ext.aggrid = aggrid

	package.loaded['mw.ext.aggrid'] = aggrid
end

--- Render an AG Grid from a gridOptions table.
---
--- Two modes:
---  * Inline: supply `columnDefs` and `rowData` directly.
---  * Backend source: supply a `source` descriptor and the grid is built from a
---    stored query (rows fetched on demand). When `source` is present, any author
---    `columnDefs`/`rowData` are ignored — columns are auto-derived from the
---    source's datatypes. `source.type` selects the backend ('smw' or 'bucket');
---    each requires its extension to be installed.
---
--- SMW source (`type = 'smw'`) fields:
---      * query     string|table  @query condition string, or a sequence of
---                                 fragments joined with spaces, e.g.
---                                 { '[[Category:City]]', '[[Population::>1000]]' }
---      * printouts table   @sequence of properties; each entry is a plain string
---                          'Population', a 'Property=Label' string, or a table
---                          { prop = 'Has population', label = 'Pop',
---                            filterProp = 'Has continent' }. ≥1 required.
---                          filterProp declares a filter facet: the column's filter
---                          lists and matches that property's values while the column
---                          displays and sorts on `prop`.
---      * mainlabel string|nil  @subject column header; '-' suppresses the subject
---                              column. Defaults to a 'Page' column.
---
--- Bucket source (`type = 'bucket'`) fields:
---      * bucket    string  @primary bucket name. Required.
---      * fields    table   @sequence of columns; each entry is a plain field name
---                          'value', or a table { field = 'value', label = 'Value',
---                          type = ..., cellRendererParams = ..., format = ...,
---                          filter = false }. A joined field is qualified
---                          'skill.category'. ≥1 required. (page_name is a normal
---                          Page field — list it to show a page-link column.)
---      * join      table|nil  @sequence of joins; each is
---                          { bucket = 'skill', on = { 'item.skill_required',
---                          'skill.skill_name' } }.
---      * where     table|nil  @base scope: a list of { field, operator, value }
---                          conditions ANDed together. Operators (strings):
---                          '=' '!=' '>=' '<=' '>' '<' (Bucket has no substring match).
---      * orderBy   table|nil  @default sort { field = 'value', direction = 'DESC' };
---                          the field must be one of `fields`.
---    Bucket columns get a set filter (page/text/boolean) or a number filter
---    (integer/double); there is no text 'contains' filter and no quick search,
---    because Bucket's query language has no LIKE operator.
---
--- The extension also understands one non-AG-Grid gridOption:
---  * quickSearch boolean|table @opt-in quick-search box above the grid rows,
---                 wired to AG Grid's quick filter. `true` enables it with an
---                 i18n placeholder and a 200 ms debounce; a table overrides:
---                 { placeholder = 'Find ships…', debounceMs = 300 }
---                 (debounceMs is capped at 5000).
---                 Works on inline grids and SMW `source` grids; ignored on Bucket
---                 `source` grids (no server-side substring search).
---
--- @param gridOptions table @AG Grid gridOptions; inline (columnDefs + rowData) or { source = ... }
--- @return string @The rendered grid placeholder wikitext
function aggrid.render( gridOptions )
	return php.render( gridOptions )
end

--- Resolve a thumbnail for a rich image cell. Resolution happens server-side.
---
--- @param file string @File title, e.g. "File:Aurora.jpg"
--- @param width number @Thumbnail width in px
--- @param opts table|nil @{ link = <page title>, alt = <string> }
--- @return table|nil @{ src, width, alt, href? } or nil if the file is missing
function aggrid.thumb( file, width, opts )
	return php.thumb( file, width, opts or {} )
end

--- Build a { text, href } link cell value for a page title.
---
--- @param target string @Page title to link to
--- @param text string|nil @Display text; defaults to the title's text
--- @return table|nil @{ text, href } or nil if the title cannot be parsed
function aggrid.link( target, text )
	local title = mw.title.new( target )
	if not title then
		return nil
	end
	return { text = text or title.text, href = title:localUrl() }
end

--- Build a { links = {...} } cell value from a list of page titles.
---
--- @param targets table @Sequence of page titles
--- @return table @{ links = { {text,href}, ... } }
function aggrid.linkList( targets )
	local links = {}
	for _, target in ipairs( targets ) do
		local link = aggrid.link( target )
		if link then
			links[ #links + 1 ] = link
		end
	end
	return { links = links }
end

-- Shallow-copy a column spec, preset its renderer type, and map `header` to AG Grid's
-- `headerName` so authors can use the shorter key. Note: the spec's `type` is reserved
-- and always overwritten with the helper's renderer type; pass `headerName` directly if
-- you prefer it over the `header` alias.
local function column( spec, columnType )
	local colDef = {}
	for key, value in pairs( spec ) do
		colDef[ key ] = value
	end
	colDef.type = columnType
	if colDef.header ~= nil then
		colDef.headerName = colDef.header
		colDef.header = nil
	end
	return colDef
end

--- Column def for a link column (cells built with aggrid.link).
--- @param spec table @colDef keys, e.g. { field = 'name', header = 'Name' }
--- @return table
function aggrid.linkColumn( spec )
	return column( spec, 'aggridLink' )
end

--- Column def for a thumbnail column (cells built with aggrid.thumb).
--- @param spec table
--- @return table
function aggrid.imageColumn( spec )
	return column( spec, 'aggridImage' )
end

--- Column def for a link-list column (cells built with aggrid.linkList).
--- @param spec table
--- @return table
function aggrid.linkListColumn( spec )
	return column( spec, 'aggridLinkList' )
end

return aggrid
