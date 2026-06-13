<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Rest;

use MediaWiki\Extension\AGGrid\DataSource\BackendRegistry;
use MediaWiki\Extension\AGGrid\Service\GridDataPopulator;
use MediaWiki\Extension\AGGrid\Service\SourceSpecStore;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Rest\LocalizedHttpException;
use MediaWiki\Rest\Response;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\UserFactory;
use RuntimeException;
use Wikimedia\Message\MessageValue;
use Wikimedia\ParamValidator\ParamValidator;

/**
 * Serves the distinct column values for a backend-source grid column,
 * used to populate the AG Grid server-side Set Filter.
 *
 * Route: GET /aggrid/v0/grid/{pageid}/{rev}/{index}/values?column=<colId>
 *
 * Response body: { values: [ { key, label }, … ], partial: bool }
 *
 * Auth and cache policy mirror {@see GridPageHandler}.
 */
class GridValuesHandler extends SimpleHandler {

	use BackendHandlerTrait;

	public function __construct(
		private readonly BackendRegistry $registry,
		private readonly SourceSpecStore $specStore,
		private readonly PermissionManager $permissionManager,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory,
		private readonly GridDataPopulator $populator
	) {
	}

	/**
	 * @param int $pageid Wiki page ID that owns the grid.
	 * @param int $rev Revision identifier (carried for cache keying; not used for lookup).
	 * @param int $index Zero-based index of the grid on the page.
	 * @return Response
	 */
	public function run( int $pageid, int $rev, int $index ): Response {
		$title = $this->resolveTitleOrThrow( $pageid );
		$this->assertCanRead( $title );

		$source = $this->resolveSource( $pageid, $index, $title );
		$dataSource = $this->resolveDataSource( $source, $title );

		$params = $this->getValidatedParams();
		$column = (string)$params['column'];

		try {
			// Pass the resolved spec so the set-filter values are served from it directly
			// rather than re-reading the store (issue #31 self-heal — see GridPageHandler).
			$result = $dataSource->getColumnValues( $pageid, $index, $column, $source['spec'] );
		} catch ( RuntimeException ) {
			// Do not leak the backend exception message or stack to the client.
			throw new LocalizedHttpException( new MessageValue( 'rest-bad-request' ), 400 );
		}

		$response = $this->getResponseFactory()->createJson( [
			'values' => $result['values'],
			'partial' => $result['partial'],
		] );

		$this->applyCachePolicy( $response, $title, $dataSource->getCachePolicy() );
		return $response;
	}

	/** @inheritDoc */
	public function getParamSettings(): array {
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
			'column' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
		];
	}
}
