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
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\ThumbnailProvider
 * @group MediaModeration
 */
class ThumbnailProviderTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	/**
	 * @param bool $sendThumbnails
	 * @return array
	 */
	private function configureFixture( bool $sendThumbnails ) {
		$options = new ServiceOptions(
			ThumbnailProvider::CONSTRUCTOR_OPTIONS,
			[
				'MediaModerationSendThumbnails' => $sendThumbnails,
				'MediaModerationThumbnailSize' => [ 'width' => 200, 'height' => 100 ],
			]
		);

		$file = $this->getMockLocalFile();
		$logger = $this->getMockLogger();
		$thumbnailProvider = new ThumbnailProvider(
			$options,
			$logger
		);

		return [ $thumbnailProvider, $logger, $file ];
	}

	/**
	 * @covers ::__construct
	 * @covers ::getThumbnailUrl
	 */
	public function testThumbnailProviderShouldUseFullFile() {
		list(
			$thumbnailProvider,
			$logger,
			$file
		) = $this->configureFixture( false );

		$file->expects( $this->any() )
			->method( 'canRender' )
			->willReturn( true );
		$file->expects( $this->once() )
			->method( 'getUrl' )
			->willReturn( 'http://example.com/path/to/file.jpg' );

		$logger->expects( $this->never() )->method( 'warning' );

		$thumbUrl = $thumbnailProvider->getThumbnailUrl( $file );
		$this->assertEquals( 'http://example.com/path/to/file.jpg', $thumbUrl );
	}

	/**
	 * @covers ::__construct
	 * @covers ::getThumbnailUrl
	 */
	public function testThumbnailProviderShouldUseFullFileIfCantRender() {
		list(
			$thumbnailProvider,
			$logger,
			$file
		) = $this->configureFixture( true );

		$file->expects( $this->any() )
			->method( 'canRender' )
			->willReturn( false );

		$file->expects( $this->once() )
			->method( 'getUrl' )
			->willReturn( 'http://example.com/path/to/file.jpg' );

		$logger->expects( $this->once() )->method( 'warning' );

		$thumbUrl = $thumbnailProvider->getThumbnailUrl( $file );
		$this->assertEquals( 'http://example.com/path/to/file.jpg', $thumbUrl );
	}

	/**
	 * @covers ::__construct
	 * @covers ::getThumbnailUrl
	 */
	public function testThumbnailProviderCreatesThumbnail() {
		list(
			$thumbnailProvider,
			$logger,
			$file,
		) = $this->configureFixture( true );

		$file->expects( $this->any() )
			->method( 'canRender' )
			->willReturn( true );

		$thumbnailImage = $this->getMockThumbnailImage();

		$file->expects( $this->once() )
			->method( 'transform' )
			->with( [ 'width' => 200, 'height' => 100 ] )
			->willReturn( $thumbnailImage );

		$logger->expects( $this->never() )->method( 'warning' );

		$thumbnailImage->expects( $this->once() )
			->method( 'getUrl' )
			->willReturn( 'http://example.com/path/to/file.jpg' );

		$thumbUrl = $thumbnailProvider->getThumbnailUrl( $file );
		$this->assertEquals( 'http://example.com/path/to/file.jpg', $thumbUrl );
	}

}
