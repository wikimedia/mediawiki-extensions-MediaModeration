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

namespace MediaWiki\Extension\MediaModeration\Tests\Integration\Hooks\Handlers;

use MediaWiki\Config\ConfigException;
use MediaWiki\Extension\MediaModeration\Hooks\Handlers\SchemaChangesHandler;
use MediaWiki\Installer\DatabaseUpdater;
use MediaWikiIntegrationTestCase;
use Wikimedia\Rdbms\IDatabase;

/**
 * @covers MediaWiki\Extension\MediaModeration\Hooks\Handlers\SchemaChangesHandler
 * @group MediaModeration
 */
class SchemaChangesHandlerTest extends MediaWikiIntegrationTestCase {
	public function testThrowsExceptionOnInvalidConfig() {
		$this->expectException( ConfigException::class );
		$this->testNoUpdates( 'test', false );
	}

	/** @dataProvider provideMappingConfigCausingNoDBUpdates */
	public function testNoUpdates( $configValue, $shouldPerformOutput = true ) {
		$this->setMwGlobals( 'wgVirtualDomainsMapping', $configValue );
		$objectUnderTest = new SchemaChangesHandler();
		$mockUpdater = $this->createMock( DatabaseUpdater::class );
		// ::getDB method is called to get the type of the DB. This should
		// only be done if the database table is not on an external store.
		$mockUpdater->expects( $this->never() )
			->method( 'getDB' );
		// It should call ::output to indicate no updates were performed.
		$mockUpdater->expects( $shouldPerformOutput ? $this->once() : $this->never() )
			->method( 'output' );
		$objectUnderTest->onLoadExtensionSchemaUpdates( $mockUpdater );
	}

	public static function provideMappingConfigCausingNoDBUpdates() {
		return [
			'Empty array as the key of "virtual-mediamoderation" in the virtual domains config' => [
				[ 'virtual-mediamoderation' => [] ]
			],
			'Cluster defined for the "virtual-mediamoderation" key in the virtual domains config' => [
				[ 'virtual-mediamoderation' => [ 'cluster' => 'test' ] ]
			],
			'Cluster and DB defined for "virtual-mediamoderation" key in the virtual domains config' => [
				[ 'virtual-mediamoderation' => [ 'cluster' => 'test', 'db' => false ] ]
			],
			'DB that is not false defined for "virtual-mediamoderation" key in the virtual domains config' => [
				[ 'virtual-mediamoderation' => [ 'db' => 'centralauth' ] ]
			],
		];
	}

	/** @dataProvider provideMappingConfigCausingDBUpdates */
	public function testUpdates( $configValue ) {
		$this->setMwGlobals( 'wgVirtualDomainsMapping', $configValue );
		$objectUnderTest = new SchemaChangesHandler();
		$mockUpdater = $this->createMock( DatabaseUpdater::class );
		// ::getDB method is called to get the type of the DB. This should
		// only be done if the database table is not on an external store.
		$mockDb = $this->createMock( IDatabase::class );
		$mockDb->method( 'getType' )
			->willReturn( 'mysql' );
		$mockUpdater->expects( $this->once() )
			->method( 'getDB' )
			->willReturn( $mockDb );
		// It should call ::output to indicate no updates were performed.
		$mockUpdater->expects( $this->never() )
			->method( 'output' );
		// A call to ::addExtensionTable should be made
		$mockUpdater->expects( $this->once() )
			->method( 'addExtensionTable' );
		$objectUnderTest->onLoadExtensionSchemaUpdates( $mockUpdater );
	}

	public static function provideMappingConfigCausingDBUpdates() {
		return [
			'Empty array as the virtual domains config' => [
				[]
			],
			'Something else defined in the virtual domains config' => [
				[ 'virtual-test' => [] ]
			],
			'DB that is false defined for "virtual-mediamoderation" key in the virtual domains config' => [
				[ 'virtual-mediamoderation' => [ 'db' => false ] ]
			],
		];
	}
}
