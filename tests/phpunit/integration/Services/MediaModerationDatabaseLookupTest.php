<?php

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Services;

use MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup;
use MediaWiki\Tests\Unit\Permissions\MockAuthorityTrait;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\SelectQueryBuilder;

/**
 * @covers \MediaWiki\Extension\MediaModeration\Services\MediaModerationDatabaseLookup
 * @group MediaModeration
 * @group Database
 */
class MediaModerationDatabaseLookupTest extends MediaWikiIntegrationTestCase {
	use MockAuthorityTrait;

	/** @dataProvider provideGetRowsFromScanTable */
	public function testGetRowsFromScanTable(
		$limit, $lastChecked, $direction, $excludedSha1Values, $matchStatus, $expectedResults, $shouldCompareInOrder
	) {
		/** @var MediaModerationDatabaseLookup $objectUnderTest */
		$objectUnderTest = $this->getServiceContainer()->get( 'MediaModerationDatabaseLookup' );
		$returnedResults = $objectUnderTest->getSha1ValuesForScan(
			$limit, $lastChecked, $direction, $excludedSha1Values, $matchStatus
		);
		$this->assertArrayEquals(
			$expectedResults,
			$returnedResults,
			$shouldCompareInOrder,
			$shouldCompareInOrder,
			'::getRowsFromScanTable did not return the expected rows.'
		);
	}

	public static function provideGetRowsFromScanTable() {
		return [
			'Limit 100, last checked as (fake) current date, any match status, sort ASC' => [
				// Limit parameter for ::getRowsFromScanTable
				100,
				// Last checked parameter for ::getRowsFromScanTable
				'20231211143402',
				// The $direction parameter for ::getRowsFromScanTable
				SelectQueryBuilder::SORT_ASC,
				// The $excludedSha1Values parameter for ::getRowsFromScanTable
				[],
				// The $matchStatus parameter for ::getRowsFromScanTable
				MediaModerationDatabaseLookup::ANY_MATCH_STATUS,
				// The SHA-1 values that should be returned
				[
					'sy02psim0bgdh0jt4vdltuzoh7j80yu',
					'sy02psim0bgdh0jt4vdltuzoh7j80ru',
					'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'sy02psim0bgdh0jt4vdltuzoh7j800u',
					'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'sy02psim0bgdh0st4vdltuzoh7j60ru',
					'sy02psim0bgdh0st4vdlguzoh7j60ru',
				],
				// Whether the expected results above should be compared with or without respect to order.
				false,
			],
			'Limit 5, last checked as null, match status as null, sort DESC' => [
				5,
				null,
				SelectQueryBuilder::SORT_DESC,
				[],
				MediaModerationDatabaseLookup::NULL_MATCH_STATUS,
				[
					'sy02psim0bgdh0jt4vdltuzoh7j80yu',
					'sy02psim0bgdh0jt4vdltuzoh7j80ru',
					'sy02psim0bgdh0jt4vdltuzoh7j70ru',
					'sy02psim0bgdh0jt4vdltuzoh7j800u'
				],
				false,
			],
			'Limit 2, last checked as (fake) yesterday, match status any, sort DESC' => [
				2,
				'20231210143402',
				SelectQueryBuilder::SORT_DESC,
				[],
				MediaModerationDatabaseLookup::ANY_MATCH_STATUS,
				[
					'sy02psim0bgdh0st4vdlguzoh7j60ru',
					'sy02psim0bgdh0st4vdltuzoh7j60ru',
				],
				true,
			],
			'Limit 3, last checked as (fake) current date, match status positive, sort DESC' => [
				3,
				'20231211143402',
				SelectQueryBuilder::SORT_DESC,
				[],
				MediaModerationDatabaseLookup::POSITIVE_MATCH_STATUS,
				[ 'sy02psim0bgdh0st4vdltuzoh7j60ru' ],
				true,
			],
			'Limit 10, last checked as (fake) current date, match status negative, sort DESC' => [
				3,
				'20231211143402',
				SelectQueryBuilder::SORT_DESC,
				[],
				MediaModerationDatabaseLookup::NEGATIVE_MATCH_STATUS,
				[
					'sy02psim0bgdh0st4vdltuzoh7j70ru',
					'sy02psim0bgdh0st4vdlguzoh7j60ru',
				],
				true,
			],
		];
	}

	public function addDBData() {
		parent::addDBData();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80yu' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j80ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j70ru' ],
				[ 'mms_sha1' => 'sy02psim0bgdh0jt4vdltuzoh7j800u' ]
			] )
			->execute();
		$this->getDb()->newInsertQueryBuilder()
			->insertInto( 'mediamoderation_scan' )
			->rows( [
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j70ru',
					// Define last checked as today (mock this as 11/12/2023)
					'mms_last_checked' => '20231211',
					'mms_is_match' => 0,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdltuzoh7j60ru',
					// Set last checked as 7 days ago from 11/12/2023.
					'mms_last_checked' => '20231204',
					'mms_is_match' => 1,
				],
				[
					'mms_sha1' => 'sy02psim0bgdh0st4vdlguzoh7j60ru',
					// Set last checked as 1 day ago from 11/12/2023.
					'mms_last_checked' => '20231210',
					'mms_is_match' => 0,
				],
			] )
			->execute();
	}
}
