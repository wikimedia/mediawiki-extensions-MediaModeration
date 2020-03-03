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
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MediaWikiServices;
use MediaWikiIntegrationTestCase;

/**
 * @covers MediaWiki\Extension\MediaModeration\RequestModerationCheck
 * @group MediaModeration
 */
class RequestModerationCheckIntegrationTest extends MediaWikiIntegrationTestCase {
	use MocksHelperTrait;

	public function requestModerationRealFileProvider() {
		return [
			'Microsoft test file' => [
				'https://pdnasampleimages.blob.core.windows.net/matchedimages/img_130.jpg',
				true
			],
			'External Test file' => [
				'https://via.placeholder.com/350x150',
				false
			]
		];
	}

	/**
	 * @dataProvider requestModerationRealFileProvider
	 */
	public function testRequestModerationRealFile( $url, $isChildExploitationFound ) {
		$this->markTestSkipped( 'Real requests to PhotoDNA should be disabled for CI' );

		$services = MediaWikiServices::getInstance();
		$configFactory = $services->getConfigFactory();
		$backend = $this->getMockFileBackend();

		$file = $this->getMockLocalFile();
		$file->expects( $this->once() )->method( 'getPath' )->willReturn( '/fake/path/Foo.jpg' );

		$content = file_get_contents(
			$url
		);

		$this->assertNotFalse( $content );

		$backend->expects( $this->once() )
			->method( 'getFileContentsMulti' )
			->willReturn( [ '/fake/path/Foo.jpg' => $content ] );

			$file->expects( $this->once() )->method( 'getMimeType' )->willReturn( 'image/jpeg' );

		$requestModerationCheck = new RequestModerationCheck(
			new ServiceOptions(
				RequestModerationCheck::CONSTRUCTOR_OPTIONS,
				$configFactory->makeConfig( 'MediaModeration' )
			),
			$services->getHttpRequestFactory(),
			$backend,
			LoggerFactory::getInstance( 'mediamoderation' )
		);
		$info = $requestModerationCheck->requestModeration( $file );
		$this->assertTrue( $info->isOk() );
		$this->assertEquals( $isChildExploitationFound, $info->isChildExploitationFound() );
	}
}
