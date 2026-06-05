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
--- @param gridOptions table @AG Grid gridOptions (columnDefs and rowData required)
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
