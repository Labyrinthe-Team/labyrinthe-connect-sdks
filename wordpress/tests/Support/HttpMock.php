<?php
/**
 * Serveur HTTP simulé : intercepte wp_remote_request() (filtre pre_http_request).
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Tests\Support;

/**
 * Les réponses sont servies dans l'ordre ; toute requête inattendue fait échouer le test.
 */
final class HttpMock {

	/**
	 * Requêtes reçues : method, url, headers, body (JSON décodé).
	 *
	 * @var list<array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}>
	 */
	public array $requests = array();

	/**
	 * Réponses à servir.
	 *
	 * @var list<array{status: int, body: array<mixed>|string, headers: array<string, string>}|\WP_Error>
	 */
	private array $queue = array();

	public function install(): void {
		add_filter( 'pre_http_request', array( $this, 'handle' ), 10, 3 );
	}

	public function uninstall(): void {
		remove_filter( 'pre_http_request', array( $this, 'handle' ), 10 );
	}

	/**
	 * Ajoute une réponse JSON.
	 *
	 * @param int                   $status  Statut HTTP.
	 * @param array<mixed>|string   $body    Corps (tableau encodé en JSON).
	 * @param array<string, string> $headers En-têtes.
	 */
	public function push( int $status, $body, array $headers = array() ): self {
		$this->queue[] = array(
			'status'  => $status,
			'body'    => $body,
			'headers' => $headers + array( 'content-type' => 'application/json' ),
		);

		return $this;
	}

	/**
	 * Ajoute une erreur API au format { error: { code, message, details } }.
	 *
	 * @param int                   $status  Statut HTTP.
	 * @param string                $code    Code d'erreur.
	 * @param array<mixed>          $details Détails.
	 * @param array<string, string> $headers En-têtes.
	 */
	public function push_error( int $status, string $code, array $details = array(), array $headers = array() ): self {
		return $this->push(
			$status,
			array(
				'error' => array(
					'code'    => $code,
					'message' => 'Erreur ' . $code,
					'details' => (object) $details,
				),
			),
			$headers
		);
	}

	/**
	 * Ajoute une erreur réseau (WP_Error).
	 *
	 * @param string $message Message.
	 */
	public function push_network_error( string $message = 'cURL error 7: Failed to connect' ): self {
		$this->queue[] = new \WP_Error( 'http_request_failed', $message );

		return $this;
	}

	public function pending(): int {
		return count( $this->queue );
	}

	/**
	 * Dernière requête.
	 *
	 * @return array{method: string, url: string, headers: array<string, string>, body: array<mixed>|null}
	 * @throws \RuntimeException Aucune requête.
	 */
	public function last(): array {
		if ( array() === $this->requests ) {
			throw new \RuntimeException( 'No HTTP request was made.' );
		}

		return $this->requests[ count( $this->requests ) - 1 ];
	}

	/**
	 * Filtre pre_http_request.
	 *
	 * @param mixed                $preempt Valeur actuelle.
	 * @param array<string, mixed> $args    Arguments de la requête.
	 * @param string               $url     URL.
	 * @return array<string, mixed>|\WP_Error
	 * @throws \RuntimeException Requête inattendue.
	 */
	public function handle( $preempt, $args, $url ) {
		// Requêtes d'autres extensions (WooCommerce…) : réponse vide, jamais de réseau ; seule l'API est simulée et enregistrée.
		if ( false === strpos( (string) $url, '/api/public/v1/' ) ) {
			return array(
				'headers'  => array(),
				'body'     => '{}',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		}
		$headers = array();
		foreach ( (array) ( $args['headers'] ?? array() ) as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = (string) $value;
		}
		$body             = isset( $args['body'] ) && is_string( $args['body'] ) ? json_decode( $args['body'], true ) : null;
		$this->requests[] = array(
			'method'  => (string) ( $args['method'] ?? 'GET' ),
			'url'     => (string) $url,
			'headers' => $headers,
			'body'    => is_array( $body ) ? $body : null,
		);

		$response = array_shift( $this->queue );
		if ( null === $response ) {
			throw new \RuntimeException( 'Unexpected HTTP request: ' . esc_url_raw( $url ) );
		}
		if ( $response instanceof \WP_Error ) {
			return $response;
		}

		return array(
			'headers'  => $response['headers'],
			'body'     => is_string( $response['body'] ) ? $response['body'] : (string) wp_json_encode( $response['body'] ),
			'response' => array(
				'code'    => $response['status'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
