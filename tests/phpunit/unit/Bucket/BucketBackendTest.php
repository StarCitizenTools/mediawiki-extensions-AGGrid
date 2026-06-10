<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit\Bucket;

use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketBackend;
use MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketSourceCompiler;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Bucket\BucketBackend
 */
class BucketBackendTest extends MediaWikiUnitTestCase {

	public function testTypeAndCapability(): void {
		$backend = new BucketBackend(
			$this->createMock( BucketSourceCompiler::class ),
			$this->createMock( BackendDataSource::class )
		);
		$this->assertSame( 'bucket', $backend->getType() );
		$this->assertFalse( $backend->supportsQuickSearch(), 'Bucket has no server-side quick search' );
	}

	public function testCompileSourceDelegates(): void {
		$compiler = $this->createMock( BucketSourceCompiler::class );
		$compiler->expects( $this->once() )->method( 'compile' )
			->with( [ 'bucket' => 'item' ] )
			->willReturn( [ [ 'col' ], [ 'spec' ] ] );
		$backend = new BucketBackend( $compiler, $this->createMock( BackendDataSource::class ) );
		$this->assertSame( [ [ 'col' ], [ 'spec' ] ], $backend->compileSource( [ 'bucket' => 'item' ] ) );
	}

	public function testGetDataSourceReturnsInjected(): void {
		$dataSource = $this->createMock( BackendDataSource::class );
		$backend = new BucketBackend( $this->createMock( BucketSourceCompiler::class ), $dataSource );
		$this->assertSame( $dataSource, $backend->getDataSource() );
	}
}
