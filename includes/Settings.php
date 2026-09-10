<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot;

use Wikimedia\Rdbms\ILoadBalancer;

/**
 * The settings an administrator saves on Special:AsyntaiChatbot.
 *
 * They live in the asyntai_settings table, so no one has to edit
 * LocalSettings.php. The API key sits in the database only; it is never
 * printed back into a form.
 */
class Settings {
	/** Shape of a widget identifier. */
	public const ID_PATTERN = '/^asyntai_[A-Za-z0-9]{6,64}$/';

	private const KEYS = [
		'widget_id', 'api_key', 'sync_enabled', 'namespaces', 'website_id',
		'last_error', 'last_sync',
	];

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var array<string,string>|null */
	private $cache = null;

	public function __construct( ILoadBalancer $loadBalancer ) {
		$this->loadBalancer = $loadBalancer;
	}

	/**
	 * @return array<string,string> Every stored value, by key
	 */
	public function all(): array {
		if ( $this->cache !== null ) {
			return $this->cache;
		}
		$values = [];
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'as_key', 'as_value' ] )
			->from( 'asyntai_settings' )
			->caller( __METHOD__ )
			->fetchResultSet();
		foreach ( $res as $row ) {
			$values[$row->as_key] = (string)$row->as_value;
		}
		$this->cache = $values;
		return $values;
	}

	public function get( string $key, string $default = '' ): string {
		$all = $this->all();
		return array_key_exists( $key, $all ) ? $all[$key] : $default;
	}

	public function set( string $key, string $value ): void {
		if ( !in_array( $key, self::KEYS, true ) ) {
			throw new \InvalidArgumentException( "Unknown Asyntai setting: $key" );
		}
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->replace(
			'asyntai_settings',
			'as_key',
			[ 'as_key' => $key, 'as_value' => $value ],
			__METHOD__
		);
		if ( $this->cache !== null ) {
			$this->cache[$key] = $value;
		}
	}

	public function getWidgetId(): string {
		return self::readWidgetId( $this->get( 'widget_id' ) );
	}

	public function getApiKey(): string {
		return trim( $this->get( 'api_key' ) );
	}

	public function isSyncEnabled(): bool {
		return $this->get( 'sync_enabled' ) === '1' && $this->getApiKey() !== '';
	}

	/**
	 * @return int[] Namespace numbers whose pages are sent
	 */
	public function getNamespaces(): array {
		return self::readNamespaces( $this->get( 'namespaces', '0' ) );
	}

	public function getWebsiteId(): string {
		return trim( $this->get( 'website_id' ) );
	}

	/**
	 * Read a widget identifier from a stored or typed value.
	 *
	 * Accepts either a bare identifier or the whole snippet from the Asyntai
	 * dashboard. Returns an empty string when there is nothing usable, which
	 * is how the chat stays off until the administrator fills the field in.
	 */
	public static function readWidgetId( ?string $raw ): string {
		if ( $raw === null ) {
			return '';
		}
		$value = trim( html_entity_decode( $raw, ENT_QUOTES ) );
		if ( preg_match( '/data-asyntai-id\s*=\s*["\']([^"\']+)["\']/', $value, $m ) ) {
			$value = trim( $m[1] );
		}
		return preg_match( self::ID_PATTERN, $value ) ? $value : '';
	}

	/**
	 * @param string $raw Comma separated namespace numbers
	 * @return int[]
	 */
	public static function readNamespaces( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/[\s,;]+/', $raw ) as $part ) {
			if ( $part !== '' && ctype_digit( $part ) ) {
				$out[] = (int)$part;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
