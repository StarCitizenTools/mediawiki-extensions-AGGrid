<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Installs the aggrid_data table. Runs in the installer context, so it must not
 * depend on services.
 */
final class SchemaHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$updater->addExtensionTable(
			'aggrid_data',
			dirname( __DIR__, 2 ) . "/sql/$type/tables-generated.sql"
		);
	}
}
