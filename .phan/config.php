<?php

$cfg = require __DIR__ . '/../vendor/mediawiki/mediawiki-phan-config/src/config.php';

// Scribunto is a hard dependency — include it for type resolution when available.
$scribuntoDir = __DIR__ . '/../../../extensions/Scribunto';
if ( is_dir( $scribuntoDir ) ) {
	$cfg['directory_list'][] = $scribuntoDir;
	$cfg['exclude_analysis_directory_list'][] = $scribuntoDir;
}

// Semantic MediaWiki is an optional (soft) dependency — the SMW data source
// references its classes/constants. Include it for type resolution when present
// (installed via Composer into extensions/ by composer/installers).
$smwDir = __DIR__ . '/../../../extensions/SemanticMediaWiki';
if ( is_dir( $smwDir ) ) {
	$cfg['directory_list'][] = $smwDir;
	$cfg['exclude_analysis_directory_list'][] = $smwDir;
}

return $cfg;
