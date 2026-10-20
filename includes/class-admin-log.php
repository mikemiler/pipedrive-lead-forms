<?php
/**
 * Admin submission log: list, inspect and retry submissions.
 *
 * @package PipedriveLeadForms
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the submission log and handles retry actions.
 */
class Pdlead_Admin_Log {

	const PAGE     = 'pdlead-log';
	const MENU_TOP = 'edit.php?post_type=pdlead_form';
	const PER_PAGE = 25;

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_page' ) );
		add_action( 'admin_post_pdlead_retry', array( __CLASS__, 'handle_retry' ) );
		add_action( 'admin_post_pdlead_retry_all', array( __CLASS__, 'handle_retry_all' ) );
		add_action( 'admin_post_pdlead_discard', array( __CLASS__, 'handle_discard' ) );
		add_action( 'admin_post_pdlead_download', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'attention_notice' ) );
	}

	/**
	 * Tell administrators about leads that did not reach Pipedrive, on every
	 * admin screen, so a problem is noticed even when no email arrives.
	 */
	public static function attention_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$counts = Pdlead_Submission_Store::attention_counts();
		$parts  = array();
		if ( $counts['failed'] ) {
			/* translators: %d: number of submissions. */
			$parts[] = sprintf( _n( '%d failed', '%d failed', $counts['failed'], 'pipedrive-lead-forms' ), $counts['failed'] );
		}
		if ( $counts['held'] ) {
			/* translators: %d: number of submissions. */
			$parts[] = sprintf( _n( '%d held back (not sent)', '%d held back (not sent)', $counts['held'], 'pipedrive-lead-forms' ), $counts['held'] );
		}
		if ( $counts['stuck'] ) {
			/* translators: %d: number of submissions. */
			$parts[] = sprintf( _n( '%d waiting for more than an hour', '%d waiting for more than an hour', $counts['stuck'], 'pipedrive-lead-forms' ), $counts['stuck'] );
		}
		if ( get_option( Pdlead_Bot_Guard::TURNSTILE_SECRET_REJECTED ) ) {
			printf(
				'<div class="notice notice-error"><p><strong>%1$s</strong> %2$s</p></div>',
				esc_html__( 'Pipedrive Lead Forms:', 'pipedrive-lead-forms' ),
				esc_html__( 'Cloudflare rejects the Turnstile secret key, so the captcha does not protect your forms. Submissions are still accepted and flagged as suspicious. Check the secret key in the settings.', 'pipedrive-lead-forms' )
			);
		}
		if ( ! $parts ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Pipedrive Lead Forms:', 'pipedrive-lead-forms' ),
			/* translators: %s: comma separated list such as "2 failed, 1 held back (not sent)". */
			esc_html( sprintf( __( 'Leads that did not reach Pipedrive: %s.', 'pipedrive-lead-forms' ), implode( ', ', $parts ) ) ),
			esc_url( self::page_url() ),
			esc_html__( 'Open the submission log', 'pipedrive-lead-forms' )
		);
	}

	/**
	 * Add the log submenu.
	 */
	public static function add_page() {
		add_submenu_page(
			self::MENU_TOP,
			__( 'Submission Log', 'pipedrive-lead-forms' ),
			__( 'Submission Log', 'pipedrive-lead-forms' ),
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enqueue the admin stylesheet on the log page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'pdlead-admin', PDLEAD_URL . 'assets/admin.css', array(), PDLEAD_VERSION );
		wp_enqueue_script( 'pdlead-admin-log', PDLEAD_URL . 'assets/admin-log.js', array(), PDLEAD_VERSION, true );
	}

	/**
	 * The log page URL.
	 *
	 * @return string
	 */
	private static function page_url() {
		return admin_url( 'edit.php?post_type=' . PDLEAD_CPT . '&page=' . self::PAGE );
	}

	/**
	 * Render the log table.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Read filters from the query. This is a read only listing screen.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flagged = isset( $_GET['flagged'] ) && '1' === $_GET['flagged'] ? '1' : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$result = Pdlead_Submission_Store::query(
			array(
				'status'   => $status,
				'flagged'  => $flagged,
				'per_page' => self::PER_PAGE,
				'page'     => $paged,
			)
		);
		$rows  = $result['rows'];
		$total = $result['total'];
		$pages = (int) ceil( $total / self::PER_PAGE );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Submission Log', 'pipedrive-lead-forms' ); ?></h1>

			<ul class="subsubsub">
				<?php
				$filters = array(
					''        => __( 'All', 'pipedrive-lead-forms' ),
					'pending' => __( 'Pending', 'pipedrive-lead-forms' ),
					'sent'    => __( 'Sent', 'pipedrive-lead-forms' ),
					'failed'  => __( 'Failed', 'pipedrive-lead-forms' ),
					'held'    => __( 'Held', 'pipedrive-lead-forms' ),
					'spam'    => __( 'Spam', 'pipedrive-lead-forms' ),
				);
				$links = array();
				foreach ( $filters as $value => $label ) {
					$url     = add_query_arg( 'status', $value, self::page_url() );
					$current = ( $status === $value ) ? ' class="current"' : '';
					$links[] = '<li><a href="' . esc_url( $url ) . '"' . $current . '>' . esc_html( $label ) . '</a></li>';
				}
				echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</ul>

			<p>
				<a href="<?php echo esc_url( add_query_arg( 'flagged', '1', self::page_url() ) ); ?>" class="button"><?php esc_html_e( 'Show suspicious only', 'pipedrive-lead-forms' ); ?></a>
				<?php
				$retry_all_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=pdlead_retry_all' ),
					'pdlead_retry_all'
				);
				?>
				<a href="<?php echo esc_url( $retry_all_url ); ?>" class="button button-secondary"><?php esc_html_e( 'Retry all failed', 'pipedrive-lead-forms' ); ?></a>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'pipedrive-lead-forms' ); ?></th>
						<th><?php esc_html_e( 'Date', 'pipedrive-lead-forms' ); ?></th>
						<th><?php esc_html_e( 'Form', 'pipedrive-lead-forms' ); ?></th>
						<th><?php esc_html_e( 'Status', 'pipedrive-lead-forms' ); ?></th>
						<th><?php esc_html_e( 'Lead', 'pipedrive-lead-forms' ); ?></th>
						<th><?php esc_html_e( 'Data', 'pipedrive-lead-forms' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'pipedrive-lead-forms' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7"><?php esc_html_e( 'No submissions yet.', 'pipedrive-lead-forms' ); ?></td></tr>
					<?php endif; ?>

					<?php foreach ( $rows as $row ) : ?>
						<?php
						$form_title = get_the_title( (int) $row['form_id'] );
						$payload    = json_decode( $row['payload'], true );
						$payload    = is_array( $payload ) ? $payload : array();
						?>
						<tr>
							<td><?php echo esc_html( $row['id'] ); ?></td>
							<td><?php echo esc_html( get_date_from_gmt( $row['created_at'], 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( $form_title ? $form_title : '#' . $row['form_id'] ); ?></td>
							<td>
								<span class="pdlead-badge pdlead-badge-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( $row['status'] ); ?></span>
								<?php if ( ! empty( $row['flagged'] ) ) : ?>
									<span class="pdlead-badge pdlead-badge-flagged"><?php esc_html_e( 'suspicious', 'pipedrive-lead-forms' ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $row['last_error'] ) ) : ?>
									<br /><small class="pdlead-error"><?php echo esc_html( $row['last_error'] ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $row['pipedrive_lead_id'] ) ) : ?>
									<code><?php echo esc_html( $row['pipedrive_lead_id'] ); ?></code>
								<?php else : ?>
									<span aria-hidden="true">-</span>
								<?php endif; ?>
							</td>
							<td>
								<details>
									<summary><?php esc_html_e( 'View', 'pipedrive-lead-forms' ); ?></summary>
									<dl class="pdlead-payload">
										<?php foreach ( $payload as $key => $value ) : ?>
											<?php if ( Pdlead_Form_CPT::COOKIES_KEY === $key ) : ?>
												<?php if ( is_array( $value ) ) : ?>
													<dt><?php esc_html_e( 'Cookies', 'pipedrive-lead-forms' ); ?></dt>
													<dd>
														<?php foreach ( $value as $name => $item ) : ?>
															<?php echo esc_html( $name . ': ' . ( is_scalar( $item ) ? $item : '' ) ); ?><br />
														<?php endforeach; ?>
													</dd>
												<?php endif; ?>
												<?php continue; ?>
											<?php endif; ?>
											<dt><?php echo esc_html( $key ); ?></dt>
											<dd>
													<?php if ( is_array( $value ) ) : ?>
														<?php
														// File fields hold a list of file metadata; link each one
														// to the protected, admin-only download handler.
														$parts = array();
														foreach ( $value as $idx => $file ) {
															if ( is_array( $file ) && ! empty( $file['file'] ) ) {
																$dl      = wp_nonce_url(
																	admin_url( 'admin-post.php?action=pdlead_download&id=' . (int) $row['id'] . '&key=' . rawurlencode( $key ) . '&idx=' . (int) $idx ),
																	'pdlead_download_' . (int) $row['id'] . '_' . $key . '_' . (int) $idx
																);
																$fname   = ! empty( $file['name'] ) ? $file['name'] : wp_basename( $file['file'] );
																$parts[] = '<a href="' . esc_url( $dl ) . '">' . esc_html( $fname ) . '</a>';
															} elseif ( is_scalar( $file ) ) {
																$parts[] = esc_html( (string) $file );
															}
														}
														echo implode( ', ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each part is individually escaped above.
														?>
													<?php else : ?>
														<?php echo esc_html( $value ); ?>
													<?php endif; ?>
												</dd>
										<?php endforeach; ?>
									</dl>
								</details>
							</td>
							<td>
								<?php if ( in_array( $row['status'], array( 'failed', 'pending', 'held' ), true ) ) : ?>
									<?php
									$retry_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=pdlead_retry&id=' . (int) $row['id'] ),
										'pdlead_retry_' . (int) $row['id']
									);
									?>
									<a href="<?php echo esc_url( $retry_url ); ?>" class="button button-small"><?php echo 'held' === $row['status'] ? esc_html__( 'Send to Pipedrive', 'pipedrive-lead-forms' ) : esc_html__( 'Retry', 'pipedrive-lead-forms' ); ?></a>
								<?php endif; ?>
								<?php if ( in_array( $row['status'], array( 'failed', 'held' ), true ) ) : ?>
									<?php
									$discard_url = wp_nonce_url(
										admin_url( 'admin-post.php?action=pdlead_discard&id=' . (int) $row['id'] ),
										'pdlead_discard_' . (int) $row['id']
									);
									?>
									<a href="<?php echo esc_url( $discard_url ); ?>" class="button button-small button-link-delete pdlead-discard" data-pdlead-confirm="<?php esc_attr_e( 'Discard this submission? It will not be sent to Pipedrive and is deleted with the regular cleanup.', 'pipedrive-lead-forms' ); ?>"><?php esc_html_e( 'Discard', 'pipedrive-lead-forms' ); ?></a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $pages > 1 ) : ?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'base'    => add_query_arg( 'paged', '%#%', self::page_url() ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						)
					);
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Retry a single submission.
	 */
	public static function handle_retry() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'pdlead_retry_' . $id );

		if ( $id ) {
			// Reset to pending and clear the backoff so it runs now.
			Pdlead_Submission_Store::update(
				$id,
				array(
					'status'          => Pdlead_Submission_Store::STATUS_PENDING,
					'next_attempt_at' => null,
				)
			);
			Pdlead_Lead_Dispatcher::dispatch( $id );
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Discard a held or failed submission by marking it as spam. It is never
	 * sent and the regular cleanup removes it with its files.
	 */
	public static function handle_discard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'pdlead_discard_' . $id );

		$row = $id ? Pdlead_Submission_Store::get( $id ) : null;
		if ( $row && in_array( $row['status'], array( Pdlead_Submission_Store::STATUS_FAILED, Pdlead_Submission_Store::STATUS_HELD ), true ) ) {
			Pdlead_Submission_Store::update(
				$id,
				array(
					'status'     => Pdlead_Submission_Store::STATUS_SPAM,
					'last_error' => __( 'Discarded in the submission log.', 'pipedrive-lead-forms' ),
				)
			);
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Retry all failed submissions.
	 */
	public static function handle_retry_all() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) );
		}
		check_admin_referer( 'pdlead_retry_all' );

		global $wpdb;
		$table = Pdlead_Submission_Store::table();
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s, attempts = 0, next_attempt_at = NULL WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				Pdlead_Submission_Store::STATUS_PENDING,
				Pdlead_Submission_Store::STATUS_FAILED
			)
		);

		Pdlead_Lead_Dispatcher::process_retry_queue();

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Stream an uploaded file to an authorized admin. Files live in a protected
	 * directory, so this handler is the only way to retrieve them.
	 */
	public static function handle_download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'pipedrive-lead-forms' ) );
		}

		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$key = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : '';
		$idx = isset( $_GET['idx'] ) ? absint( $_GET['idx'] ) : 0;
		check_admin_referer( 'pdlead_download_' . $id . '_' . $key . '_' . $idx );

		$row = $id ? Pdlead_Submission_Store::get( $id ) : null;
		if ( ! $row ) {
			wp_die( esc_html__( 'File not found.', 'pipedrive-lead-forms' ) );
		}

		$payload = json_decode( $row['payload'], true );
		if ( ! is_array( $payload ) || empty( $payload[ $key ][ $idx ] ) || ! is_array( $payload[ $key ][ $idx ] ) ) {
			wp_die( esc_html__( 'File not found.', 'pipedrive-lead-forms' ) );
		}

		$file = $payload[ $key ][ $idx ];
		$path = ! empty( $file['file'] ) ? Pdlead_File_Store::abs_path( $file['file'] ) : '';
		$dir  = trailingslashit( Pdlead_File_Store::dir() );

		// Never serve anything outside the managed upload directory.
		if ( '' === $path || 0 !== strpos( wp_normalize_path( $path ), wp_normalize_path( $dir ) ) || ! file_exists( $path ) ) {
			wp_die( esc_html__( 'File not found.', 'pipedrive-lead-forms' ) );
		}

		$name = ! empty( $file['name'] ) ? sanitize_file_name( $file['name'] ) : wp_basename( $path );

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a local file download; WP_Filesystem cannot stream to output.
		readfile( $path );
		exit;
	}
}
