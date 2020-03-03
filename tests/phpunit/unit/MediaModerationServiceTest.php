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
		$handler = $this->getMockMediaModerationHandler();
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

		$service = new MediaModerationService( $handler );
		return [ $service, $handler, $file, $uploadBase, $title ];
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaAllowed() {
		list( $service, $handler, $file, $uploadBase, $title ) = $this->configureFixture();

		$title
			->expects( $this->once() )
			->method( 'getDBkey' )
			->willReturn( 'File:Foom.png' );

		$title
			->expects( $this->once() )
			->method( 'getNamespace' )
			->willReturn( NS_FILE );

		$handler
			->expects( $this->once() )
			->method( 'handleMedia' );

		$file
			->expects( $this->once() )
			->method( 'getMediaType' )
			->willReturn( MEDIATYPE_BITMAP );
		$service->processUploadedMedia( $uploadBase );
	}

	/**
	 * @covers ::__construct
	 * @covers ::processUploadedMedia
	 */
	public function testProcessUploadedMediaFirbidden() {
		list( $service, $handler, $file, $uploadBase, $title ) = $this->configureFixture();

		$handler
			->expects( $this->never() )
			->method( 'handleMedia' );

		$file
			->expects( $this->once() )
			->method( 'getMediaType' )
			->willReturn( MEDIATYPE_DRAWING );

		$service->processUploadedMedia( $uploadBase );
	}
}
