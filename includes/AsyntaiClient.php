<?php
/**
 * Asyntai AI Chatbot extension for MediaWiki.
 *
 * @copyright (c) 2026 Asyntai <https://asyntai.com>
 * @license GPL-2.0-or-later
 */

namespace MediaWiki\Extension\AsyntaiChatbot;

use MediaWiki\Http\HttpRequestFactory;

/**
 * The calls this extension makes to the Asyntai API.
 */
class AsyntaiClient {
	/** @var HttpRequestFactory */
	private $requestFactory;

	/** @var string */
	private $apiBase;

	/** @var string */
	private $apiKey;

	public function __construct( HttpRequestFactory $requestFactory, string $apiBase, string $apiKey ) {
		$this->requestFactory = $requestFactory;
		$this->apiBase = rtrim( $apiBase, '/' );
		$this->apiKey = $apiKey;
	}

	/**
	 * Add one text entry to the knowledge base.
	 *
	 * @return array{code:int,body:string,id:string,error:string}
	 */
	public function addText( string $title, string $content, string $websiteId ): array {
		$payload = [ 'title' => $title, 'content' => $content ];
		if ( $websiteId !== '' ) {
			$payload['website_id'] = $websiteId;
		}
		$result = $this->call( 'POST', '/api/v1/knowledge/text/', $payload );
		$result['id'] = '';
		if ( $result['code'] === 200 ) {
			$data = json_decode( $result['body'], true );
			if ( is_array( $data ) && isset( $data['id'] ) ) {
				$result['id'] = (string)$data['id'];
			}
		}
		return $result;
	}

	/**
	 * Remove one entry from the knowledge base.
	 *
	 * @return array{code:int,body:string,error:string}
	 */
	public function deleteEntry( string $id ): array {
		return $this->call( 'DELETE', '/api/v1/knowledge/' . rawurlencode( $id ) . '/', null );
	}

	/**
	 * @return array{code:int,body:string,error:string}
	 */
	private function call( string $method, string $path, ?array $payload ): array {
		$options = [
			'method' => $method,
			'timeout' => 60,
			'connectTimeout' => 15,
		];
		if ( $payload !== null ) {
			$options['postData'] = json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		$request = $this->requestFactory->create( $this->apiBase . $path, $options, __METHOD__ );
		$request->setHeader( 'Authorization', 'Bearer ' . $this->apiKey );
		$request->setHeader( 'Accept', 'application/json' );
		if ( $payload !== null ) {
			$request->setHeader( 'Content-Type', 'application/json' );
		}
		$status = $request->execute();
		$code = (int)$request->getStatus();
		$body = (string)$request->getContent();
		$error = '';
		if ( $code === 0 ) {
			// Nothing came back at all: DNS, TLS or a refused connection.
			$error = $status->isOK() ? 'no response' : implode( '; ', array_map(
				static function ( $e ) {
					return is_array( $e ) ? ( $e['message'] ?? 'error' ) : (string)$e;
				},
				$status->getErrors()
			) );
		}
		return [ 'code' => $code, 'body' => $body, 'error' => $error ];
	}
}
