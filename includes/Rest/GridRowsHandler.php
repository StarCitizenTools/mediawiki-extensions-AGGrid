<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Rest;

use MediaWiki\Extension\AGGrid\DataSource\DataSource;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * GET /aggrid/v0/grid/{pageid}/{rev}/{index}/rows
 *
 * Serves a stored grid's rows. The {rev} segment pins the cache entry to the
 * page revision (an edit yields a new URL); the stored rows are the current
 * version.
 */
class GridRowsHandler extends SimpleHandler {

	public function __construct(
		private readonly DataSource $dataSource,
		private readonly PermissionManager $permissionManager,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory
	) {
	}

	/**
	 * @param int $pageid
	 * @param int $rev
	 * @param int $index
	 * @return \MediaWiki\Rest\Response
	 */
	public function run( int $pageid, int $rev, int $index ) {
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

		$rows = $this->dataSource->getRows( $pageid, $index );
		if ( $rows === null ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-nonexistent-title', [ $title->getPrefixedText() ] ),
				404
			);
		}

		$response = $this->getResponseFactory()->createJson( [ 'rows' => $rows ] );

		// Inline rows are a pure function of (page, revision), and the revision is in the
		// URL, so the response is genuinely immutable — an edit yields a new rev and a new
		// URL. Cache for a year (like a content-addressed asset), but only publicly when an
		// anonymous reader could fetch this page.
		$anonCanRead = $this->permissionManager->userCan(
			'read', $this->userFactory->newAnonymous(), $title
		);
		$response->setHeader(
			'Cache-Control',
			$anonCanRead ? 'public, max-age=31536000, immutable' : 'private, max-age=0'
		);
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
			'rev' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
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
