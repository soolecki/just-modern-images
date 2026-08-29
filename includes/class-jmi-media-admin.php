<?php
/**
 * Media Library status and actions.
 *
 * @package JustModernImages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Makes modern-image processing visible and controllable from Media screens.
 */
final class JMI_Media_Admin {

	const BULK_ACTION = 'jmi_process_now';

	/**
	 * Queryable attachment status.
	 *
	 * @var JMI_Media_Status
	 */
	private $media_status;

	/**
	 * Attachment manifest storage.
	 *
	 * @var JMI_Manifest
	 */
	private $manifest;

	/**
	 * Background conversion queue.
	 *
	 * @var JMI_Queue
	 */
	private $queue;

	/**
	 * Quality profile provider.
	 *
	 * @var JMI_Quality_Profiles
	 */
	private $profiles;

	/**
	 * Verified server capabilities.
	 *
	 * @var JMI_Capabilities
	 */
	private $capabilities;

	/**
	 * Set up Media Library integration.
	 *
	 * @param JMI_Media_Status     $media_status Attachment status.
	 * @param JMI_Manifest         $manifest     Manifest storage.
	 * @param JMI_Queue            $queue        Background queue.
	 * @param JMI_Quality_Profiles $profiles     Quality profiles.
	 * @param JMI_Capabilities     $capabilities Server capabilities.
	 */
	public function __construct( $media_status, $manifest, $queue, $profiles, $capabilities ) {
		$this->media_status = $media_status;
		$this->manifest     = $manifest;
		$this->queue        = $queue;
		$this->profiles     = $profiles;
		$this->capabilities = $capabilities;
	}

	/**
	 * Register Media Library hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'manage_media_columns', array( $this, 'add_status_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_status_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'add_row_action' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_attachment_fields' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk_action' ), 10, 3 );
		add_action( 'restrict_manage_posts', array( $this, 'render_status_filter' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'apply_status_filter' ) );
		add_action( 'admin_post_jmi_process_attachment', array( $this, 'handle_single_action' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
	}

	/**
	 * Add a compact status column to the Media list.
	 *
	 * @param array<string, string> $columns Existing columns.
	 * @return array<string, string>
	 */
	public function add_status_column( $columns ) {
		$columns['jmi_status'] = __( 'Modern images', 'just-modern-images' );

		return $columns;
	}

	/**
	 * Render the status column.
	 *
	 * @param string $column_name  Column name.
	 * @param int    $attachment_id Attachment ID.
	 * @return void
	 */
	public function render_status_column( $column_name, $attachment_id ) {
		if ( 'jmi_status' !== $column_name ) {
			return;
		}

		if ( ! $this->queue->is_supported_attachment( $attachment_id ) ) {
			echo '<span class="jmi-badge jmi-badge--muted">' . esc_html__( 'Not supported', 'just-modern-images' ) . '</span>';
			return;
		}

		echo wp_kses_post( $this->status_markup( $attachment_id, false ) );
	}

	/**
	 * Add a manual priority action next to an attachment.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @param WP_Post               $post    Attachment post.
	 * @return array<string, string>
	 */
	public function add_row_action( $actions, $post ) {
		if (
			! $post instanceof WP_Post ||
			! $this->queue->is_supported_attachment( $post->ID ) ||
			! current_user_can( 'edit_post', $post->ID )
		) {
			return $actions;
		}

		$actions['jmi_process_now'] = '<a href="' . esc_url( $this->process_url( $post->ID ) ) . '">' . esc_html__( 'Process now', 'just-modern-images' ) . '</a>';

		return $actions;
	}

	/**
	 * Show status and controls in attachment details.
	 *
	 * @param array<string, array<string, mixed>> $fields Existing fields.
	 * @param WP_Post                             $post   Attachment post.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_attachment_fields( $fields, $post ) {
		if ( ! $post instanceof WP_Post || ! $this->queue->is_supported_attachment( $post->ID ) ) {
			return $fields;
		}

		$html = $this->status_markup( $post->ID, true );
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$html .= '<p><a class="button button-secondary" href="' . esc_url( $this->process_url( $post->ID ) ) . '">' . esc_html__( 'Process now', 'just-modern-images' ) . '</a></p>';
		}

		$fields['jmi_status'] = array(
			'label' => __( 'Modern images', 'just-modern-images' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $fields;
	}

	/**
	 * Register the bulk priority action.
	 *
	 * @param array<string, string> $actions Existing actions.
	 * @return array<string, string>
	 */
	public function add_bulk_action( $actions ) {
		$actions[ self::BULK_ACTION ] = __( 'Process modern images now', 'just-modern-images' );

		return $actions;
	}

