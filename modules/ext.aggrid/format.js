// Declarative value formatters. A column carries a serializable `format` spec
// (colDef.format) authored in Lua; this turns it into an AG Grid valueFormatter.
// The raw cell value stays primitive so native sort/filter/CSV export operate on
// the real number/date — only the displayed string is transformed.

function isAbsent( value ) {
	return value === null || value === undefined || value === '';
}

// Build a number valueFormatter from a spec: { useGrouping?, decimals?, locale?,
// prefix?, suffix? }. Grouping defaults on; decimals pins fraction digits.
function numberFormatter( spec ) {
	const opts = { useGrouping: spec.useGrouping !== false };
	if ( typeof spec.decimals === 'number' ) {
		opts.minimumFractionDigits = spec.decimals;
		opts.maximumFractionDigits = spec.decimals;
	}
	const nf = new Intl.NumberFormat( spec.locale || undefined, opts );
	const prefix = typeof spec.prefix === 'string' ? spec.prefix : '';
	const suffix = typeof spec.suffix === 'string' ? spec.suffix : '';
	return ( params ) => {
		if ( isAbsent( params.value ) ) {
			return '';
		}
		const n = Number( params.value );
		if ( Number.isNaN( n ) ) {
			return String( params.value );
		}
		return prefix + nf.format( n ) + suffix;
	};
}

// Match ISO date-only strings (YYYY-MM-DD) so they are parsed as local dates
// rather than UTC midnight (which would shift the displayed date in western timezones).
const DATE_ONLY_RE = /^(\d{4})-(\d{2})-(\d{2})$/;

function parseDate( value ) {
	if ( value instanceof Date ) {
		return value;
	}
	const m = typeof value === 'string' && DATE_ONLY_RE.exec( value );
	if ( m ) {
		// new Date(year, month-1, day) — local time, no UTC shift.
		return new Date( Number( m[ 1 ] ), Number( m[ 2 ] ) - 1, Number( m[ 3 ] ) );
	}
	return new Date( value );
}

// Build a date valueFormatter from a spec: { dateStyle?, locale?, options? }. A full
// `options` object (Intl.DateTimeFormat options) overrides dateStyle for datetime control.
function dateFormatter( spec ) {
	const options = ( spec.options && typeof spec.options === 'object' ) ?
		spec.options : { dateStyle: spec.dateStyle || 'medium' };
	const df = new Intl.DateTimeFormat( spec.locale || undefined, options );
	return ( params ) => {
		if ( isAbsent( params.value ) ) {
			return '';
		}
		const d = parseDate( params.value );
		if ( Number.isNaN( d.getTime() ) ) {
			return String( params.value );
		}
		return df.format( d );
	};
}

/**
 * Build a valueFormatter from a `format` spec, or null if the spec is absent/unknown.
 *
 * @param {Object|null} spec
 * @return {Function|null} params -> string, or null.
 */
function makeFormatter( spec ) {
	if ( !spec || typeof spec !== 'object' ) {
		return null;
	}
	if ( spec.style === 'number' ) {
		return numberFormatter( spec );
	}
	if ( spec.style === 'date' ) {
		return dateFormatter( spec );
	}
	return null;
}

module.exports = { makeFormatter };
