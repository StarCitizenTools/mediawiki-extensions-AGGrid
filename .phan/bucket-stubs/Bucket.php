<?php
// phpcs:ignoreFile
// Phan stubs for the optional Bucket extension. Loaded ONLY when Bucket is not installed
// (e.g. CI, where Bucket is not a Composer package and cannot be cloned). They mirror the
// minimal signatures the Bucket data source references so Phan can resolve the classes.
//
// This lives outside the conventional `.phan/stubs/` directory on purpose: the base
// mediawiki-phan-config auto-scans `.phan/stubs/`, which would define these classes a
// second time and collide with the real Bucket source on a dev box that has it installed.
// `.phan/config.php` adds this directory only on the no-Bucket branch.

namespace MediaWiki\Extension\Bucket;

class Bucket {
	public static function runSelect( array $userInput, ?\MediaWiki\Title\Title $title ): array {
		return [];
	}
}

class BucketQuery {
	public function __construct( array $data ) {
	}

	public function getSelectQueryBuilder(): \Wikimedia\Rdbms\SelectQueryBuilder {
		throw new \LogicException( 'stub' );
	}
}

class BucketDatabase {
	public static function getDB(): \Wikimedia\Rdbms\IMaintainableDatabase {
		throw new \LogicException( 'stub' );
	}
}

class BucketException extends \LogicException {
}
