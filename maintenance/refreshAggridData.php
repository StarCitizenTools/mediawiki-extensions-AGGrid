<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Maintenance;

use MediaWiki\Category\Category;
use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;

// @codeCoverageIgnoreStart
$IP = getenv( 'MW_INSTALL_PATH' );
if ( $IP === false ) {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";
// @codeCoverageIgnoreEnd

/**
 * Rebuilds the AGGrid derived stores (aggrid_data / aggrid_source) for every page
 * that renders an AG Grid, from each page's current parse.
 *
 * The stores are normally written only on an edit/links-update. Run this after a
 * bulk template migration that introduced grids on many pages without editing each
 * consumer (the case in issue #31), or to recover from store drift. Lazy population
 * on REST cache-miss already self-heals individual pages on first view; this is the
 * bulk pre-warm / escape hatch.
 *
 *   php maintenance/run.php extensions/AGGrid/maintenance/refreshAggridData.php
 *   php maintenance/run.php extensions/AGGrid/maintenance/refreshAggridData.php --force-reparse
 */
class RefreshAggridData extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->requireExtension( 'AGGrid' );
		$this->addDescription(
			'Rebuild the AGGrid derived stores (aggrid_data / aggrid_source) for every ' .
			'page that renders an AG Grid, from each page\'s current parse.'
		);
		$this->addOption(
			'force-reparse',
			'Bypass the parser cache and parse each page fresh (slower; for store drift recovery).'
		);
		$this->setBatchSize( 50 );
	}

	public function execute() {
		/** @var \MediaWiki\Extension\AGGrid\Service\GridDataPopulator $populator */
		$populator = $this->getServiceContainer()->getService( 'AGGrid.GridDataPopulator' );
		$forceReparse = $this->hasOption( 'force-reparse' );

		$category = $this->resolveTrackingCategory();
		if ( $category === null ) {
			$this->output( "AGGrid tracking category is disabled; nothing to refresh.\n" );
			return;
		}

		$batchSize = $this->getBatchSize();
		$scanned = 0;
		$refreshed = 0;
		$inlineGrids = 0;
		$sourceGrids = 0;

		// Category::getMembers() runs a single query for the members; Title objects are
		// built lazily as we iterate, but the row set is fetched up front. We use the
		// schema-portable Category API deliberately: categorylinks is mid-migration to
		// linktarget across the supported MW versions (1.43+), and its sortkey-offset
		// paging can't be driven from the returned Titles — so a hand-rolled batched
		// query would couple this script to the in-flight schema. The member set is just
		// page rows; the expensive part — the per-page rebuild + primary-DB write — is
		// what we pace below with waitForReplication().
		foreach ( $category->getMembers() as $title ) {
			if ( !$title->canExist() ) {
				continue;
			}
			$pageId = $title->getArticleID();
			if ( !$pageId ) {
				continue;
			}

			$scanned++;
			$extracted = $populator->rebuild( $pageId, $forceReparse );
			if ( $extracted !== null ) {
				$refreshed++;
				$inlineGrids += count( $extracted['inline'] );
				$sourceGrids += count( $extracted['source'] );
			}

			if ( $scanned % $batchSize === 0 ) {
				$this->output( "...scanned $scanned page(s)\n" );
				$this->waitForReplication();
			}
		}

		$this->output(
			"Done. Scanned $scanned page(s), refreshed $refreshed: " .
			"$inlineGrids inline grid(s), $sourceGrids backend grid(s).\n"
		);
	}

	/**
	 * Resolve the tracking category's content-language title, or null when the
	 * `aggrid-tracking-category` message is disabled ('-') on this wiki.
	 */
	private function resolveTrackingCategory(): ?Category {
		$msg = wfMessage( 'aggrid-tracking-category' )->inContentLanguage();
		if ( $msg->isDisabled() ) {
			return null;
		}
		$title = Title::makeTitleSafe( NS_CATEGORY, $msg->text() );
		if ( $title === null ) {
			return null;
		}
		return Category::newFromTitle( $title );
	}
}

// @codeCoverageIgnoreStart
$maintClass = RefreshAggridData::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
