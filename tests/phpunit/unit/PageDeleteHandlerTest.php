<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use ManualLogEntry;
use MediaWiki\Extension\AGGrid\Hooks\PageDeleteHandler;
use MediaWiki\Extension\AGGrid\Service\GridDataStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Revision\RevisionRecord;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\Hooks\PageDeleteHandler
 */
class PageDeleteHandlerTest extends MediaWikiUnitTestCase {

	public function testDeletesRowsForDeletedPage(): void {
		$store = $this->createMock( GridDataStore::class );
		$store->expects( $this->once() )->method( 'deleteForPage' )->with( 100 );

		$page = $this->createMock( ProperPageIdentity::class );
		$deleter = $this->createMock( Authority::class );
		$logEntry = $this->createMock( ManualLogEntry::class );

		( new PageDeleteHandler( $store ) )->onPageDeleteComplete(
			$page, $deleter, 'reason', 100, $this->createMock( RevisionRecord::class ), $logEntry, 1
		);
	}
}
