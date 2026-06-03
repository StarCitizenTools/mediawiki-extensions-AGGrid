<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Hooks;

use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Extension\AGGrid\Service\GridRenderer;
use MediaWiki\Hook\LinksUpdateCompleteHook;

/**
 * Flushes grids queued during the parse into the aggrid_data table.
 */
final class LinksUpdateHandler implements LinksUpdateCompleteHook {

	public function __construct(
		private readonly GridDataStore $store
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

		// Grids are stored one-per-key (GridRenderer::EXT_DATA_KEY . $index),
		// contiguous from 0; probe until the first gap.
		$parserOutput = $linksUpdate->getParserOutput();
		$grids = [];
		for ( $index = 0; ; $index++ ) {
			$grid = $parserOutput->getExtensionData( GridRenderer::EXT_DATA_KEY . $index );
			if ( $grid === null ) {
				break;
			}
			$grids[$index] = $grid;
		}

		// Always call (even with no grids) so removing a grid clears stale rows.
		$this->store->replaceForPage( $pageId, $grids );
	}
}
