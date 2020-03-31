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
use Title;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\MediaModerationService
 * @group MediaModeration
 */
class MediaModerationServiceTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	private function configureFixture() {
		$jobQueueGroup = $this->getMockJobQueueGroup();
		$file = $this->getMockLocalFile();
		$uploadBase = $this->getMockUploadBase();

		$title = $this->getMockTitle( Title::class );

		$file->expects( $this->any() )
			->method( 'getTitle' )
			->willReturn( $title );

		$uploadBase
			->expects( $this->once() )
			->method( 'getLocalFile' )
			->willReturn( $file );

		$service = new MediaModerationService( $jobQueueGroup );
		return [ $service, $jobQueueGroup, $file, $uploadBase, $title ];
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaAllowed() {
		list( $service, $jobQueueGroup, $file, $uploadBase, $title ) = $this->configureFixture();

		$title
			->expects( $this->any() )
			->method( 'getDBkey' )
			->willReturn( 'File:Foom.png' );

		$title
			->expects( $this->any() )
			->method( 'getNamespace' )
			->willReturn( NS_FILE );

		$jobQueueGroup
			->expects( $this->once() )
			->method( 'push' );

		$file
			->expects( $this->once() )
			->method( 'getMediaType' )
			->willReturn( MEDIATYPE_BITMAP );
		$file
			->expects( $this->once() )
			->method( 'getTimestamp' )
			->willReturn( 'timestamp' );

		$service->processUploadedMedia( $uploadBase );
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaFirbidden() {
		list( $service, $jobQueueGroup, $file, $uploadBase, $title ) = $this->configureFixture();

		$jobQueueGroup
			->expects( $this->never() )
			->method( 'push' );

		$file
			->expects( $this->once() )
			->method( 'getMediaType' )
			->willReturn( MEDIATYPE_DRAWING );

		$service->processUploadedMedia( $uploadBase );
	}
}
