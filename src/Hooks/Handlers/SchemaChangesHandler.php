<?php

namespace MediaWiki\Extension\MediaModeration\Hooks\Handlers;

use MediaWiki\Config\ConfigException;
use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaChangesHandler implements LoadExtensionSchemaUpdatesHook {
	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		// The config cannot be injected via dependency injection
		// as the service locator is not available when running
		// this hook.
		global $wgVirtualDomainsMapping;
		if ( !is_array( $wgVirtualDomainsMapping ) ) {
			// If $wgVirtualDomainsMapping is invalid, say so.
			throw new ConfigException(
				'The VirtualDomainsMapping config value is not an array. ' .
				'The config must be an array and as such is invalid.'
			);
		}
		// Work out if the mediamoderation_scan table is configured to be
		// stored on any DB other than the local DB. If this is the case,
		// then don't use the DatabaseUpdater as it cannot support updates
		// to any DB than the local DB.
		$usesLocalDb = false;
		if ( isset( $wgVirtualDomainsMapping['virtual-mediamoderation'] ) ) {
			$config = $wgVirtualDomainsMapping['virtual-mediamoderation'];
			// If 'cluster' is defined, an external DB is being used.
			// Any value other than the boolean false for 'db' indicates
			// a non-local DB.
			if (
				!isset( $config['cluster'] ) &&
				isset( $config['db'] ) &&
				$config['db'] === false
			) {
				$usesLocalDb = true;
			}
		} else {
			$usesLocalDb = true;
		}
		if ( !$usesLocalDb ) {
			// DatabaseUpdater does not support other databases, so skip
			$updater->output(
				"Unable to perform DB updates for MediaModeration as the table is on an virtual database domain. " .
				"Make sure to apply the database updates found in extensions/MediaModeration/schema to the DB table."
			);
			return;
		}

		// @codeCoverageIgnoreStart This is tested by installing or updating MediaWiki
		$base = __DIR__ . '/../../../schema';
		$maintenanceDb = $updater->getDB();
		$dbType = $maintenanceDb->getType();
		$updater->addExtensionTable( 'mediamoderation_scan', "$base/$dbType/tables-generated.sql" );
		// @codeCoverageIgnoreEnd This is tested by installing or updating MediaWiki
	}
}
