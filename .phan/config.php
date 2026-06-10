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

// Bucket is an optional (soft) dependency — the Bucket data source references its
// Bucket/BucketQuery/BucketDatabase classes. Analyse the real source when present; fall
// back to local stubs when it is not installed (e.g. CI — Bucket is not a Composer
// package, so unlike SMW it cannot be pulled in for the Phan job).
$bucketDir = __DIR__ . '/../../../extensions/Bucket';
if ( is_dir( $bucketDir ) ) {
	$cfg['directory_list'][] = $bucketDir;
	$cfg['exclude_analysis_directory_list'][] = $bucketDir;
} else {
	// Bucket-stubs lives outside .phan/stubs/ (which the base config auto-scans) so it is
	// only ever loaded here, never alongside a real Bucket install.
	$cfg['directory_list'][] = __DIR__ . '/bucket-stubs';
	$cfg['exclude_analysis_directory_list'][] = __DIR__ . '/bucket-stubs';
}

return $cfg;
