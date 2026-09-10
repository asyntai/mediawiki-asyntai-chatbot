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
use MediaWiki\Extension\AsyntaiChatbot\PageSync;
use MediaWiki\Extension\AsyntaiChatbot\Settings;
use MediaWiki\MediaWikiServices;

/**
 * Sends one page to Asyntai, or removes it when the page is gone.
 */
class SyncPageJob extends Job implements GenericParameterJob {
	public function __construct( array $params ) {
		parent::__construct( 'asyntaiSyncPage', $params );
		// Five saves of one page in a row queue one job, not five.
		$this->removeDuplicates = true;
	}

	public static function newForPage( int $pageId ): self {
		return new self( [ 'pageId' => $pageId ] );
	}

	/**
	 * @inheritDoc
	 */
	public function run() {
		$loadBalancer = MediaWikiServices::getInstance()->getDBLoadBalancer();
		$sync = new PageSync( $loadBalancer, new Settings( $loadBalancer ) );
		$result = $sync->syncPage( (int)$this->params['pageId'] );
		if ( $result === PageSync::RETRY ) {
			// The queue tries again later; the error is on the settings page.
			$this->setLastError( 'Asyntai sync deferred' );
			return false;
		}
		return true;
	}
}
