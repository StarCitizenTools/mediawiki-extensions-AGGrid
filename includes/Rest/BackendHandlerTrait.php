<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Rest;

use InvalidArgumentException;
use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\CachePolicy;
use MediaWiki\Extension\AGGrid\DataSource\DataSourceRegistry;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use Wikimedia\Message\MessageValue;

/**
 * Shared helpers for REST handlers that resolve a backend grid source.
 *
 * Both {@see GridPageHandler} and {@see GridValuesHandler} need to:
 *  1. Resolve a Title from a page ID (404 on miss).
 *  2. Assert the requester can read it (403).
 *  3. Load the stored source spec by (pageId, index) (404 on null).
 *  4. Resolve the BackendDataSource from the registry (404 on unknown type).
 *  5. Set the Cache-Control header based on anonymous readability.
 *
 * Consumers must expose $registry, $specStore, $permissionManager,
 * $titleFactory, and $userFactory as properties (injected via the constructor).
 * They must also implement getAuthority() and getResponseFactory()
 * (provided by {@see SimpleHandler}).
 *
 * @property DataSourceRegistry $registry
 * @property SourceSpecStore $specStore
 * @property PermissionManager $permissionManager
 * @property TitleFactory $titleFactory
 * @property UserFactory $userFactory
 * @method \MediaWiki\Permissions\Authority getAuthority()
 */
trait BackendHandlerTrait {

	/**
	 * Resolve a Title from a page ID, throwing 404 if it does not exist.
	 */
	private function resolveTitleOrThrow( int $pageid ): Title {
		/** @var TitleFactory $titleFactory */
		$title = $this->titleFactory->newFromID( $pageid );
		if ( !$title ) {
			throw new LocalizedHttpException( new MessageValue( 'rest-nonexistent-title' ), 404 );
		}
		return $title;
	}

	/**
	 * Assert the current requester has read access to $title, throwing 403 otherwise.
	 */
	private function assertCanRead( Title $title ): void {
		if ( !$this->getAuthority()->probablyCan( 'read', $title ) ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-permission-denied-title', [ $title->getPrefixedText() ] ),
				403
			);
		}
	}

	/**
	 * Load the stored source spec for (pageId, index), throwing 404 on null.
	 *
	 * @return array The spec array from SourceSpecStore::getSource().
	 */
	private function resolveSource( int $pageid, int $index, Title $title ): array {
		/** @var SourceSpecStore $specStore */
		$source = $this->specStore->getSource( $pageid, $index );
		if ( $source === null ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-nonexistent-title', [ $title->getPrefixedText() ] ),
				404
			);
		}
		return $source;
	}

	/**
	 * Resolve the BackendDataSource for the given spec, throwing 404 on unknown type.
	 */
	private function resolveDataSource( array $source, Title $title ): BackendDataSource {
		/** @var DataSourceRegistry $registry */
		try {
			return $this->registry->get( $source['source'] );
		} catch ( InvalidArgumentException ) {
			throw new LocalizedHttpException(
				new MessageValue( 'rest-nonexistent-title', [ $title->getPrefixedText() ] ),
				404
			);
		}
	}

	/**
	 * Set the Cache-Control header based on whether the anonymous user can read $title.
	 */
	private function applyCachePolicy( Response $response, Title $title, CachePolicy $policy ): void {
		/** @var PermissionManager $permissionManager */
		/** @var UserFactory $userFactory */
		$anonCanRead = $this->permissionManager->userCan(
			'read', $this->userFactory->newAnonymous(), $title
		);
		$response->setHeader(
			'Cache-Control',
			$anonCanRead ? 'public, max-age=' . $policy->getMaxAge() : 'private, max-age=0'
		);
	}
}
