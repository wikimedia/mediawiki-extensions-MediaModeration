<?php

/**
 * Copy of CentralAuth's CentralAuthServiceWiringTest.php
 * used to test the ServiceWiring.php file.
 */

namespace MediaWiki\Extension\MediaModeration\Tests\Integration;

use MediaWikiIntegrationTestCase;

/**
 * Tests ServiceWiring.php by ensuring that the call to the
 * service does not result in an error.
 *
 * @coversNothing PHPUnit does not support covering annotations for files
 * @group MediaModeration
 */
class ServiceWiringTest extends MediaWikiIntegrationTestCase {
	/**
	 * @dataProvider provideService
	 */
	public function testService( string $name ) {
		// Set wgMediaModerationRecipientList and wgMediaModerationFrom
		// with fake values as the defaults cause exceptions to be
		// thrown for some of the services.
		$this->overrideConfigValues( [
			'MediaModerationRecipientList' => [ 'test@test.com' ],
			'MediaModerationFrom' => 'testing@test.com'
		] );
		$this->getServiceContainer()->get( $name );
		$this->addToAssertionCount( 1 );
	}

	public static function provideService() {
		$wiring = require __DIR__ . '/../../../src/ServiceWiring.php';
		foreach ( $wiring as $name => $_ ) {
			yield $name => [ $name ];
		}
	}
}