	/**
	 * Queue selected attachments at manual priority.
	 *
	 * @param string             $redirect_url Redirect URL.
	 * @param string             $action       Selected action.
	 * @param array<int, string> $post_ids     Selected IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( self::BULK_ACTION !== $action ) {
			return $redirect_url;
		}

		$queued = 0;
		foreach ( array_values( $post_ids ) as $index => $post_id ) {
			$post_id = absint( $post_id );
			if (
				$post_id &&
				current_user_can( 'edit_post', $post_id ) &&
				$this->queue->is_supported_attachment( $post_id )
			) {
				$this->queue->schedule_attachment( $post_id, 1 + $index, 'manual', true );
				++$queued;
			}
		}

		return add_query_arg( 'jmi-queued', $queued, $redirect_url );
	}

	/**
	 * Render a status filter above the Media list.
	 *
	 * @param string $post_type Current post type.
	 * @param string $which     Filter position.
	 * @return void
	 */
	public function render_status_filter( $post_type, $which = 'top' ) {
		unset( $which );
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$selected = isset( $_GET['jmi-status'] ) ? sanitize_key( wp_unslash( $_GET['jmi-status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<label class="screen-reader-text" for="jmi-status-filter"><?php esc_html_e( 'Filter by modern image status', 'just-modern-images' ); ?></label>
		<select id="jmi-status-filter" name="jmi-status">
			<option value=""><?php esc_html_e( 'All modern image statuses', 'just-modern-images' ); ?></option>
			<option value="ready" <?php selected( $selected, 'ready' ); ?>><?php esc_html_e( 'Modern images ready', 'just-modern-images' ); ?></option>
			<option value="waiting" <?php selected( $selected, 'waiting' ); ?>><?php esc_html_e( 'Waiting for processing', 'just-modern-images' ); ?></option>
			<option value="attention" <?php selected( $selected, 'attention' ); ?>><?php esc_html_e( 'Needs attention', 'just-modern-images' ); ?></option>
		</select>
		<?php
	}

	/**
	 * Apply the selected Media status filter.
	 *
	 * @param WP_Query $query Media query.
	 * @return void
	 */
	public function apply_status_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || 'attachment' !== $query->get( 'post_type' ) ) {
			return;
		}

		$filter = isset( $_GET['jmi-status'] ) ? sanitize_key( wp_unslash( $_GET['jmi-status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $filter, array( 'ready', 'waiting', 'attention' ), true ) ) {
			return;
		}

		$profile = $this->profiles->generation_profile();
		if ( 'ready' === $filter ) {
			$status_query = array(
				'key'     => JMI_Media_Status::STATE_META_KEY,
				'value'   => $this->media_status->current_values( array( 'ready' ), $profile ),
				'compare' => 'IN',
			);
		} elseif ( 'attention' === $filter ) {
			$status_query = array(
				'key'     => JMI_Media_Status::STATE_META_KEY,
				'value'   => $this->media_status->current_values( array( 'failed', 'stale' ), $profile ),
				'compare' => 'IN',
			);
		} else {
			$current_terminal = $this->media_status->current_values( array( 'ready', 'partial', 'skipped', 'failed', 'stale' ), $profile );
			$status_query     = array(
				'relation' => 'OR',
				array(
					'key'     => JMI_Media_Status::STATE_META_KEY,
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => JMI_Media_Status::STATE_META_KEY,
					'value'   => $current_terminal,
					'compare' => 'NOT IN',
				),
			);
		}

		$existing = $query->get( 'meta_query' );
		if ( is_array( $existing ) && ! empty( $existing ) ) {
			$status_query = array(
				'relation' => 'AND',
				$existing,
				$status_query,
			);
		}

		$query->set( 'meta_query', $status_query );
	}

	/**
	 * Handle a single attachment priority action.
	 *
	 * @return void
	 */
	public function handle_single_action() {
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( $_GET['attachment_id'] ) : 0;
		if ( ! $attachment_id || ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'You are not allowed to process this attachment.', 'just-modern-images' ) );
		}

		check_admin_referer( 'jmi_process_attachment_' . $attachment_id );
		$this->queue->schedule_attachment( $attachment_id, 1, 'manual', true );

		$redirect = wp_get_referer();
		$redirect = wp_validate_redirect( $redirect, admin_url( 'upload.php' ) );
		wp_safe_redirect( add_query_arg( 'jmi-queued', 1, $redirect ) );
		exit;
	}

	/**
	 * Display queue confirmation after single or bulk actions.
	 *
	 * @return void
	 */
	public function render_notice() {
		if ( ! isset( $_GET['jmi-queued'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$count = absint( $_GET['jmi-queued'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $count ) {
			return;
		}
		?>
		<div class="notice notice-success is-dismissible"><p>
			<?php /* translators: %s: number of images moved to the priority queue. */ ?>
			<?php echo esc_html( sprintf( _n( '%s image was moved to the front of the queue.', '%s images were moved to the front of the queue.', $count, 'just-modern-images' ), number_format_i18n( $count ) ) ); ?>
		</p></div>
		<?php
	}

	/**
	 * Load shared admin styles only where plugin UI is visible.
	 *
	 * @param string $hook_suffix Current admin page.
	 * @return void
	 */
	public function enqueue_styles( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'upload.php', 'post.php' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'jmi-admin', plugins_url( 'assets/admin.css', JMI_PLUGIN_FILE ), array(), JMI_VERSION );
	}

	/**
	 * Build the signed manual-processing URL.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private function process_url( $attachment_id ) {
		$url = add_query_arg(
			array(
				'action'        => 'jmi_process_attachment',
				'attachment_id' => absint( $attachment_id ),
			),
			admin_url( 'admin-post.php' )
		);

		return wp_nonce_url( $url, 'jmi_process_attachment_' . absint( $attachment_id ) );
	}

	/**
	 * Render overall and per-format attachment status.
	 *
	 * @param int  $attachment_id Attachment ID.
	 * @param bool $detailed      Whether to include format details.
	 * @return string
	 */
	private function status_markup( $attachment_id, $detailed ) {
		$status = $this->media_status->get( $attachment_id, $this->profiles->generation_profile() );
		$labels = array(
			'ready'      => array( __( 'Ready', 'just-modern-images' ), 'success' ),
			'partial'    => array( __( 'Partly ready', 'just-modern-images' ), 'warning' ),
			'skipped'    => array( __( 'Checked — no change', 'just-modern-images' ), 'neutral' ),
			'queued'     => array( __( 'In queue', 'just-modern-images' ), 'info' ),
			'processing' => array( __( 'Processing', 'just-modern-images' ), 'info' ),
			'failed'     => array( __( 'Needs attention', 'just-modern-images' ), 'danger' ),
			'stale'      => array( __( 'Needs refresh', 'just-modern-images' ), 'warning' ),
			'pending'    => array( __( 'Not processed', 'just-modern-images' ), 'muted' ),
		);
		$label  = $labels[ $status['state'] ] ?? $labels['pending'];
		$html   = '<span class="jmi-badge jmi-badge--' . esc_attr( $label[1] ) . '">' . esc_html( $label[0] ) . '</span>';

		if ( ! $detailed ) {
			return $html;
		}

		$manifest     = $this->manifest->get( $attachment_id );
		$capabilities = $this->capabilities->get_all();
		$html        .= '<div class="jmi-format-list">';
		$html        .= $this->format_markup( 'AVIF', 'image/avif', $manifest, $capabilities );
		$html        .= $this->format_markup( 'WebP', 'image/webp', $manifest, $capabilities );
		$html        .= '</div>';

		if ( ! empty( $status['updated_at'] ) ) {
			/* translators: %s: localized date and time of the last attachment update. */
			$html .= '<p class="description">' . esc_html( sprintf( __( 'Last update: %s', 'just-modern-images' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $status['updated_at'] ) ) ) . '</p>';
		}

		return $html;
	}

	/**
	 * Render one format state from the attachment manifest.
	 *
	 * @param string                              $label        Format label.
	 * @param string                              $mime_type    Format MIME type.
	 * @param array<string, mixed>                $manifest     Attachment manifest.
	 * @param array<string, array<string, mixed>> $capabilities Server capabilities.
	 * @return string
	 */
	private function format_markup( $label, $mime_type, $manifest, $capabilities ) {
		$total = 0;
		$ready = 0;

		foreach ( $manifest['sources'] as $source ) {
			++$total;
			if ( 'ready' === ( $source['variants'][ $mime_type ]['status'] ?? '' ) ) {
				++$ready;
			}
		}

		if ( $total && $ready === $total ) {
			$text  = __( 'Ready', 'just-modern-images' );
			$class = 'success';
		} elseif ( $ready ) {
			/* translators: 1: ready image sizes, 2: total image sizes. */
			$text  = sprintf( __( '%1$d of %2$d sizes', 'just-modern-images' ), $ready, $total );
			$class = 'warning';
		} elseif ( 'available' !== ( $capabilities[ $mime_type ]['state'] ?? 'unknown' ) ) {
			$text  = __( 'Unavailable on this server', 'just-modern-images' );
			$class = 'muted';
		} else {
			$text  = __( 'Not ready', 'just-modern-images' );
			$class = 'muted';
		}

		return '<div><strong>' . esc_html( $label ) . '</strong><span class="jmi-dot jmi-dot--' . esc_attr( $class ) . '"></span>' . esc_html( $text ) . '</div>';
	}
}
