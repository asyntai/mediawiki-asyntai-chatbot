<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot\Jobs;

use GenericParameterJob;
use Job;
use MediaWiki\Extension\AsyntaiChatbot\Settings;
use MediaWiki\MediaWikiServices;

/**
 * Walks the page table in batches and queues one SyncPageJob per page.
 */
class SyncAllJob extends Job implements GenericParameterJob {
	private const BATCH = 100;

	public function __construct( array $params ) {
		parent::__construct( 'asyntaiSyncAll', $params );
	}

	public static function newFromStart(): self {
		return new self( [ 'afterPage' => 0 ] );
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$services = MediaWikiServices::getInstance();
		$loadBalancer = $services->getDBLoadBalancer();
		$settings = new Settings( $loadBalancer );
		if ( !$settings->isSyncEnabled() ) {
			return true;
		}
		$namespaces = $settings->getNamespaces();
		if ( !$namespaces ) {
			return true;
		}
		$dbr = $loadBalancer->getConnection( DB_REPLICA );
		$after = (int)( $this->params['afterPage'] ?? 0 );
		$ids = $dbr->newSelectQueryBuilder()
			->select( 'page_id' )
			->from( 'page' )
			->where( [
				'page_namespace' => $namespaces,
				'page_is_redirect' => 0,
				'page_id > ' . $after,
			] )
			->orderBy( 'page_id' )
			->limit( self::BATCH )
			->caller( __METHOD__ )
			->fetchFieldValues();
		if ( !$ids ) {
			return true;
		}
		$jobs = [];
		foreach ( $ids as $id ) {
			$jobs[] = SyncPageJob::newForPage( (int)$id );
		}
		$queue = $services->getJobQueueGroup();
		$queue->push( $jobs );
		if ( count( $ids ) === self::BATCH ) {
			$queue->push( new self( [ 'afterPage' => (int)end( $ids ) ] ) );
		}
		return true;
	}
}
