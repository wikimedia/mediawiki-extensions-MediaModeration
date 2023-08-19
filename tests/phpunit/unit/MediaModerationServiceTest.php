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

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Title\Title;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\MediaModerationService
 * @group MediaModeration
 */
class MediaModerationServiceTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	private function configureFixture( $checkOnUpload ) {
		$file = $this->getMockLocalFile();
		$uploadBase = $this->getMockUploadBase();

		$title = $this->getMockTitle( Title::class );

		$file->expects( $this->any() )
			->method( 'getTitle' )
			->willReturn( $title );

		$options = new ServiceOptions(
			MediaModerationService::CONSTRUCTOR_OPTIONS,
			[
				'MediaModerationCheckOnUpload' => $checkOnUpload
			]
		);

		$jobQueueGroup = $this->getMockJobQueueGroup();

		$logger = $this->getMockLogger();

		$service = new MediaModerationService( $options, $jobQueueGroup, $logger );
		return [ $service, $jobQueueGroup, $file, $uploadBase, $title ];
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaAllowed() {
		list( $service, $jobQueueGroup, $file, $uploadBase, $title ) = $this->configureFixture( true );

		$uploadBase->expects( $this->once() )
			->method( 'getLocalFile' )
			->willReturn( $file );

		$title->expects( $this->any() )
			->method( 'getDBkey' )
			->willReturn( 'File:Foom.png' );

		$title->expects( $this->any() )
			->method( 'getNamespace' )
			->willReturn( NS_FILE );

		$jobQueueGroup->expects( $this->once() )
			->method( 'push' );

		$file->expects( $this->once() )
			->method( 'getMediaType' )
			->willReturn( MEDIATYPE_BITMAP );
		$file->expects( $this->once() )
			->method( 'getTimestamp' )
			->willReturn( 'timestamp' );

		$service->processUploadedMedia( $uploadBase );
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaForbidden() {
		list( $service, $jobQueueGroup, $file, $uploadBase, $title ) = $this->configureFixture( true );

		$uploadBase->expects( $this->once() )
			->method( 'getLocalFile' )
			->willReturn( $file );

		$jobQueueGroup->expects( $this->never() )
			->method( 'push' );

		$file->expects( $this->once() )
			->method( 'getMediaType' )
			->willReturn( MEDIATYPE_DRAWING );

		$service->processUploadedMedia( $uploadBase );
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaCheckOnUploadDisabled() {
		list( $service, $jobQueueGroup, $file, $uploadBase, $title ) = $this->configureFixture( false );

		$uploadBase->expects( $this->never() )
			->method( 'getLocalFile' );

		$jobQueueGroup->expects( $this->never() )
			->method( 'push' );

		$service->processUploadedMedia( $uploadBase );
	}
}
