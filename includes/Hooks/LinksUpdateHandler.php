<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Hooks;

use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Hook\LinksUpdateCompleteHook;

/**
 * Flushes grids queued during the parse into the aggrid_data and aggrid_source tables.
 */
final class LinksUpdateHandler implements LinksUpdateCompleteHook {

	public function __construct(
		private readonly GridDataStore $store,
		private readonly SourceSpecStore $sourceStore
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function onLinksUpdateComplete( $linksUpdate, $ticket ) {
		$pageId = $linksUpdate->getPageId();
		if ( !$pageId ) {
			return;
		}

		$parserOutput = $linksUpdate->getParserOutput();

		// Always call (even with no grids) so removing a grid clears stale rows. The
		// same extraction feeds GridDataPopulator's lazy/maintenance flush.
		$this->store->replaceForPage( $pageId, GridRenderer::extractInlineGrids( $parserOutput ) );

		// Flush backend query specs to aggrid_source; likewise clears on removal.
		$this->sourceStore->replaceForPage( $pageId, GridRenderer::extractSourceGrids( $parserOutput ) );
	}
}
