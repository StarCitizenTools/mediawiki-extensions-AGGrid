<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Service;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Page\ParserOutputAccess;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\ParserOutput;
use Wikimedia\Rdbms\ReadOnlyMode;

/**
 * Rebuilds the derived grid stores (aggrid_data / aggrid_source) for a page from
 * its current parse, decoupled from LinksUpdateComplete.
 *
 * The stores are otherwise written only on an edit/links-update (see
 * {@see \MediaWiki\Extension\AGGrid\Hooks\LinksUpdateHandler}). A grid introduced
 * via a transcluded template — with no per-page edit of the consumer — therefore
 * leaves the stores empty, and the REST rows/page/values endpoints 404
 * ("could not load the grid data"). The REST handlers call
 * {@see populateFromParse()} on a store miss to self-heal: the requested grid is
 * served in-band from the freshly-read ParserOutput (the client does not retry a
 * 404), and the stores are filled by a deferred (post-send) write so later
 * requests skip the parse entirely.
 *
 * The maintenance/refreshAggridData.php script calls {@see rebuild()} to flush
 * synchronously for bulk pre-warming / drift recovery after a template migration.
 *
 * Not final so REST handler unit tests can mock it (mirroring SourceSpecStore).
 */
class GridDataPopulator {

	public function __construct(
		private readonly WikiPageFactory $wikiPageFactory,
		private readonly ParserOutputAccess $parserOutputAccess,
		private readonly GridDataStore $inlineStore,
		private readonly SourceSpecStore $sourceStore,
		private readonly ReadOnlyMode $readOnlyMode
	) {
	}

	/**
	 * Serve-and-schedule path for the REST handlers.
	 *
	 * Reads the page's current parse and returns the queued grids so the caller can
	 * serve the requested grid in THIS response — the client mounts an error overlay
	 * on a 404 and never retries within the page view, so the first request must
	 * itself succeed. The store write is deferred to POSTSEND (so it never blocks the
	 * GET) and re-derived at flush time rather than persisting the rows read here, so it
	 * does not overwrite a concurrent edit's LinksUpdate with stale rows. It also re-checks
	 * page existence at flush time (extract() returns null for a missing page), so a page
	 * deleted in the meantime is generally not resurrected — and any rows that do slip
	 * through a lagged replica read are unreachable anyway, since no title resolves to a
	 * deleted page.
	 *
	 * @param int $pageId
	 * @return array{inline: array<int,array>, source: array<int,array>}|null
	 *   The queued grids keyed by grid index, or null when the page has no obtainable
	 *   ParserOutput (missing page or failed parse).
	 */
	public function populateFromParse( int $pageId ): ?array {
		$extracted = $this->extract( $pageId );
		if ( $extracted === null ) {
			return null;
		}
		DeferredUpdates::addCallableUpdate( function () use ( $pageId ) {
			$this->rebuild( $pageId );
		} );
		return $extracted;
	}

	/**
	 * Synchronous (re)build for the maintenance script: read the page's current
	 * parse and flush both stores in-process.
	 *
	 * @param int $pageId
	 * @param bool $forceReparse Bypass the parser cache and parse the page fresh.
	 * @return array{inline: array<int,array>, source: array<int,array>}|null
	 *   The queued grids (for tallying), or null when the page has no obtainable
	 *   ParserOutput.
	 */
	public function rebuild( int $pageId, bool $forceReparse = false ): ?array {
		$extracted = $this->extract( $pageId, $forceReparse );
		if ( $extracted === null ) {
			return null;
		}
		$this->flush( $pageId, $extracted );
		return $extracted;
	}

	/**
	 * Read the page's current canonical ParserOutput and extract the queued grids.
	 *
	 * Prefers the parser cache — a pure read, no parse — so the common REST path
	 * (fired right after a page view warmed the cache) never parses. Only on a true
	 * cache miss does it parse. The fallback parse is allowed to write the canonical
	 * parser cache (OPT_NO_UPDATE_CACHE is deliberately NOT set), warming the same entry
	 * a normal view uses, so the next view and the next REST hit are both cache hits and
	 * the reparse cost stays bounded to one page-view-equivalent per parser-cache miss.
	 *
	 * The $options bitfield is passed as an int for MediaWiki 1.43 compatibility: the
	 * array form and OPT_POOL_COUNTER are 1.45+, and the older int OPT_FOR_ARTICLE_VIEW
	 * is deprecated (removable on master), so there is no single PoolCounter option
	 * valid across all supported cores. We therefore forgo PoolCounter stampede
	 * protection on this rare cold-cache fallback; the common path is the
	 * getCachedParserOutput hit above, and a normal page view warms the cache (under its
	 * own ArticleView pool work) before the REST hit, so the fallback rarely fires.
	 *
	 * @param int $pageId
	 * @param bool $forceReparse
	 * @return array{inline: array<int,array>, source: array<int,array>}|null
	 */
	private function extract( int $pageId, bool $forceReparse = false ): ?array {
		$wikiPage = $this->wikiPageFactory->newFromID( $pageId );
		if ( !$wikiPage || !$wikiPage->exists() ) {
			return null;
		}
		$pageRecord = $wikiPage->toPageRecord();
		$parserOptions = $wikiPage->makeParserOptions( 'canonical' );

		$output = null;
		if ( !$forceReparse ) {
			$output = $this->parserOutputAccess->getCachedParserOutput( $pageRecord, $parserOptions );
		}
		if ( !$output instanceof ParserOutput ) {
			// 0 = check the cache then parse-and-cache on miss; OPT_NO_CHECK_CACHE forces
			// a fresh parse for --force-reparse. Both are ints (1.43-compatible).
			$options = $forceReparse ? ParserOutputAccess::OPT_NO_CHECK_CACHE : 0;
			$status = $this->parserOutputAccess->getParserOutput(
				$pageRecord, $parserOptions, null, $options
			);
			$output = $status->getValue();
		}
		if ( !$output instanceof ParserOutput ) {
			return null;
		}

		return [
			'inline' => GridRenderer::extractInlineGrids( $output ),
			'source' => GridRenderer::extractSourceGrids( $output ),
		];
	}

	/**
	 * Write both derived stores for the page. Read-only guarded; both replaceForPage
	 * implementations no-op when the stored hashes already match, so an idempotent
	 * re-flush performs no write.
	 *
	 * @param int $pageId
	 * @param array{inline: array<int,array>, source: array<int,array>} $extracted
	 */
	private function flush( int $pageId, array $extracted ): void {
		if ( $this->readOnlyMode->isReadOnly() ) {
			return;
		}
		$this->inlineStore->replaceForPage( $pageId, $extracted['inline'] );
		$this->sourceStore->replaceForPage( $pageId, $extracted['source'] );
	}
}
