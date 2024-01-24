<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Services;

use File;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileLookup;
use MediaWiki\MainConfigNames;
use MediaWikiIntegrationTestCase;
use Wikimedia\TestingAccessWrapper;
use Wikimedia\Timestamp\ConvertibleTimestamp;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer
 * @group MediaModeration
 */
class MediaModerationEmailerTest extends MediaWikiIntegrationTestCase {

	/** @dataProvider provideGetEmailBody */
	public function testGetEmailBody(
		$fileObjectsFileNamesAndTimestamps, $minimumTimestamp, $expectedHtml, $expectedText
	) {
		// Needed incase the localhost wiki does not set the content language to English. The language and date format
		// needs to remain consistent for the test to work.
		$this->setUserLang( 'en' );
		$this->overrideConfigValue( MainConfigNames::AmericanDates, false );
		$this->overrideConfigValue( MainConfigNames::Sitename, 'mediawiki' );
		// Convert $fileObjectsFileNamesAndTimestamps to an array of mock File objects
		$mockFileObjects = [];
		foreach ( $fileObjectsFileNamesAndTimestamps as $fileObjectEntry ) {
			$mockFile = $this->createMock( File::class );
			$mockFile->method( 'getTimestamp' )
				->willReturn( $fileObjectEntry['timestamp'] );
			$mockFile->method( 'getName' )
				->willReturn( $fileObjectEntry['name'] );
			$mockFile->method( 'getFullUrl' )
				->willReturn( $fileObjectEntry['url'] ?? '' );
			$mockFileObjects[] = $mockFile;
		}
		// Define a mock MediaModerationFileLookup service that returns the mock File objects in $mockFileObjects
		$mockMediaModerationFileLookup = $this->createMock( MediaModerationFileLookup::class );
		$mockMediaModerationFileLookup
			->method( 'getFileObjectsForSha1' )
			->with( 'test-sha1', 50 )
			->willReturnCallback( static function () use ( $mockFileObjects ) {
				yield from $mockFileObjects;
			} );
		$this->setService(
			'MediaModerationFileLookup',
			static function () use ( $mockMediaModerationFileLookup ) {
				return $mockMediaModerationFileLookup;
			}
		);
		// Get the object under test.
		$objectUnderTest = $this->getServiceContainer()->get( 'MediaModerationEmailer' );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			$expectedHtml,
			$objectUnderTest->getEmailBodyHtml( 'test-sha1', $minimumTimestamp ),
			'::getEmailBodyHtml returned unexpected HTML'
		);
		$this->assertSame(
			$expectedText,
			$objectUnderTest->getEmailBodyPlaintext( 'test-sha1', $minimumTimestamp ),
			'::getEmailBodyPlaintext returned unexpected text'
		);
	}

	public static function provideGetEmailBody() {
		return [
			'Two revisions for the same filename' => [
				// The expected results of ::getTimestamp and ::getName for each File object returned by
				// MediaModerationFileLookup::getFileObjectsForSha1
				[
					[ 'name' => 'Test.png', 'timestamp' => '20230405060708', 'url' => 'test.com' ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
				],
				// The $minimumTimestamp argument passed to the method under test
				null,
				// The expected HTML email body to be returned by ::getEmailBodyHTML
				"The following file revisions on mediawiki are a possible match to a known child exploitation " .
				"image based on their hash:\n<ul><li>Test.png: <a href=\"test.com\">06:07, 5 April 2023</a> and " .
				"06:07, 5 April 2024</li></ul>\n",
				// The expected plaintext email body to be returned by ::getEmailBodyPlaintext
				"The following file revisions on mediawiki are a possible match to a known child exploitation " .
				"image based on their hash:\n* Test.png: 06:07, 5 April 2023 ( test.com ) and 06:07, 5 April 2024\n",
			],
			'Multiple filenames and a minimum timestamp' => [
				[
					[ 'name' => 'Test.png', 'timestamp' => '20220405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20230405060708', 'url' => 'a.com' ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
					[ 'name' => 'Test2.png', 'timestamp' => '20220405060709', 'url' => 'test.com/test' ],
				],
				'20220405060709',
				"The following file revisions on mediawiki are a possible match to a known child exploitation " .
				"image based on their hash:\n" .
				"<ul><li>Test.png: <a href=\"a.com\">06:07, 5 April 2023</a> and 06:07, 5 April 2024</li></ul>\n" .
				"<ul><li>Test2.png: <a href=\"test.com/test\">06:07, 5 April 2022</a></li></ul>\n",
				"The following file revisions on mediawiki are a possible match to a known child exploitation " .
				"image based on their hash:\n" .
				"* Test.png: 06:07, 5 April 2023 ( a.com ) and 06:07, 5 April 2024\n" .
				"* Test2.png: 06:07, 5 April 2022 ( test.com/test )\n",
			],
			'Multiple filenames with missing timestamps and a minimum timestamp meaning only one file is included' => [
				[
					[ 'name' => 'Test.png', 'timestamp' => false ],
					[ 'name' => 'Test.png', 'timestamp' => '20240405060708' ],
					[ 'name' => 'Test.png', 'timestamp' => '20220405060708', 'url' => 'notincluded.test.com' ],
					[ 'name' => 'Test2.png', 'timestamp' => false ],
				],
				'20230405060708',
				"The following file revision on mediawiki is a possible match to a known child exploitation " .
				"image based on their hash:\n<ul><li>Test.png: 06:07, 5 April 2024</li></ul>\n" .
				"The following filenames had versions which matched, but had no upload timestamp: " .
				"Test.png and Test2.png\n",
				"The following file revision on mediawiki is a possible match to a known child exploitation " .
				"image based on their hash:\n* Test.png: 06:07, 5 April 2024\n" .
				"The following filenames had versions which matched, but had no upload timestamp: " .
				"Test.png and Test2.png\n",
			],
		];
	}

	public function testGetEmailSubject() {
		ConvertibleTimestamp::setFakeTime( '20230405060708' );
		$this->overrideConfigValue( MainConfigNames::AmericanDates, false );
		$this->overrideConfigValue( MainConfigNames::Sitename, 'mediawiki' );
		$objectUnderTest = $this->getServiceContainer()->get( 'MediaModerationEmailer' );
		$objectUnderTest = TestingAccessWrapper::newFromObject( $objectUnderTest );
		$this->assertSame(
			'Automated scan found hash match for hash syrtqda72zc7dpjqeukz3d686doficu at 06:07, 5 April 2023',
			$objectUnderTest->getEmailSubject( 'syrtqda72zc7dpjqeukz3d686doficu' ),
			'::getEmailSubject did not return the expected subject line.'
		);
	}
}
