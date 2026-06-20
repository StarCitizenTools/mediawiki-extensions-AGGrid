<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Rest;

use MediaWiki\Extension\AGGrid\DataSource\CachePolicyResolver;
use MediaWiki\Extension\AGGrid\Service\GridDataPopulator;
use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * GET /aggrid/v0/grid/{pageid}/{token}/{index}/rows
 *
 * Serves a stored grid's rows. The {token} segment is the content hash (sha1)
 * of the rows; when rows change the hash changes, yielding a new URL and
 * invalidating any cached response.
 */
class GridRowsHandler extends SimpleHandler {

	public function __construct(
		private readonly GridDataStore $store,
		private readonly PermissionManager $permissionManager,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly GridDataPopulator $populator,
		private readonly CachePolicyResolver $cachePolicyResolver
	) {
	}

	/**
	 * @param int $pageid
	 * @param string $token The rows' content hash (sha1); compared against the stored/derived hash.
	 * @param int $index
	 * @return \MediaWiki\Rest\Response
	 */
	public function run( int $pageid, string $token, int $index ) {
		$title = $this->titleFactory->newFromID( $pageid );
		if ( !$title ) {
			throw new LocalizedHttpException( new MessageValue( 'rest-nonexistent-title' ), 404 );
		}
		if ( !$this->getAuthority()->probablyCan( 'read', $title ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-permission-denied-title', [ $title->getPrefixedText() ] ),
				403
			);
		}

		$result = $this->store->getRowsAndHash( $pageid, $index );

		// Re-derive from the CURRENT parse when the store is absent (issue #31) OR stale relative
		// to the URL token. The stale case is load-bearing for SMW: ParserCachePurgeJob re-parses
		// the host page to a new hash but never runs LinksUpdateComplete, so aggrid_data is not
		// rebuilt. populateFromParse reads the current (freshly re-parsed) ParserOutput — fresh
		// rows + fresh hash — and schedules a deferred aggrid_data rebuild, so later requests match
		// the store directly. Also covers replica lag and pre-deploy HTML carrying an old token.
		if ( $result === null || $result['hash'] !== $token ) {
			$extracted = $this->populator->populateFromParse( $pageid );
			// array_key_exists (not truthiness) so a real zero-row grid serves [] rather than 404ing.
			if ( $extracted !== null && array_key_exists( $index, $extracted['inline'] ) ) {
				$result = $extracted['inline'][$index];
			}
		}
		if ( $result === null ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-nonexistent-title', [ $title->getPrefixedText() ] ),
				404
			);
		}

		$response = $this->getResponseFactory()->createJson( [ 'rows' => $result['rows'] ] );

		$anonCanRead = $this->permissionManager->userCan(
			'read', $this->userFactory->newAnonymous(), $title
		);
		if ( !$anonCanRead ) {
			$response->setHeader( 'Cache-Control', 'private, max-age=0' );
		} elseif ( $result['hash'] === $token ) {
			// The URL hash equals the served rows' hash: this URL only ever appears in HTML that
			// materialized exactly these rows, so it is safe to cache at the anon/CDN edge.
			$policy = $this->cachePolicyResolver->forSource( 'inline' );
			$response->setHeader( 'Cache-Control', $policy->toPublicCacheControl() );
		} else {
			// Genuinely stale token (pre-deploy revid URL, or a client on outdated HTML even after
			// re-deriving the current parse): serve the freshest rows but never cache them, so
			// nothing is frozen and the URL self-upgrades once the client's HTML catches up.
			$response->setHeader( 'Cache-Control', 'no-store' );
		}
		return $response;
	}

	/**
	 * @inheritDoc
	 */
	public function getParamSettings() {
		return [
			'pageid' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'token' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'index' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
