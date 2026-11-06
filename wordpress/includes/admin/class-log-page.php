<?php
/**
 * Page « Labyrinthe Connect > Journal des SMS ».
 *
 * @package LabyrintheConnect
 */

namespace LabyrintheConnect\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Affiche le journal (WP_List_Table).
 */
final class Log_Page {

	/**
	 * Affiche la page.
	 */
	public function render(): void {
		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'labyrinthe-connect' ), 403 );
		}

		$table = new Log_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap labyrinthe-connect">
			<h1><?php esc_html_e( 'SMS log', 'labyrinthe-connect' ); ?></h1>
			<p class="description"><?php esc_html_e( 'SMS sent by this site. Delivery statuses are updated by the webhook (see Settings). Phone numbers are masked; message texts are never stored.', 'labyrinthe-connect' ); ?></p>
			<form method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::LOG_SLUG ); ?>" />
				<?php
				$table->search_box( __( 'Search (order, message ID, error code, request ID)', 'labyrinthe-connect' ), 'labyrinthe-sms' );
				$table->display();
				?>
			</form>
		</div>
		<?php
	}
}
