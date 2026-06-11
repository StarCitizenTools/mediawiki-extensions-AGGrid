<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\DataSource;

/**
 * The backend-agnostic comparison operators AG Grid's number/date filters express.
 *
 * {@see AbstractFilterModelWalker::operatorFor()} maps the AG Grid filter `type` string
 * to one of these once; each backend's filter translator then maps the case to its own
 * token (SMW's SMW_CMP_* constants, Bucket's '=' / '!=' / '>' … strings).
 */
enum FilterOperator {
	case Equals;
	case NotEqual;
	case GreaterThan;
	case GreaterThanOrEqual;
	case LessThan;
	case LessThanOrEqual;
}
