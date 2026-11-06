<?php
/**
 * Journal des SMS envoyés par le plugin.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect;

defined( 'ABSPATH' ) || exit;

/**
 * Accès à la table {prefix}labyrinthe_sms_log. Toutes les requêtes sont préparées.
 *
 * Statuts : ceux de l'API (queued, submitted, sent, delivered, failed, rejected, expired,
 * undeliverable), plus « error » (refusé par l'API ou injoignable : rien n'est parti) et
 * « otp_sent » (code OTP envoyé : l'API ne renvoie pas d'identifiant de message).
 */
final class Message_Log {

	public const STATUS_ERROR    = 'error';
	public const STATUS_OTP_SENT = 'otp_sent';

	/** Statuts définitifs de l'API. */
	public const FINAL_STATUSES = array( 'delivered', 'failed', 'rejected', 'expired', 'undeliverable' );

	/** Filtres de la recherche : un critère vide est neutralisé par « %s = '' ». */
	private const WHERE = "( %s = '' OR status = %s ) AND ( %s = '' OR context = %s ) AND ( %s = '' OR order_id = %d OR message_id = %s OR error_code = %s OR request_id = %s )";

	/** Colonnes triables. */
	private const SORTABLE = array( 'created_at', 'updated_at', 'status', 'context', 'order_id' );

	/**
	 * Ajoute une ligne.
	 *
	 * @param array<string, scalar> $row Colonnes.
	 * @return int Identifiant de la ligne (0 en cas d'échec).
	 */
	public function insert( array $row ): int {
		global $wpdb;

		$now  = current_time( 'mysql', true );
		$data = array(
			'created_at'    => $now,
			'updated_at'    => $now,
			'message_id'    => substr( (string) ( $row['message_id'] ?? '' ), 0, 64 ),
			'recipient'     => substr( (string) ( $row['recipient'] ?? '' ), 0, 32 ),
			'context'       => substr( (string) ( $row['context'] ?? '' ), 0, 32 ),
			'order_id'      => absint( $row['order_id'] ?? 0 ),
			'status'        => substr( (string) ( $row['status'] ?? '' ), 0, 20 ),
			'segments'      => absint( $row['segments'] ?? 0 ),
			'encoding'      => substr( (string) ( $row['encoding'] ?? '' ), 0, 8 ),
			'error_code'    => substr( (string) ( $row['error_code'] ?? '' ), 0, 64 ),
			'error_message' => mb_substr( (string) ( $row['error_message'] ?? '' ), 0, 255 ),
			'test_mode'     => empty( $row['test_mode'] ) ? 0 : 1,
			'request_id'    => substr( (string) ( $row['request_id'] ?? '' ), 0, 128 ),
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- table du plugin.
		$inserted = $wpdb->insert( Schema::log_table(), $data, array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%s' ) );

		return false === $inserted ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Une ligne par identifiant.
	 *
	 * @param int $id Identifiant.
	 * @return array<string, string>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::log_table(), $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * La ligne d'un message de l'API.
	 *
	 * @param string $message_id Identifiant du message (API).
	 * @return array<string, string>|null
	 */
	public function find_by_message_id( string $message_id ): ?array {
		global $wpdb;

		if ( '' === $message_id ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE message_id = %s ORDER BY id DESC LIMIT 1', Schema::log_table(), $message_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Met à jour le statut d'un message reçu par webhook (et son erreur, en cas d'échec).
	 *
	 * L'ordre d'arrivée des webhooks n'est pas garanti : un statut définitif n'est jamais
	 * remplacé par un statut intermédiaire (« sent » arrivé après « delivered »).
	 *
	 * @param string      $message_id    Identifiant du message (API).
	 * @param string      $status        Nouveau statut.
	 * @param string|null $error_code    Code d'erreur du fournisseur ou de la plateforme (webhook), ou null.
	 * @param string|null $error_message Message d'erreur, ou null.
	 * @return array<string, string>|null La ligne mise à jour, ou null si le message n'est pas dans le journal.
	 */
	public function update_status( string $message_id, string $status, ?string $error_code = null, ?string $error_message = null ): ?array {
		global $wpdb;

		$row = $this->find_by_message_id( $message_id );
		if ( null === $row ) {
			return null;
		}

		$status = substr( sanitize_key( $status ), 0, 20 );
		if ( $row['status'] === $status || ( in_array( $row['status'], self::FINAL_STATUSES, true ) && ! in_array( $status, self::FINAL_STATUSES, true ) ) ) {
			return $row;
		}

		$data = array(
			'status'     => $status,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( null !== $error_code && '' !== $error_code ) {
			$data['error_code']    = substr( sanitize_text_field( $error_code ), 0, 64 );
			$data['error_message'] = mb_substr( sanitize_text_field( (string) $error_message ), 0, 255 );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		$wpdb->update( Schema::log_table(), $data, array( 'id' => (int) $row['id'] ), array_fill( 0, count( $data ), '%s' ), array( '%d' ) );

		return $this->find( (int) $row['id'] );
	}

	/**
	 * Recherche paginée pour la page d'administration.
	 *
	 * @param array{status?: string, context?: string, search?: string, orderby?: string, order?: string, per_page?: int, page?: int} $args Critères.
	 * @return array{items: list<array<string, string>>, total: int}
	 */
	public function query( array $args ): array {
		global $wpdb;

		$status  = (string) ( $args['status'] ?? '' );
		$context = (string) ( $args['context'] ?? '' );
		$search  = trim( (string) ( $args['search'] ?? '' ) );
		// Recherche d'une commande (« 1042 » ou « #1042 ») ; -1 ne correspond à aucune ligne.
		$order_id = '' !== $search && ctype_digit( ltrim( $search, '#' ) ) ? (int) ltrim( $search, '#' ) : -1;

		$orderby  = in_array( $args['orderby'] ?? '', self::SORTABLE, true ) ? (string) $args['orderby'] : 'created_at';
		$asc      = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) );
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

		// Requêtes fixes (self::WHERE) ; table et colonne de tri passent par %i.
		$filters = array( $status, $status, $context, $context, $search, $order_id, $search, strtoupper( $search ), $search );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- table du plugin ; requêtes constantes.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . self::WHERE, array_merge( array( Schema::log_table() ), $filters ) ) );
		$items = $wpdb->get_results(
			$wpdb->prepare(
				$asc
					? 'SELECT * FROM %i WHERE ' . self::WHERE . ' ORDER BY %i ASC, id ASC LIMIT %d OFFSET %d'
					: 'SELECT * FROM %i WHERE ' . self::WHERE . ' ORDER BY %i DESC, id DESC LIMIT %d OFFSET %d',
				array_merge( array( Schema::log_table() ), $filters, array( $orderby, $per_page, ( $page - 1 ) * $per_page ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? array_values( $items ) : array(),
			'total' => $total,
		);
	}

	/**
	 * Supprime les lignes plus anciennes que $days jours.
	 *
	 * @param int $days Durée de conservation.
	 * @return int Lignes supprimées.
	 */
	public function purge( int $days ): int {
		global $wpdb;

		if ( $days <= 0 ) {
			return 0;
		}
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table du plugin.
		$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', Schema::log_table(), $limit ) );

		return (int) $deleted;
	}
}
