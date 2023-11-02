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

use DatabaseUpdater;
use MediaWiki\Extension\MediaModeration\Hooks\Handlers\SchemaChangesHandler;
use MediaWikiIntegrationTestCase;

/**
 * @covers MediaWiki\Extension\MediaModeration\Hooks\Handlers\SchemaChangesHandler
 * @group MediaModeration
 */
class SchemaChangesHandlerTest extends MediaWikiIntegrationTestCase {
	public function testNoUpdatesOnExternalDB() {
		$this->setMwGlobals( 'wgVirtualDomainsMapping', [ 'virtual-mediamoderation' => [] ] );
		$objectUnderTest = new SchemaChangesHandler();
		$mockUpdater = $this->createMock( DatabaseUpdater::class );
		// ::getDB method is called to get the type of the DB. This should
		// only be done if the database table is not on an external store.
		$mockUpdater->expects( $this->never() )
			->method( 'getDB' );
		$objectUnderTest->onLoadExtensionSchemaUpdates( $mockUpdater );
	}
}
