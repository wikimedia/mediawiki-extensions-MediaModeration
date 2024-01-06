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

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\MediaModeration\RequestModerationCheck;
use MediaWiki\Extension\MediaModeration\Tests\MocksHelperTrait;
use MediaWiki\Status\Status;
use MediaWikiUnitTestCase;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\RequestModerationCheck
 * @group MediaModeration
 */
class RequestModerationCheckTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	/**
	 * @param bool $requestStatus
	 * @return array
	 */
	private function configureFixtureForStatus( $requestStatus ) {
		$requestFactory = $this->getMockHttpRequestFactory();
		$httpRequest = $this->getMockHttpRequest();

		$requestFactory->expects( $this->once() )->method( 'create' )->willReturn( $httpRequest );

		$status = new Status();
		$status->setOK( $requestStatus );

		$httpRequest->expects( $this->once() )->method( 'execute' )->willReturn( $status );

		$stats = $this->getMockStats();
		$logger = $this->getMockLogger();

		$options = new ServiceOptions(
			RequestModerationCheck::CONSTRUCTOR_OPTIONS,
			[
				'MediaModerationPhotoDNAUrl' => 'https://api.microsoftmoderator.com/photodna/v1.0/Match',
				'MediaModerationPhotoDNASubscriptionKey' => 'subscription-key',
				'MediaModerationHttpProxy' => null
			]
		);

		$requestModerationCheck = new RequestModerationCheck(
			$options,
			$requestFactory,
			$stats,
			$logger
		);
		return [ $requestModerationCheck, $httpRequest, $logger, $stats ];
	}

	/**
	 * @covers ::__construct
	 * @covers ::requestModeration
	 * @covers ::createModerationRequest
	 */
	public function testRequestModerationResultInvalidResponse() {
		list(
			$requestModerationCheck,
			,
			,
			$stats
		) = $this->configureFixtureForStatus( false );

		$stats->expects( $this->once() )
			->method( 'timing' )
			->with( 'mediamoderation.photodna.500.latency', $this->anything() );

		$result = $requestModerationCheck->requestModeration( 'http://example.com/file-url.jpg', 'FileName' );
		$this->assertFalse( $result->isOk() );
	}

	/**
	 * @return array
	 */
	public static function requestModerationWrongContentProvider() {
		return [
			'Invalid JSON should fail' => [ '{asf' ],
			'No Content field should fail' => [ json_encode( [] ) ],
			'Content without description should fail' => [
				json_encode( [ 'Status' => [ 'Code' => 200 ] ] )
			],
			'Content with wrong status should fail' => [
				json_encode( [ 'Status' => [ 'Code' => 200, 'Description' => 'Error' ] ] )
			],
			'No IsMatch field should fail' => [
				json_encode( [ 'Status' => [ 'Code' => 3000, 'Description' => 'OK' ] ] )
			]
		];
	}

	/**
	 * @dataProvider requestModerationWrongContentProvider
	 *
	 * @covers ::__construct
	 * @covers ::requestModeration
	 * @covers ::createModerationRequest
	 * @covers ::logWarning
	 */
	public function testRequestModerationWrongContent( $content ) {
		list(
			$requestModerationCheck,
			$httpRequest,
			$logger,
			$stats
		) = $this->configureFixtureForStatus( true );

		$stats->expects( $this->once() )
			->method( 'timing' )
			->with( 'mediamoderation.photodna.200.latency', $this->anything() );

		$httpRequest->expects( $this->any() )->method( 'getContent' )->willReturn( $content );
		$logger->expects( $this->once() )->method( 'warning' );
		$result = $requestModerationCheck->requestModeration( 'http://example.com/file-url.jpg', 'FileName' );
		$this->assertFalse( $result->isOk() );
	}

	/**
	 * @return array[]
	 */
	public static function requestModerationCorrectContentProvider() {
		return [
			'Correct status and code with no hash match should succeed' => [
				[
					'Status' => [ 'Code' => 3000, 'Description' => 'OK' ],
					'IsMatch' => false
				],
				false
			],
			'Correct status and code with hash match should succeed' => [
				[
					'Status' => [ 'Code' => 3000, 'Description' => 'OK' ],
					'IsMatch' => true
				],
				true
			]
		];
	}

	/**
	 * @dataProvider requestModerationCorrectContentProvider
	 *
	 * @covers ::__construct
	 * @covers ::requestModeration
	 * @covers ::createModerationRequest
	 * @covers ::logWarning
	 */
	public function testRequestModerationCorrectContent( $content, $found ) {
		list(
			$requestModerationCheck,
			$httpRequest,
			$logger,
			$stats
		) = $this->configureFixtureForStatus( true );

		$httpRequest->expects( $this->any() )->method( 'getContent' )->willReturn(
			json_encode( $content )
		);
		$logger->expects( $this->never() )->method( 'warning' );
		$stats->expects( $this->once() )
			->method( 'timing' )
			->with( 'mediamoderation.photodna.200.latency', $this->anything() );

		$result = $requestModerationCheck->requestModeration( 'http://example.com/file-url.jpg', 'FileName' );
		$this->assertTrue( $result->isOk() );
		$this->assertEquals( $found, $result->isChildExploitationFound() );
	}

}
