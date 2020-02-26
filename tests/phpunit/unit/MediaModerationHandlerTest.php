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

use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\MediaModerationHandler
 * @group MediaModeration
 */
class MediaModerationHandlerTest extends MediaWikiUnitTestCase {
	use MockTitleFactoryTrait;
	use MockLocalRepoTrait;

	/**
	 * @covers ::__construct
	 * @covers ::handleMedia
	 */
	public function testHandleMediaFileNotFound() {
		$localRepo = $this->getMockLocalRepo();
		$localRepo->expects( $this->once() )->method( 'findFile' )->willReturn( false );
		$logger = $this->getMockLogger();
		$logger->expects( $this->once() )->method( 'info' );

		$service = new MediaModerationHandler(
			$this->getMockTitleFactory( $this->getMockTitle() ),
			$localRepo,
			$this->getMockRequestModerationCheck(),
			$this->getMockProcessModerationCheckResult(),
			$logger
		);
		$this->assertTrue( $service->handleMedia( 'File:Foom.png', NS_FILE ) );
	}

	/**
	 * @covers ::__construct
	 * @covers ::handleMedia
	 */
	public function testHandleMediaFileFound() {
		$localRepo = $this->getMockLocalRepo();

		$file = $this->getMockLocalFile();

		$localRepo->expects( $this->once() )->method( 'findFile' )->willReturn( $file );
		$logger = $this->getMockLogger();

		$request = $this->getMockRequestModerationCheck();
		$request->expects( $this->once() )->method( 'requestModeration' )->willReturn(
			new CheckResultValue( false )
		);

		$service = new MediaModerationHandler(
			$this->getMockTitleFactory( $this->getMockTitle() ),
			$localRepo,
			$request,
			$this->getMockProcessModerationCheckResult(),
			$logger
		);
		$this->assertTrue( $service->handleMedia( 'File:Foom.png', NS_FILE ) );
	}

}
