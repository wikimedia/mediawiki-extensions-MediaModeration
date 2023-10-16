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

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Job;

use MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob;
use MediaWiki\Extension\MediaModeration\Tests\MocksHelperTrait;
use MediaWikiIntegrationTestCase;

/**
 * @covers MediaWiki\Extension\MediaModeration\Job\ProcessMediaModerationJob
 * @group MediaModeration
 */
class ProcessMediaModerationJobTest extends MediaWikiIntegrationTestCase {
	use MocksHelperTrait;

	/**
	 * @return \bool[][]
	 */
	public static function runPassArgumentsProvider() {
		return [ [ true ], [ false ] ];
	}

	/**
	 * @dataProvider runPassArgumentsProvider
	 */
	public function testRunPassArguments( $hadlerResult ) {
		$mediaModerationHandler = $this->getMockMediaModerationHandler();

		$mediaModerationHandler
			->expects( $this->once() )
			->method( 'handleMedia' )
			->with( $this->anything(), $this->equalTo( 'timestamp' ) )
			->willReturn( $hadlerResult );

		$this->setService( 'MediaModerationHandler', $mediaModerationHandler );
		$job = new ProcessMediaModerationJob( [
			'title' => 'File:Bal.png',
			'namespace' => NS_FILE,
			'timestamp' => 'timestamp',
		] );
		$this->assertEquals( $hadlerResult,  $job->run() );
	}
}
