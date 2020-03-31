<?php
/**
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

namespace MediaWiki\Extension\MediaModeration;

use MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob
 * @group MediaModeration
 */
class ProcessMediaModerationJobTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	/**
	 * @covers ::newSpec
	 */
	public function testNewSpec() {
		$title = $this->getMockTitle();

		$title
			->expects( $this->any() )
			->method( 'getDBkey' )
			->willReturn( 'File:Foom.png' );

		$title
			->expects( $this->any() )
			->method( 'getNamespace' )
			->willReturn( NS_FILE );

		$spec = ProcessMediaModerationJob::newSpec( $title, 'timestamp', false );
		$this->assertEquals( 'processMediaModeration', $spec->getType() );
		$this->assertEquals( 'File:Foom.png', $spec->getParams()['title'] );
		$this->assertEquals( NS_FILE, $spec->getParams()[ 'namespace' ] );
		$this->assertEquals( 'timestamp', $spec->getParams()[ 'timestamp' ] );
		$this->assertTrue( $spec->ignoreDuplicates() );

		$specPrioritized = ProcessMediaModerationJob::newSpec( $title, 'timestamp', true );
		$this->assertEquals( 'processMediaModerationPrioritized', $specPrioritized->getType() );
	}
}
