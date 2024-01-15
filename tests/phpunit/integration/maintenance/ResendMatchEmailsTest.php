<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Maintenance;

use MediaWiki\Extension\MediaModeration\Maintenance\ResendMatchEmails;
use MediaWiki\Extension\MediaModeration\Services\MediaModerationEmailer;
use MediaWiki\Language\RawMessage;
use MediaWiki\Tests\Maintenance\MaintenanceBaseTestCase;
use StatusValue;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Maintenance\ResendMatchEmails
 * @group MediaModeration
 * @group Database
 */
class ResendMatchEmailsTest extends MaintenanceBaseTestCase {
	protected function getMaintenanceClass() {
		return ResendMatchEmails::class;
	}

	/** @dataProvider provideExecute */
	public function testExecute( $scannedSince, array $mockMediaModerationEmailerStatuses, $expectedOutput ) {
		// Set uploaded since as a pre-defined string, as is only passed to a mocked method.
		$uploadedSince = '20220505050505';
		// Mock the MediaModerationEmailer so we can verify that emails are being sent.
		$mockMediaModerationEmailer = $this->createMock( MediaModerationEmailer::class );
		$mockMediaModerationEmailer->expects( $this->exactly( count( $mockMediaModerationEmailerStatuses ) ) )
			->method( 'sendEmailForSha1' )
			->willReturnCallback( function ( $sha1, $minimumTimestamp ) use (
				&$mockMediaModerationEmailerStatuses, $uploadedSince
			) {
				$this->assertSame(
					$uploadedSince,
					$minimumTimestamp,
					'The call to ::sendEmailForSha1 did not use the expected minimum timestamp'
				);
				$expectedSha1AndAssociatedStatus = array_shift( $mockMediaModerationEmailerStatuses );
				$this->assertSame(
					$expectedSha1AndAssociatedStatus[0],
					$sha1,
					'The call to ::sendEmailForSha1 did not use the expected SHA-1'
				);
				return $expectedSha1AndAssociatedStatus[1];
			} );
		// Re-define the MediaModerationEmailer service to return our mock MediaModerationEmailer
		$this->overrideMwServices(
			null,
			[
				'MediaModerationEmailer' => static function () use ( $mockMediaModerationEmailer ) {
					return $mockMediaModerationEmailer;
				}
			]
		);
		$this->maintenance->setArg( 'scanned-since', $scannedSince );
		$this->maintenance->setOption( 'uploaded-since', $uploadedSince );
		$this->maintenance->setOption( 'verbose', 1 );
		// Set --sleep to 0 to avoid slowing the test.
		$this->maintenance->setOption( 'sleep', 0 );
		$this->maintenance->execute();
		$this->expectOutputString( $expectedOutput );
	}

	public static function provideExecute() {
		return [
			'Scanned since as 20240101' => [
				'20240101',
				[
					[ 'sy02psim0bgdh0jt4vdltuzoh7j80au', StatusValue::newGood() ],
				],
				"Sent email for SHA-1 sy02psim0bgdh0jt4vdltuzoh7j80au.\n",
			],
			'Scanned since as 20220101000000 with one fatal emailer status' => [
				'20220101000000',
				[
					[ 'sy02psim0bgdh0jt4vdltuzoh7j80au', StatusValue::newGood() ],
					[ 'sy02psim0bgdh0st4vdltuzoh7j60ru', StatusValue::newFatal( new RawMessage( 'test' ) ) ],
				],
				"Sent email for SHA-1 sy02psim0bgdh0jt4vdltuzoh7j80au.\n" .
				"Email for SHA-1 sy02psim0bgdh0st4vdltuzoh7j60ru failed to send.\n* test\n",
			],
			'Scanned since as 20240101 with one fatal emailer status that has multiple errors' => [
				'20240101',
				[
					[
						'sy02psim0bgdh0jt4vdltuzoh7j80au',
						StatusValue::newFatal( new RawMessage( 'test' ) )->fatal( new RawMessage( "test2" ) ),
					],
				],
				"Email for SHA-1 sy02psim0bgdh0jt4vdltuzoh7j80au failed to send.\n* test\n* test2\n",
			],
		];
	}

	public function addDBData() {
		parent::addDBData();

		$this->db->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru' ],
			] )
			->execute();
		$this->db->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'mms_last_checked' => '20231211',
					// Define match status as negative
					'mms_is_match' => 0,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j60ru',
					'mms_last_checked' => '20231204',
					// Define match status as positive
					'mms_is_match' => 1,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdlguzoh7j60ru',
					'mms_last_checked' => '20231210',
					// Define match status as negative
					'mms_is_match' => 0,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80au',
					'mms_last_checked' => '20240118',
					'mms_is_match' => 1,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u',
					// Define a last checked value, even though the match status is unscanned.
					'mms_last_checked' => '20231208',
					// Define match status as unscanned
					'mms_is_match' => null,
				]
			] )
			->execute();
	}
}
