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

return aggrid
