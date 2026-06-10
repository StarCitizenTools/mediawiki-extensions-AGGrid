<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit\Smw;

use MediaWiki\Extension\AGGrid\DataSource\BackendDataSource;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwBackend;
use MediaWiki\Extension\AGGrid\DataSource\Smw\SmwSourceCompiler;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\DataSource\Smw\SmwBackend
 */
class SmwBackendTest extends MediaWikiUnitTestCase {

	public function testTypeAndCapability(): void {
		$backend = new SmwBackend(
			$this->createMock( SmwSourceCompiler::class ),
			$this->createMock( BackendDataSource::class )
		);
		$this->assertSame( 'smw', $backend->getType() );
		$this->assertTrue( $backend->supportsQuickSearch(), 'SMW supports server-side quick search' );
	}

	public function testCompileSourceDelegates(): void {
		$compiler = $this->createMock( SmwSourceCompiler::class );
		$compiler->expects( $this->once() )->method( 'compile' )
			->with( [ 'query' => 'x' ] )
			->willReturn( [ [ 'col' ], [ 'spec' ] ] );
		$backend = new SmwBackend( $compiler, $this->createMock( BackendDataSource::class ) );
		$this->assertSame( [ [ 'col' ], [ 'spec' ] ], $backend->compileSource( [ 'query' => 'x' ] ) );
	}

	public function testGetDataSourceReturnsInjected(): void {
		$dataSource = $this->createMock( BackendDataSource::class );
		$backend = new SmwBackend( $this->createMock( SmwSourceCompiler::class ), $dataSource );
		$this->assertSame( $dataSource, $backend->getDataSource() );
	}
}
