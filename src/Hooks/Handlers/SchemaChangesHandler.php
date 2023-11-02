<?php

namespace MediaWiki\Extension\MediaModeration\Hooks\Handlers;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/**
	 * @codeCoverageIgnore This is tested by installing or updating MediaWiki
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		// The config cannot be injected via dependency injection
		// as the service locator is not available when running
		// this hook.
		global $wgVirtualDomainsMapping;
		// Check if the $wgVirtualDomainsMapping config is defined for the mediamoderation
		// virtual database domain. If it is, then the extension probably uses an external
		// DB which cannot be handled by the DatabaseUpdater. Therefore skip the updates.
		// The config should be an array, but if it isn't then assume that the DB is
		// external.
		if (
			!is_array( $wgVirtualDomainsMapping ) ||
			array_key_exists( 'virtual-mediamoderation', $wgVirtualDomainsMapping )
		) {
			// DatabaseUpdater does not support other databases, so skip
			$updater->output(
				"Unable to perform DB updates for MediaModeration as the table is on an virtual database domain. " .
				"Make sure to apply the database updates found in extensions/MediaModeration/schema to the DB table."
			);
			return;
		}

		$base = __DIR__ . '/../../../schema';
		$maintenanceDb = $updater->getDB();
		$dbType = $maintenanceDb->getType();
		$updater->addExtensionTable( 'mediamoderation_scan', "$base/$dbType/tables-generated.sql" );
	}
}
