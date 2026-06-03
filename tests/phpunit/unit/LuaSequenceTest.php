<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Tests\Unit;

use MediaWiki\Extension\AGGrid\LuaSequence;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\AGGrid\LuaSequence
 */
class LuaSequenceTest extends MediaWikiUnitTestCase {

	public function testReindexesOneBasedSequences(): void {
		$out = LuaSequence::normalize( [ 1 => [ 'field' => 'name' ], 2 => [ 'field' => 'price' ] ] );
		$this->assertSame( [ [ 'field' => 'name' ], [ 'field' => 'price' ] ], $out );
	}

	public function testLeavesAssociativeArraysAsObjects(): void {
		$out = LuaSequence::normalize( [ 'field' => 'name', 'width' => 100 ] );
		$this->assertSame( [ 'field' => 'name', 'width' => 100 ], $out );
	}

	public function testRecursesIntoNestedValues(): void {
		$out = LuaSequence::normalize( [ 1 => [ 1 => 'a', 2 => 'b' ] ] );
		$this->assertSame( [ [ 'a', 'b' ] ], $out );
	}

	public function testEmptyArrayStaysEmpty(): void {
		$this->assertSame( [], LuaSequence::normalize( [] ) );
	}

	public function testReturnsScalarsUnchanged(): void {
		$this->assertSame( 'hello', LuaSequence::normalize( 'hello' ) );
		$this->assertSame( 42, LuaSequence::normalize( 42 ) );
		$this->assertNull( LuaSequence::normalize( null ) );
	}
}
