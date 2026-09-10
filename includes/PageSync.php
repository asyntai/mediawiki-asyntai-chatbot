<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot;

use MediaWiki\MediaWikiServices;
use WikiPage;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Sends one wiki page to the Asyntai knowledge base, or removes it.
 *
 * The asyntai_pages table remembers which knowledge base entry each page
 * became and at which revision, so a page is sent again only when it changed
 * and a deleted page is removed from Asyntai as well.
 */
class PageSync {
	/** Result of one sync call. */
	public const SENT = 'sent';
	public const UNCHANGED = 'unchanged';
	public const SKIPPED = 'skipped';
	public const RETRY = 'retry';
	public const FAILED = 'failed';

	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var Settings */
	private $settings;

	/** @var AsyntaiClient */
	private $client;

	/** @var int */
	private $minChars;

	/** @var int */
	private $maxChars;

	public function __construct( ILoadBalancer $loadBalancer, Settings $settings ) {
		$this->loadBalancer = $loadBalancer;
		$this->settings = $settings;
		$services = MediaWikiServices::getInstance();
		$config = $services->getMainConfig();
		$this->client = new AsyntaiClient(
			$services->getHttpRequestFactory(),
			(string)$config->get( 'AsyntaiApiBase' ),
			$settings->getApiKey()
		);
		$this->minChars = (int)$config->get( 'AsyntaiSyncMinChars' );
		$this->maxChars = (int)$config->get( 'AsyntaiSyncMaxChars' );
	}

	/**
	 * Send one page. Returns one of the result constants.
	 */
	public function syncPage( int $pageId ): string {
		if ( !$this->settings->isSyncEnabled() ) {
			return self::SKIPPED;
		}
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromID( $pageId );
		if ( !$page || !$page->exists() ) {
			return $this->removePage( $pageId ) ? self::SENT : self::SKIPPED;
		}
		$title = $page->getTitle();
		if ( !in_array( $title->getNamespace(), $this->settings->getNamespaces(), true )
			|| $page->isRedirect()
		) {
			// Moved out of scope, or turned into a redirect: drop the old copy.
			return $this->removePage( $pageId ) ? self::SENT : self::SKIPPED;
		}

		$known = $this->getTracked( $pageId );
		$revId = (int)$page->getLatest();
		if ( $known && (int)$known->ap_rev === $revId ) {
			return self::UNCHANGED;
		}

		$text = $this->extractText( $page );
		if ( mb_strlen( $text ) < $this->minChars ) {
			// A stub. If an older, longer version was sent, remove it.
			$this->removePage( $pageId );
			return self::SKIPPED;
		}
		if ( mb_strlen( $text ) > $this->maxChars ) {
			$text = mb_substr( $text, 0, $this->maxChars );
		}

		$name = $title->getPrefixedText();
		$body = 'Wiki page: ' . $name . "\n"
			. 'Link: ' . $title->getFullURL() . "\n\n"
			. $text;

		// There is no update call, so a changed page is a delete and then an
		// add. The delete runs first: a failed add must not leave two copies.
		if ( $known && $known->ap_kb_id !== '' ) {
			$this->client->deleteEntry( $known->ap_kb_id );
			$this->untrack( $pageId );
		}

		$result = $this->client->addText( mb_substr( $name, 0, 200 ), $body, $this->settings->getWebsiteId() );
		if ( $result['code'] === 200 && $result['id'] !== '' ) {
			$this->track( $pageId, $result['id'], $revId );
			$this->settings->set( 'last_sync', wfTimestampNow() );
			$this->settings->set( 'last_error', '' );
			return self::SENT;
		}
		return $this->recordFailure( $result );
	}

	/**
	 * Remove a page from the knowledge base. True when something was removed.
	 */
	public function removePage( int $pageId ): bool {
		$known = $this->getTracked( $pageId );
		if ( !$known ) {
			return false;
		}
		if ( $known->ap_kb_id !== '' && $this->settings->getApiKey() !== '' ) {
			$this->client->deleteEntry( $known->ap_kb_id );
		}
		$this->untrack( $pageId );
		return true;
	}

	public function countTracked(): int {
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		return (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'asyntai_pages' )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * The rendered page as plain text, one paragraph per line.
	 */
	private function extractText( WikiPage $page ): string {
		$services = MediaWikiServices::getInstance();
		$options = \ParserOptions::newFromAnon();
		$status = $services->getParserOutputAccess()->getParserOutput( $page, $options );
		if ( !$status->isOK() ) {
			return '';
		}
		$output = $status->getValue();
		$htmlOptions = [ 'allowTOC' => false, 'enableSectionEditLinks' => false ];
		if ( method_exists( $output, 'runOutputPipeline' ) ) {
			// MediaWiki 1.42 and later.
			$html = $output->runOutputPipeline( $options, $htmlOptions )->getContentHolderText();
		} else {
			$html = $output->getText( $htmlOptions );
		}
		// Keep the block structure as line breaks before the tags go.
		// Sanitizer::stripAllTags would fold every newline into one space, so
		// headings and paragraphs would run into each other.
		$html = preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', '', $html );
		$html = preg_replace( '#</(p|div|li|h[1-6]|tr|dt|dd|blockquote|pre|table|caption)>#i', "$0\n", $html );
		$html = preg_replace( '#</(td|th)>#i', "$0 ", $html );
		$html = preg_replace( '#<br\s*/?>#i', "\n", $html );
		$text = strip_tags( $html );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = preg_replace( '/ *\n */', "\n", $text );
		$text = preg_replace( '/\n{3,}/', "\n\n", $text );
		return trim( $text );
	}

	/**
	 * @return string One of the result constants
	 */
	private function recordFailure( array $result ): string {
		$code = $result['code'];
		if ( $code === 429 ) {
			$this->settings->set( 'last_error', wfMessage( 'asyntai-error-limit' )->inContentLanguage()->text() );
			return self::RETRY;
		}
		if ( $code === 401 || $code === 403 ) {
			$this->settings->set( 'last_error', wfMessage( 'asyntai-error-auth', $code )->inContentLanguage()->text() );
			return self::FAILED;
		}
		if ( $code === 0 ) {
			$this->settings->set( 'last_error', wfMessage( 'asyntai-error-network', $result['error'] )->inContentLanguage()->text() );
			return self::RETRY;
		}
		$detail = mb_substr( trim( strip_tags( $result['body'] ) ), 0, 300 );
		$this->settings->set( 'last_error', wfMessage( 'asyntai-error-http', $code, $detail )->inContentLanguage()->text() );
		return $code >= 500 ? self::RETRY : self::FAILED;
	}

	/**
	 * @return \stdClass|false
	 */
	private function getTracked( int $pageId ) {
		$dbr = $this->loadBalancer->getConnection( DB_PRIMARY );
		return $dbr->newSelectQueryBuilder()
			->select( [ 'ap_kb_id', 'ap_rev' ] )
			->from( 'asyntai_pages' )
			->where( [ 'ap_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchRow();
	}

	private function track( int $pageId, string $kbId, int $revId ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->replace(
			'asyntai_pages',
			'ap_page',
			[
				'ap_page' => $pageId,
				'ap_kb_id' => $kbId,
				'ap_rev' => $revId,
				'ap_synced' => wfTimestampNow(),
			],
			__METHOD__
		);
	}

	private function untrack( int $pageId ): void {
		$dbw = $this->loadBalancer->getConnection( DB_PRIMARY );
		$dbw->delete( 'asyntai_pages', [ 'ap_page' => $pageId ], __METHOD__ );
	}
}
