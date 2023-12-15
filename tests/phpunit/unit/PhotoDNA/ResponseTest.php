<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\PhotoDNA;

use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\PhotoDNA\Response
 * @group MediaModeration
 */
class ResponseTest extends MediaWikiUnitTestCase {

	/**
	 * @dataProvider provideNewFromArray
	 */
	public function testNewFromArray( int $expectedCode, bool $expectedIsMatch, array $json ) {
		$response = Response::newFromArray( $json, 'test' );
		$this->assertEquals( $expectedCode, $response->getStatusCode() );
		$this->assertEquals( $expectedIsMatch, $response->isMatch() );
		$this->assertEquals( 'test', $response->getRawResponse() );
	}

	public static function provideNewFromArray(): array {
		return [
			'good status code, no match' => [
				Response::STATUS_OK,
				false,
				[ 'Status' => [ 'Code' => Response::STATUS_OK ], 'IsMatch' => false ]
			],
			'good status code, is match' => [
				Response::STATUS_OK,
				true,
				[ 'Status' => [ 'Code' => Response::STATUS_OK ], 'IsMatch' => true ]
			],
			'bad status code, IsMatch missing from JSON' => [
				Response::STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE,
				false,
				[ 'Status' => [ 'Code' => Response::STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE ] ]
			],
			'bad JSON structure, default bad status code' => [
				Response::INVALID_JSON_STATUS_CODE,
				false,
				[]
			]
		];
	}

}
