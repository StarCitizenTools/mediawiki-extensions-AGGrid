<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Service;

use RuntimeException;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Reads and writes inline grid rows in the aggrid_data table.
 */
final class InlineDataStore implements GridDataStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {
	}

	/**
	 * @inheritDoc
	 */
	public function replaceForPage( int $pageId, array $grids ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$existing = [];
		$res = $dbw->newSelectQueryBuilder()
			->select( [ 'agd_grid_index', 'agd_hash' ] )
			->from( 'aggrid_data' )
			->where( [ 'agd_page_id' => $pageId ] )
			->caller( __METHOD__ )->fetchResultSet();
		foreach ( $res as $row ) {
			$existing[(int)$row->agd_grid_index] = (string)$row->agd_hash;
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
			->deleteFrom( 'aggrid_data' )
			->where( [ 'agd_page_id' => $pageId ] )
			->caller( __METHOD__ )->execute();

		if ( !$grids ) {
			return;
		}

		$rows = [];
		foreach ( $grids as $index => $grid ) {
			$rowsJson = json_encode( $grid['rows'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( $rowsJson === false ) {
				throw new RuntimeException( 'AGGrid: failed to JSON-encode rows for page ' . $pageId );
			}
			$rows[] = [
				'agd_page_id' => $pageId,
				'agd_grid_index' => (int)$index,
				'agd_rows' => $rowsJson,
				'agd_row_count' => count( $grid['rows'] ),
				'agd_hash' => $grid['hash'],
			];
		}
		$dbw->newInsertQueryBuilder()
			->insertInto( 'aggrid_data' )
			->rows( $rows )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * @inheritDoc
	 */
	public function deleteForPage( int $pageId ): void {
		$this->dbProvider->getPrimaryDatabase()
			->newDeleteQueryBuilder()
			->deleteFrom( 'aggrid_data' )
			->where( [ 'agd_page_id' => $pageId ] )
			->caller( __METHOD__ )->execute();
	}

	/**
	 * @inheritDoc
	 */
	public function getRows( int $pageId, int $gridIndex ): ?array {
		$blob = $this->dbProvider->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( 'agd_rows' )
			->from( 'aggrid_data' )
			->where( [ 'agd_page_id' => $pageId, 'agd_grid_index' => $gridIndex ] )
			->caller( __METHOD__ )->fetchField();

		if ( $blob === false ) {
			return null;
		}
		return json_decode( $blob, true );
	}
}
