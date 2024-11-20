<?php

namespace MediaWiki\Extension\MediaModeration\Hooks\Handlers;

use MediaWiki\Extension\MediaModeration\Maintenance\ImportExistingFilesToScanTable;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @inheritDoc
	 * @codeCoverageIgnore Tested by updating or installing MediaWiki.
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$base = __DIR__ . '/../../../schema';
		$maintenanceDb = $updater->getDB();
		$dbType = $maintenanceDb->getType();
		$updater->addExtensionUpdateOnVirtualDomain( [
			'virtual-mediamoderation',
			'addTable',
			'mediamoderation_scan',
			"$base/$dbType/tables-generated.sql",
			true
		] );
		$updater->addPostDatabaseUpdateMaintenance( ImportExistingFilesToScanTable::class );
	}
}
