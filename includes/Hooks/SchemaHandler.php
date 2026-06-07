<?php

declare( strict_types=1 );

namespace MediaWiki\Extension\AGGrid\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Installs the aggrid_data and aggrid_source tables. Runs in the installer context,
 * so it must not depend on services.
 */
final class SchemaHandler implements LoadExtensionSchemaUpdatesHook {

	/**
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$type = $updater->getDB()->getType();
		$sqlDir = dirname( __DIR__, 2 ) . "/sql/$type";
		$updater->addExtensionTable( 'aggrid_data', "$sqlDir/tables-generated.sql" );
		$updater->addExtensionTable( 'aggrid_source', "$sqlDir/patch-aggrid_source.sql" );
	}
}
