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

namespace MediaWiki\Extension\MediaModeration\Tests\Unit;

use MediaWiki\Extension\MediaModeration\CheckResultValue;
use MediaWiki\Extension\MediaModeration\MediaModerationHandler;
use MediaWiki\Extension\MediaModeration\Tests\MocksHelperTrait;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\MediaModerationHandler
 * @group MediaModeration
 */
class MediaModerationHandlerTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	/**
	 * @covers ::__construct
	 * @covers ::handleMedia
	 */
	public function testHandleMediaFileNotFound() {
		$localRepo = $this->getMockLocalRepo();
		$thumbnailProvider = $this->getMockThumbnailProvider();

		$logger = $this->getMockLogger();
		$logger->expects( $this->once() )->method( 'info' );
		$title = $this->getMockTitle();
		$localRepo->expects( $this->once() )
			->method( 'findFile' )
			->with( $this->equalTo( $title ), $this->equalTo( [ 'time' => 'timestamp' ] ) )
			->willReturn( false );

		$service = new MediaModerationHandler(
			$localRepo,
			$thumbnailProvider,
			$this->getMockRequestModerationCheck(),
			$this->getMockProcessModerationCheckResult(),
			$logger
		);
		$this->assertTrue( $service->handleMedia( $title, 'timestamp' ) );
	}

	/**
	 * @covers ::__construct
	 * @covers ::handleMedia
	 */
	public function testHandleMediaFileFoundWrongResult() {
		$localRepo = $this->getMockLocalRepo();

		$thumbnailProvider = $this->getMockThumbnailProvider();

		$file = $this->getMockLocalFile();
		$title = $this->getMockTitle();

		$thumbnailProvider
			->expects( $this->once() )
			->method( 'getThumbnailUrl' )
			->with( $file )
			->willReturn( 'ThumbnailContent' );

		$localRepo->expects( $this->once() )->method( 'findFile' )
			->with( $this->equalTo( $title ), $this->equalTo( [ 'time' => 'timestamp' ] ) )
			->willReturn( $file );

		$logger = $this->getMockLogger();

		$request = $this->getMockRequestModerationCheck();
		$request->expects( $this->once() )->method( 'requestModeration' )->willReturn(
			new CheckResultValue( false, false )
		);

		$service = new MediaModerationHandler(
			$localRepo,
			$thumbnailProvider,
			$request,
			$this->getMockProcessModerationCheckResult(),
			$logger
		);
		$this->assertFalse( $service->handleMedia( $title, 'timestamp' ) );
	}

	/**
	 * @covers ::__construct
	 * @covers ::handleMedia
	 */
	public function testHandleMediaFileFoundGoodResult() {
		$localRepo = $this->getMockLocalRepo();

		$thumbnailProvider = $this->getMockThumbnailProvider();
		$title = $this->getMockTitle();
		$file = $this->getMockLocalFile();

		$localRepo->expects( $this->once() )->method( 'findFile' )
			->with( $this->equalTo( $title ), $this->equalTo( [ 'time' => 'timestamp' ] ) )
			->willReturn( $file );
		$logger = $this->getMockLogger();

		$request = $this->getMockRequestModerationCheck();
		$request->expects( $this->once() )->method( 'requestModeration' )->willReturn(
			new CheckResultValue( true, false )
		);

		$processResult = $this->getMockProcessModerationCheckResult();
		$processResult->expects( $this->once() )->method( 'processResult' );

		$service = new MediaModerationHandler(
			$localRepo,
			$thumbnailProvider,
			$request,
			$processResult,
			$logger
		);
		$this->assertTrue( $service->handleMedia( $title, 'timestamp' ) );
	}

}
