<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot;

use JobQueueGroup;
use MediaWiki\Extension\AsyntaiChatbot\Jobs\SyncAllJob;
use Wikimedia\Rdbms\ILoadBalancer;

/**
 * Special:AsyntaiChatbot, the settings page.
 */
class SpecialAsyntaiChatbot extends \SpecialPage {
	/** @var ILoadBalancer */
	private $loadBalancer;

	/** @var JobQueueGroup */
	private $jobQueueGroup;

	/** @var Settings */
	private $settings;

	public function __construct( ILoadBalancer $loadBalancer, JobQueueGroup $jobQueueGroup ) {
		parent::__construct( 'AsyntaiChatbot', 'asyntai-manage' );
		$this->loadBalancer = $loadBalancer;
		$this->jobQueueGroup = $jobQueueGroup;
		$this->settings = new Settings( $loadBalancer );
	}

	/**
	 * @inheritDoc
	 */
	protected function getGroupName() {
		return 'wiki';
	}

	/**
	 * @inheritDoc
	 */
	public function execute( $subPage ) {
		$this->setHeaders();
		$this->checkPermissions();
		$this->outputHeader( 'asyntai-intro' );
		$out = $this->getOutput();
		$request = $this->getRequest();

		if ( $request->wasPosted() && $request->getVal( 'asyntai_action' ) === 'syncall'
			&& $this->getUser()->matchEditToken( $request->getVal( 'wpEditToken' ) )
		) {
			$this->queueAll();
		}

		$form = \HTMLForm::factory( 'ooui', $this->formFields(), $this->getContext(), 'asyntai' );
		$form->setSubmitTextMsg( 'asyntai-save' );
		$form->setSubmitCallback( [ $this, 'onSave' ] );
		$form->setId( 'asyntai-settings-form' );
		// The sync-all button posts to the same page. Without an identifier,
		// HTMLForm would read that post as a save with every field empty.
		$form->setFormIdentifier( 'asyntai-settings' );
		if ( $form->show() ) {
			$out->addHTML( \Html::successBox( $this->msg( 'asyntai-saved' )->escaped() ) );
		}

		$this->showStatus();
	}

	private function formFields(): array {
		$s = $this->settings;
		return [
			'widget_id' => [
				'section' => 'widget',
				'type' => 'text',
				'label-message' => 'asyntai-field-widget-id',
				'help-message' => 'asyntai-field-widget-id-help',
				'default' => $s->getWidgetId(),
				'placeholder' => 'asyntai_xxxxxxxxxxxx',
				'validation-callback' => static function ( $value ) {
					$value = trim( (string)$value );
					if ( $value === '' || Settings::readWidgetId( $value ) !== '' ) {
						return true;
					}
					return wfMessage( 'asyntai-field-widget-id-invalid' )->text();
				},
			],
			'api_key' => [
				'section' => 'sync',
				'type' => 'password',
				'label-message' => 'asyntai-field-api-key',
				'help-message' => 'asyntai-field-api-key-help',
				'default' => '',
				'autocomplete' => 'off',
				'placeholder' => $this->msg(
					$s->getApiKey() !== '' ? 'asyntai-field-api-key-stored' : 'asyntai-field-api-key-none'
				)->text(),
			],
			'sync_enabled' => [
				'section' => 'sync',
				'type' => 'check',
				'label-message' => 'asyntai-field-sync-enabled',
				'help-message' => 'asyntai-field-sync-enabled-help',
				'default' => $s->get( 'sync_enabled' ) === '1',
			],
			'namespaces' => [
				'section' => 'sync',
				'type' => 'text',
				'label-message' => 'asyntai-field-namespaces',
				'help-message' => 'asyntai-field-namespaces-help',
				'default' => implode( ', ', $s->getNamespaces() ),
			],
			'website_id' => [
				'section' => 'sync',
				'type' => 'text',
				'label-message' => 'asyntai-field-website-id',
				'help-message' => 'asyntai-field-website-id-help',
				'default' => $s->getWebsiteId(),
			],
		];
	}

	/**
	 * @param array $data Form values
	 * @return bool
	 */
	public function onSave( array $data ): bool {
		$s = $this->settings;
		$s->set( 'widget_id', Settings::readWidgetId( (string)$data['widget_id'] ) );
		$key = trim( (string)$data['api_key'] );
		if ( $key !== '' ) {
			$s->set( 'api_key', $key );
			$s->set( 'last_error', '' );
		}
		$s->set( 'sync_enabled', $data['sync_enabled'] ? '1' : '0' );
		$s->set( 'namespaces', implode( ',', Settings::readNamespaces( (string)$data['namespaces'] ) ) );
		$s->set( 'website_id', trim( (string)$data['website_id'] ) );
		return true;
	}

	private function queueAll(): void {
		$out = $this->getOutput();
		if ( !$this->settings->isSyncEnabled() ) {
			$out->addHTML( \Html::errorBox( $this->msg( 'asyntai-sync-needs-key' )->escaped() ) );
			return;
		}
		$dbr = $this->loadBalancer->getConnection( DB_REPLICA );
		$count = (int)$dbr->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'page' )
			->where( [ 'page_namespace' => $this->settings->getNamespaces(), 'page_is_redirect' => 0 ] )
			->caller( __METHOD__ )
			->fetchField();
		$this->jobQueueGroup->push( SyncAllJob::newFromStart() );
		$out->addHTML( \Html::successBox(
			$this->msg( 'asyntai-sync-all-queued' )->numParams( $count )->escaped()
		) );
	}

	private function showStatus(): void {
		$out = $this->getOutput();
		$s = $this->settings;
		$sync = new PageSync( $this->loadBalancer, $s );
		$queued = $this->jobQueueGroup->get( 'asyntaiSyncPage' )->getSize();
		$lastSync = $s->get( 'last_sync' );
		$lastSyncText = $lastSync === ''
			? $this->msg( 'asyntai-status-never' )->text()
			: $this->getLanguage()->userTimeAndDate( $lastSync, $this->getUser() );

		$html = \Html::element( 'h2', [], $this->msg( 'asyntai-status-heading' )->text() );
		$lines = [
			$this->msg( 'asyntai-status-tracked' )->numParams( $sync->countTracked() )->escaped(),
			$this->msg( 'asyntai-status-queued' )->numParams( $queued )->escaped(),
			$this->msg( 'asyntai-status-last-sync', $lastSyncText )->escaped(),
		];
		$lastError = $s->get( 'last_error' );
		if ( $lastError !== '' ) {
			$lines[] = \Html::rawElement( 'span', [ 'class' => 'error' ],
				$this->msg( 'asyntai-status-last-error', $lastError )->escaped() );
		}
		$html .= \Html::rawElement( 'ul', [], implode( '', array_map( static function ( $line ) {
			return \Html::rawElement( 'li', [], $line );
		}, $lines ) ) );

		$html .= \Html::rawElement( 'form', [
			'method' => 'post',
			'action' => $this->getPageTitle()->getLocalURL(),
			'style' => 'margin-top: 1em;',
		],
			\Html::hidden( 'asyntai_action', 'syncall' )
			. \Html::hidden( 'wpEditToken', $this->getUser()->getEditToken() )
			. \Html::submitButton( $this->msg( 'asyntai-sync-all' )->text(), [] )
			. \Html::element( 'p', [ 'class' => 'mw-help-field-hint', 'style' => 'margin-top: 0.5em;' ],
				$this->msg( 'asyntai-sync-all-help' )->text() )
		);
		$out->addHTML( $html );
	}
}
