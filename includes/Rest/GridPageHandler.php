<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Rest;

use MediaWiki\Extension\AGGrid\DataSource\DataSourceRegistry;
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
 * Serves a single offset page of a backend-source grid for AG Grid's server-side row model.
 *
 * Mirrors {@see GridRowsHandler} (the inline endpoint): resolves the stored source spec by
 * page/grid handle, dispatches to the matching backend data source via the registry, and returns
 * { rows, total } with a cache header gated on anonymous readability.
 */
class GridPageHandler extends SimpleHandler {

	use BackendHandlerTrait;

	private const SIZE_MIN = 1;
	private const SIZE_MAX = 200;
	private const SIZE_DEFAULT = 50;

	public function __construct(
		private readonly DataSourceRegistry $registry,
		private readonly SourceSpecStore $specStore,
		private readonly PermissionManager $permissionManager,
		private readonly TitleFactory $titleFactory,
		private readonly UserFactory $userFactory
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

		$offset = max( 0, (int)( $params['offset'] ?? 0 ) );

		$sortModel = $this->decodeModel( (string)( $params['sort'] ?? '' ) );
		$filterModel = $this->decodeModel( (string)( $params['filter'] ?? '' ) );

		$size = (int)( $params['size'] ?? self::SIZE_DEFAULT );
		$size = max( self::SIZE_MIN, min( self::SIZE_MAX, $size ) );

		$quickSearch = (string)( $params['q'] ?? '' );

		try {
			$page = $dataSource->getPage(
				$pageid, $index, $offset, $sortModel, $filterModel, $size, $quickSearch
			);
		} catch ( RuntimeException ) {
			// Do not leak the backend exception message or stack to the client.
			throw new LocalizedHttpException( new MessageValue( 'rest-bad-request' ), 400 );
		}

		$response = $this->getResponseFactory()->createJson( [
			'rows' => $page->getRows(),
			'total' => $page->getTotal(),
		] );

		$this->applyCachePolicy( $response, $title, $dataSource->getCachePolicy() );
		return $response;
	}

	/**
	 * Decode a JSON-encoded AG Grid sort/filter model, falling back to an empty array.
	 */
	private function decodeModel( string $json ): array {
		if ( $json === '' ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : [];
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
			'offset' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 0,
			],
			'sort' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'filter' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
			],
			'q' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => '',
			],
			'size' => [
				self::PARAM_SOURCE => 'query',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => self::SIZE_DEFAULT,
			],
		];
	}
}
