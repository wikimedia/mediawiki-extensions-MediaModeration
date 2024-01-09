<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Job;

use MediaWiki\Extension\MediaModeration\Job\MediaModerationScanFileJob;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationFileScanner;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Job\MediaModerationScanFileJob
 * @group MediaModeration
 */
class MediaModerationScanFileJobTest extends MediaWikiIntegrationTestCase {
	public function testRun() {
		$expectedSha1 = 'testing1234';
		// Create a mock MediaModerationFileScanner service that expects that ::scanSha1 is called.
		$mockMediaModerationFileScanner = $this->createMock( MediaModerationFileScanner::class );
		$mockMediaModerationFileScanner->expects( $this->once() )
			->method( 'scanSha1' )
			->with( $expectedSha1 );
		// Install the mock MediaModerationFileScanner service
		$this->setService(
			'MediaModerationFileScanner',
			static function () use ( $mockMediaModerationFileScanner ) {
				return $mockMediaModerationFileScanner;
			}
		);
		// Get the object under test
		$objectUnderTest = new MediaModerationScanFileJob(
			[ 'sha1' => $expectedSha1 ]
		);
		// Call ::run and expect that it returns true.
		$this->assertTrue(
			$objectUnderTest->run(),
			'::run should return true.'
		);
	}
}
