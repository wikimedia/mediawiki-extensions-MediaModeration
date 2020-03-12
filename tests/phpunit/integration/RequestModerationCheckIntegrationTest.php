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

	/**
	 * @dataProvider requestModerationRealFileProvider
	 */
	public function requestModerationRealFileProvider() {
		return [
			'Microsoft test file' => [
				'https://pdnasampleimages.blob.core.windows.net/matchedimages/img_130.jpg',
				true,
				'MicrosoftTestFile'
			],
			'External Test file' => [
				'https://via.placeholder.com/350x150',
				false,
				'ExternalTestFile'
			]
		];
	}

	/**
	 * @dataProvider requestModerationRealFileProvider
	 */
	public function testRequestModerationRealFile( $url, $isChildExploitationFound, $name ) {
		$services = MediaWikiServices::getInstance();
		$configFactory = $services->getConfigFactory();

		$options = new ServiceOptions(
			RequestModerationCheck::CONSTRUCTOR_OPTIONS,
			$configFactory->makeConfig( 'MediaModeration' )
		);

		if ( $options->get( 'MediaModerationPhotoDNASubscriptionKey' ) == '' ) {
			$this->markTestSkipped( 'Real requests to PhotoDNA should be disabled for CI' );
		}

		$stats = $this->getMockStats();
		$stats->expects( $this->once() )
			->method( 'timing' )
			->with( 'mediamoderation.photodna.200.latency', $this->anything() );

		$requestModerationCheck = new RequestModerationCheck(
			$options,
			$services->getHttpRequestFactory(),
			$stats,
			LoggerFactory::getInstance( 'mediamoderation' )
		);
		$info = $requestModerationCheck->requestModeration( $url, $name );
		$this->assertTrue( $info->isOk() );
		$this->assertEquals( $isChildExploitationFound, $info->isChildExploitationFound() );
	}
}
