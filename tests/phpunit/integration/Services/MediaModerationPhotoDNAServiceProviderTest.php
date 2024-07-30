<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Services;

use ApiRawMessage;
use File;
use FormatJson;
use MediaWiki\Extension\MediaModeration\PhotoDNA\IMediaModerationPhotoDNAServiceProvider;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use MediaWikiIntegrationTestCase;
use MockHttpTrait;
use MWHttpRequest;
use StatusValue;
use UploadStash;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationPhotoDNAServiceProvider
 * @group Database
 * @group MediaModeration
 */
class MediaModerationPhotoDNAServiceProviderTest extends MediaWikiIntegrationTestCase {

	private ?File $file = null;

	use MockHttpTrait;

	public function testCheck() {
		$this->overrideConfigValue( 'MediaModerationPhotoDNASubscriptionKey', '' );
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$mwHttpRequest->method( 'execute' )
			->willReturn( StatusValue::newGood() );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( FormatJson::encode( [ 'Status' => [ 'Code' => 3000 ], 'IsMatch' => false ] ) );
		$mwHttpRequest->expects( $this->atLeast( 1 ) )
			->method( 'setHeader' )
			->willReturnMap( [ [ 'Content-Type', 'image/jpeg', null ] ] );
		$this->installMockHttp( $mwHttpRequest );

		/** @var IMediaModerationPhotoDNAServiceProvider $serviceProvider */
		$serviceProvider = $this->getServiceContainer()->get( '_MediaModerationPhotoDNAServiceProviderProduction' );
		$result = $serviceProvider->check( $this->getTestFile() );
		$this->assertStatusGood( $result );
		$this->assertEquals( 3000, $result->getValue()->getStatusCode() );
	}

	public function testCheckWithHttpError() {
		$this->overrideConfigValue( 'MediaModerationPhotoDNASubscriptionKey', '' );
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$mwHttpRequest->method( 'execute' )
			->willReturn( Status::wrap( StatusValue::newFatal( new ApiRawMessage( 'Some error' ) ) ) );
		$mwHttpRequest->method( 'getStatus' )
			->willReturn( 401 );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( FormatJson::encode( [ 'statusCode' => 401, 'message' => 'Access denied' ] ) );
		$this->installMockHttp( $mwHttpRequest );

		/** @var IMediaModerationPhotoDNAServiceProvider $serviceProvider */
		$serviceProvider = $this->getServiceContainer()->get( '_MediaModerationPhotoDNAServiceProviderProduction' );
		$result = $serviceProvider->check( $this->getTestFile() );
		$this->assertStatusNotOK( $result );
		$message = $result->getMessages()[0];
		$this->assertSame( 'rawmessage', $message->getKey() );
		$this->assertSame( 'PhotoDNA returned HTTP 401 error: Access denied', $message->getParams()[0] );
	}

	public function testCheckWithHttpErrorNoJson() {
		$this->overrideConfigValue( 'MediaModerationPhotoDNASubscriptionKey', '' );
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$mwHttpRequest->method( 'execute' )
			->willReturn( Status::wrap( StatusValue::newFatal( new ApiRawMessage( 'Some error' ) ) ) );
		$mwHttpRequest->method( 'getStatus' )
			->willReturn( 500 );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( '' );
		$this->installMockHttp( $mwHttpRequest );

		/** @var IMediaModerationPhotoDNAServiceProvider $serviceProvider */
		$serviceProvider = $this->getServiceContainer()->get( '_MediaModerationPhotoDNAServiceProviderProduction' );
		$result = $serviceProvider->check( $this->getTestFile() );
		$this->assertStatusNotOK( $result );
		$message = $result->getMessages()[0];
		$this->assertSame( 'rawmessage', $message->getKey() );
		$this->assertSame(
			'PhotoDNA returned HTTP 500 error: Unable to get JSON in response from PhotoDNA',
			$message->getParams()[0]
		);
	}

	public function testCheckWithHttpErrorInvalidJson() {
		$this->overrideConfigValue( 'MediaModerationPhotoDNASubscriptionKey', '' );
		$mwHttpRequest = $this->createMock( MWHttpRequest::class );
		$mwHttpRequest->method( 'execute' )
			->willReturn( Status::wrap( StatusValue::newGood() ) );
		$mwHttpRequest->method( 'getStatus' )
			->willReturn( 200 );
		$mwHttpRequest->method( 'getContent' )
			->willReturn( 'invalidjson{<' );
		$this->installMockHttp( $mwHttpRequest );

		/** @var IMediaModerationPhotoDNAServiceProvider $serviceProvider */
		$serviceProvider = $this->getServiceContainer()->get( '_MediaModerationPhotoDNAServiceProviderProduction' );
		$testFile = $this->getTestFile();
		$result = $serviceProvider->check( $testFile );
		$this->assertStatusNotOK( $result );
		$message = $result->getMessages()[0];
		$this->assertSame( 'rawmessage', $message->getKey() );
		$this->assertSame(
			"PhotoDNA returned an invalid JSON body for {$testFile->getName()}. Parse error: Syntax error",
			$message->getParams()[0]
		);
	}

	private function getTestFile(): File {
		if ( $this->file ) {
			return $this->file;
		}
		$user = User::newSystemUser( 'MediaModeration' );
		$uploadStash = new UploadStash(
			$this->getServiceContainer()->getRepoGroup()->getLocalRepo(),
			$user
		);
		$this->file = $uploadStash->stashFile(
			__DIR__ . '/../../fixtures/489px-Lagoon_Nebula.jpg',
		);
		return $this->file;
	}

}
