<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Unit\PhotoDNA;

use MediaWiki\Extension\MediaModeration\PhotoDNA\MediaModerationPhotoDNAResponseHandler;
use MediaWiki\Extension\MediaModeration\PhotoDNA\Response;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\PhotoDNA\MediaModerationPhotoDNAResponseHandler
 * @group MediaModeration
 */
class MediaModerationPhotoDNAResponseHandlerTest extends MediaWikiUnitTestCase {
	use MediaModerationPhotoDNAResponseHandler;

	/**
	 * @dataProvider provideHandleResponseCases
	 */
	public function testHandleResponse(
		bool $expectedStatusIsGood,
		Response $response,
		bool $isMatch,
		?string $expectedMessage = null
	) {
		$actual = $this->createStatusFromResponse( $response );
		if ( $expectedStatusIsGood ) {
			$this->assertStatusGood( $actual );
		} else {
			$this->assertStatusNotGood( $actual );
		}
		if ( !$expectedStatusIsGood ) {
			$this->assertStatusNotOK( $actual );
			$message = $actual->getMessages()[0];
			$this->assertSame( 'rawmessage', $message->getKey() );
			$this->assertSame( $expectedMessage, $message->getParams()[0] );
		}
	}

	public static function provideHandleResponseCases(): array {
		return [
			'good status, no match' => [
				true,
				new Response( Response::STATUS_OK, false ),
				false,
			],
			'good status, match' => [
				true,
				new Response( Response::STATUS_OK, true ),
				true,
			],
			'bad status, 3002' => [
				false,
				new Response( Response::STATUS_INVALID_MISSING_REQUEST_PARAMS ),
				false,
				'3002: Invalid or missing request parameter(s)'
			],
			'bad status, 3004' => [
				false,
				new Response( Response::STATUS_UNKNOWN_SCENARIO ),
				false,
				'3004: Unknown scenario or unhandled error occurred while processing request'
			],
			'bad status, 3206' => [
				false,
				new Response( Response::STATUS_COULD_NOT_VERIFY_FILE_AS_IMAGE ),
				false,
				'3206: The given file could not be verified as an image'
			],
			'bad status, 3208' => [
				false,
				new Response( Response::STATUS_IMAGE_PIXEL_SIZE_NOT_IN_RANGE ),
				false,
				'3208: Image size in pixels is not within allowed range'
			],
			'bad status, 3209' => [
				false,
				new Response( Response::STATUS_REQUEST_SIZE_EXCEEDED ),
				false,
				'3209: Request Size Exceeded'
			],
			'bad status, unknown code' => [
				false,
				new Response( 1 ),
				false,
				'1: Unknown status code'
			]
		];
	}

}
