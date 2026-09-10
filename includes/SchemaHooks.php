<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {
	/**
	 * Create the two tables when update.php runs.
	 *
	 * @inheritDoc
	 */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$sql = dirname( __DIR__ ) . '/sql/tables.sql';
		$updater->addExtensionTable( 'asyntai_settings', $sql );
		$updater->addExtensionTable( 'asyntai_pages', $sql );
	}
}
