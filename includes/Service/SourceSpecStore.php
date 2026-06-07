<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Service;

use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Reads and writes backend query specs in the aggrid_source table.
 */
class SourceSpecStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {
	}

	public function replaceForPage( int $pageId, array $grids ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$existing = [];
		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'ags_grid_index', 'ags_hash' ] )
			->from( 'aggrid_source' )
			->where( [ 'ags_page_id' => $pageId ] )
			->caller( __METHOD__ )->fetchResultSet();
		foreach ( $res as $row ) {
			$existing[(int)$row->ags_grid_index] = (string)$row->ags_hash;
		}

		$incoming = [];
		foreach ( $grids as $index => $grid ) {
			$incoming[(int)$index] = $grid['hash'];
		}
		ksort( $existing );
		ksort( $incoming );
		if ( $existing === $incoming ) {
			return;
		}

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'aggrid_source' )
			->where( [ 'ags_page_id' => $pageId ] )
			->caller( __METHOD__ )->execute();

		if ( !$grids ) {
			return;
		}

		$rows = [];
		foreach ( $grids as $index => $grid ) {
			$specJson = json_encode( $grid['spec'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $specJson === false ) {
				throw new RuntimeException( 'AGGrid: failed to JSON-encode spec for page ' . $pageId );
			}
			$rows[] = [
				'ags_page_id' => $pageId,
				'ags_grid_index' => (int)$index,
				'ags_source' => $grid['source'],
				'ags_spec' => $specJson,
				'ags_hash' => $grid['hash'],
			];
		}
		$dbw->newInsertQueryBuilder()
			->insertInto( 'aggrid_source' )
			->rows( $rows )
			->caller( __METHOD__ )->execute();
	}

	public function deleteForPage( int $pageId ): void {
		$this->dbProvider->getPrimaryDatabase()
			->newDeleteQueryBuilder()
			->deleteFrom( 'aggrid_source' )
			->where( [ 'ags_page_id' => $pageId ] )
			->caller( __METHOD__ )->execute();
	}

	public function getSource( int $pageId, int $gridIndex ): ?array {
		$row = $this->dbProvider->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( [ 'ags_source', 'ags_spec' ] )
			->from( 'aggrid_source' )
			->where( [ 'ags_page_id' => $pageId, 'ags_grid_index' => $gridIndex ] )
			->caller( __METHOD__ )->fetchRow();

		if ( $row === false ) {
			return null;
		}
		return [
			'source' => (string)$row->ags_source,
			'spec' => json_decode( $row->ags_spec, true ),
		];
	}
}
