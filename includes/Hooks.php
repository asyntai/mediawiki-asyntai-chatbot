<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot;

use JobQueueGroup;
use MediaWiki\Extension\AsyntaiChatbot\Jobs\SyncPageJob;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\MediaWikiServices;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use Wikimedia\Rdbms\ILoadBalancer;

class Hooks implements BeforePageDisplayHook, PageSaveCompleteHook, PageDeleteCompleteHook {
	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var JobQueueGroup */
	private $jobQueueGroup;

	/** @var Settings|null */
	private $settings = null;

	public function __construct( ILoadBalancer $loadBalancer, JobQueueGroup $jobQueueGroup ) {
		$this->loadBalancer = $loadBalancer;
		$this->jobQueueGroup = $jobQueueGroup;
	}

	private function settings(): Settings {
		if ( $this->settings === null ) {
			$this->settings = new Settings( $this->loadBalancer );
		}
		return $this->settings;
	}

	/**
	 * Put the widget script on every page.
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$widgetId = $this->settings()->getWidgetId();
		if ( $widgetId === '' ) {
			return;
		}
		$scriptUrl = (string)MediaWikiServices::getInstance()->getMainConfig()->get( 'AsyntaiScriptUrl' );
		if ( !preg_match( '#^https?://#i', $scriptUrl ) ) {
			return;
		}
		$out->addHeadItem( 'asyntai-chatbot', \Html::element( 'script', [
			'src' => $scriptUrl,
			'async' => true,
			'data-asyntai-id' => $widgetId,
		] ) );
	}

	/**
	 * A saved page goes to the knowledge base in the background.
	 *
	 * @inheritDoc
	 */
	public function onPageSaveComplete( $wikiPage, $user, $summary, $flags, $revisionRecord, $editResult ) {
		$settings = $this->settings();
		if ( !$settings->isSyncEnabled() ) {
			return;
		}
		$title = $wikiPage->getTitle();
		if ( !in_array( $title->getNamespace(), $settings->getNamespaces(), true ) ) {
			return;
		}
		$this->jobQueueGroup->lazyPush( SyncPageJob::newForPage( (int)$wikiPage->getId() ) );
	}

	/**
	 * A deleted page leaves the knowledge base too.
	 *
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		$page, $deleter, string $reason, int $pageID, $deletedRev, $logEntry, int $archivedRevisionCount
	) {
		if ( $this->settings()->getApiKey() === '' ) {
			return;
		}
		$this->jobQueueGroup->lazyPush( SyncPageJob::newForPage( $pageID ) );
	}
}
