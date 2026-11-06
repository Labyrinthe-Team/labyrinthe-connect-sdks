<?php
/**
 * Tableau du journal des SMS.
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Admin;

use LabyrintheConnect\Message_Log;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Liste paginée (côté serveur), triable et filtrable des SMS envoyés par le plugin.
 */
final class Log_List_Table extends \WP_List_Table {

	/**
	 * Journal.
	 *
	 * @var Message_Log
	 */
	private Message_Log $log;

	/**
	 * Constructeur.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'labyrinthe-sms',
				'plural'   => 'labyrinthe-sms-log',
				'ajax'     => false,
			)
		);
		$this->log = new Message_Log();
	}

	/**
	 * Libellés des statuts.
	 *
	 * @return array<string, string>
	 */
	public static function status_labels(): array {
		return array(
			'queued'                     => __( 'Queued', 'labyrinthe-connect' ),
			'submitted'                  => __( 'Submitted', 'labyrinthe-connect' ),
			'sent'                       => __( 'Sent', 'labyrinthe-connect' ),
			'delivered'                  => __( 'Delivered', 'labyrinthe-connect' ),
			'failed'                     => __( 'Failed', 'labyrinthe-connect' ),
			'rejected'                   => __( 'Rejected', 'labyrinthe-connect' ),
			'expired'                    => __( 'Expired', 'labyrinthe-connect' ),
			'undeliverable'              => __( 'Undeliverable', 'labyrinthe-connect' ),
			Message_Log::STATUS_ERROR    => __( 'Not sent', 'labyrinthe-connect' ),
			Message_Log::STATUS_OTP_SENT => __( 'Code sent', 'labyrinthe-connect' ),
		);
	}

	/**
	 * Libellés des contextes.
	 *
	 * @return array<string, string>
	 */
	public static function context_labels(): array {
		return array(
			'order_status'    => __( 'Order status', 'labyrinthe-connect' ),
			'admin_new_order' => __( 'New order (shop manager)', 'labyrinthe-connect' ),
			'test'            => __( 'Test SMS', 'labyrinthe-connect' ),
			'otp_login'       => __( 'OTP: login', 'labyrinthe-connect' ),
			'otp_register'    => __( 'OTP: registration', 'labyrinthe-connect' ),
			'otp_checkout'    => __( 'OTP: checkout', 'labyrinthe-connect' ),
		);
	}

	/**
	 * Colonnes.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'created_at' => __( 'Date', 'labyrinthe-connect' ),
			'recipient'  => __( 'Recipient', 'labyrinthe-connect' ),
			'context'    => __( 'Type', 'labyrinthe-connect' ),
			'status'     => __( 'Status', 'labyrinthe-connect' ),
			'order_id'   => __( 'Order', 'labyrinthe-connect' ),
			'segments'   => __( 'Segments', 'labyrinthe-connect' ),
			'error'      => __( 'Error', 'labyrinthe-connect' ),
		);
	}

	/**
	 * Colonnes triables.
	 *
	 * @return array<string, array{0: string, 1: bool}>
	 */
	protected function get_sortable_columns() {
		return array(
			'created_at' => array( 'created_at', true ),
			'status'     => array( 'status', false ),
			'context'    => array( 'context', false ),
			'order_id'   => array( 'order_id', false ),
		);
	}

	/**
	 * Charge la page demandée.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtres de lecture seule.
		$args = array(
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'context'  => isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'created_at',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
			'per_page' => $this->get_items_per_page( 'labyrinthe_connect_log_per_page', 20 ),
			'page'     => $this->get_pagenum(),
		);
		// phpcs:enable

		$result      = $this->log->query( $args );
		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $args['per_page'],
				'total_pages' => (int) ceil( $result['total'] / max( 1, $args['per_page'] ) ),
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'created_at' );
	}

	/**
	 * Filtres au-dessus du tableau.
	 *
	 * @param string $which top ou bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtres de lecture seule.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';
		// phpcs:enable
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="labyrinthe-filter-status"><?php esc_html_e( 'Status', 'labyrinthe-connect' ); ?></label>
			<select name="status" id="labyrinthe-filter-status">
				<option value=""><?php esc_html_e( 'All statuses', 'labyrinthe-connect' ); ?></option>
				<?php foreach ( self::status_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="screen-reader-text" for="labyrinthe-filter-context"><?php esc_html_e( 'Type', 'labyrinthe-connect' ); ?></label>
			<select name="context" id="labyrinthe-filter-context">
				<option value=""><?php esc_html_e( 'All types', 'labyrinthe-connect' ); ?></option>
				<?php foreach ( self::context_labels() as $value => $label ) : ?>
					<option value="<?php echo esc_attr( $value ); ?>"<?php selected( $context, $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Filter', 'labyrinthe-connect' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * Message sans résultat.
	 */
	public function no_items(): void {
		esc_html_e( 'No SMS sent by the plugin yet.', 'labyrinthe-connect' );
	}

	/**
	 * Colonnes simples.
	 *
	 * @param array<string, string> $item        Ligne.
	 * @param string                $column_name Colonne.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'created_at':
				$time = strtotime( $item['created_at'] . ' UTC' );
				$date = false !== $time ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : $item['created_at'];
				$test = '1' === (string) $item['test_mode'] ? ' <span class="labyrinthe-badge labyrinthe-badge--test">' . esc_html__( 'Test', 'labyrinthe-connect' ) . '</span>' : '';

				return esc_html( (string) $date ) . $test;
			case 'recipient':
				return '<code>' . esc_html( $item['recipient'] ) . '</code>';
			case 'context':
				$labels = self::context_labels();

				return esc_html( $labels[ $item['context'] ] ?? $item['context'] );
			case 'status':
				$labels = self::status_labels();
				$class  = in_array( $item['status'], array( 'delivered', Message_Log::STATUS_OTP_SENT ), true ) ? 'ok' : ( in_array( $item['status'], array_merge( Message_Log::FINAL_STATUSES, array( Message_Log::STATUS_ERROR ) ), true ) ? 'fail' : 'pending' );

				return '<span class="labyrinthe-status labyrinthe-status--' . esc_attr( $class ) . '">' . esc_html( $labels[ $item['status'] ] ?? $item['status'] ) . '</span>'
					. ( '' !== $item['message_id'] ? '<br /><small class="labyrinthe-muted">' . esc_html( $item['message_id'] ) . '</small>' : '' );
			case 'order_id':
				return $this->order_link( (int) $item['order_id'] );
			case 'segments':
				$segments = (int) $item['segments'];

				return $segments > 0 ? esc_html( number_format_i18n( $segments ) . ( 'ucs2' === $item['encoding'] ? ' (Unicode)' : '' ) ) : '—';
			case 'error':
				if ( '' === $item['error_code'] ) {
					return '—';
				}
				$request_id = (string) ( $item['request_id'] ?? '' );

				return '<code>' . esc_html( $item['error_code'] ) . '</code> ' . esc_html( $item['error_message'] )
					/* translators: %s: request ID (X-Request-Id), to give to the support. */
					. ( '' !== $request_id ? '<br /><small class="labyrinthe-muted">' . esc_html( sprintf( __( 'Request ID: %s', 'labyrinthe-connect' ), $request_id ) ) . '</small>' : '' );
		}

		return '';
	}

	/**
	 * Lien vers la commande (compatible HPOS).
	 *
	 * @param int $order_id Commande.
	 */
	private function order_link( int $order_id ): string {
		if ( $order_id <= 0 ) {
			return '—';
		}
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( $order instanceof \WC_Order ) {
			return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a>';
		}

		return '#' . esc_html( (string) $order_id );
	}
}
