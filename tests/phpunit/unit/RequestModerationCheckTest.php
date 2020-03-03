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
use Status;

/**
 * @coversDefaultClass MediaWiki\Extension\MediaModeration\RequestModerationCheck
 * @group MediaModeration
 */
class RequestModerationCheckTest extends MediaWikiUnitTestCase {
	use MocksHelperTrait;

	private function configureFixtureForStatus( $requestStatus ) {
		$requestFactory = $this->getMockHttpRequestFactory();
		$httpRequest = $this->getMockHttpRequest();
		$fileBackend = $this->getMockFileBackend();
		$fileBackend->expects( $this->once() )
			->method( 'getFileContentsMulti' )
			->willReturn( [ '/fake/path/Foo.jpg' => 'File Content Stub' ] );

		$file = $this->getMockLocalFile();
		$file->expects( $this->once() )->method( 'getPath' )->willReturn( '/fake/path/Foo.jpg' );

		$requestFactory->expects( $this->once() )->method( 'create' )->willReturn( $httpRequest );

		$status = new Status();
		$status->setOK( $requestStatus );

		$httpRequest->expects( $this->once() )->method( 'execute' )->willReturn( $status );

		$logger = $this->getMockLogger();

		$options = new ServiceOptions(
			RequestModerationCheck::CONSTRUCTOR_OPTIONS,
			[
				'MediaModerationPhotoDNAUrl' => 'https://api.microsoftmoderator.com/photodna/v1.0/Match',
				'MediaModerationPhotoDNASubscriptionKey' => 'subscription-key'
			]
		);

		$requestModerationCheck = new RequestModerationCheck(
			$options,
			$requestFactory,
			$fileBackend,
			$logger
		);
		return [ $requestModerationCheck, $httpRequest, $logger, $file ];
	}

	/**
	 * @covers ::__construct
	 * @covers ::requestModeration
	 * @covers ::fetchModerationInfo
	 */
	public function testRequestModerationResultInvalidResponse() {
		list(
			$requestModerationCheck,
			$httpRequest,
			$logger,
			$file
		) = $this->configureFixtureForStatus( false );

		$result = $requestModerationCheck->requestModeration( $file );
		$this->assertFalse( $result->isOk() );
	}

	public function requestModerationWrongContentProvider() {
		return [
			'Invalid JSON should fail' => [ '{asf' ],
			'No Content field should fail' => [ json_encode( [] ) ],
			'Content withoud description should fail' => [
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
	 * @covers ::fetchModerationInfo
	 * @covers ::getContents
	 */
	public function testRequestModerationWrongContent( $content ) {
		list(
			$requestModerationCheck,
			$httpRequest,
			$logger,
			$file
		) = $this->configureFixtureForStatus( true );

		$httpRequest->expects( $this->any() )->method( 'getContent' )->willReturn( $content );
		$logger->expects( $this->once() )->method( 'warning' );

		$result = $requestModerationCheck->requestModeration( $file );
		$this->assertFalse( $result->isOk() );
	}

	public function requestModerationCorrectContentProvider() {
		return [
			'Correct status and code and not found Adult content should succeed' => [
				[
					'Status' => [ 'Code' => 3000, 'Description' => 'OK' ],
					'IsMatch' => false
				],
				false
			],
			'Correct status and code and found Adult content should succeed' => [
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
	 * @covers ::fetchModerationInfo
	 */
	public function testRequestModerationCorrectContent( $content, $found ) {
		list(
			$requestModerationCheck,
			$httpRequest,
			$logger,
			$file
		) = $this->configureFixtureForStatus( true );

		$httpRequest->expects( $this->any() )->method( 'getContent' )->willReturn(
			json_encode( $content )
		);
		$logger->expects( $this->never() )->method( 'warning' );

		$result = $requestModerationCheck->requestModeration( $file );
		$this->assertTrue( $result->isOk() );
		$this->assertEquals( $found, $result->isChildExploitationFound() );
	}

}
