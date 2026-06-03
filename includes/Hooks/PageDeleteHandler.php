<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Hooks;

use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;

/**
 * Drops a page's stored grid rows when the page is deleted.
 */
final class PageDeleteHandler implements PageDeleteCompleteHook {

	public function __construct(
		private readonly GridDataStore $store
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		$this->store->deleteForPage( $pageID );
	}
}
