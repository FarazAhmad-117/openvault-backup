<?php
/**
 * List table of backups for the History page.
 *
 * Phase: MVP
 *
 * Only load this class after WP_List_Table exists (HistoryPage requires the core file first); it is admin-only.
 * Pure text helpers live in BackupRowPresenter. Actions are buttons driven by assets/admin/js/history.js (REST),
 * except the log link and the download links, which are plain links.
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin;

use OpenVault\Backup\Database\BackupFilesRepository;
use OpenVault\Backup\Database\BackupsRepository;
use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Engine\UploadSummary;
use OpenVault\Backup\Security\DownloadController;

defined( 'ABSPATH' ) || exit;

/**
 * Backups table.
 */
class BackupsListTable extends \WP_List_Table {

	const PER_PAGE = 20;

	/**
	 * Files of one backup.
	 *
	 * @var BackupFilesRepository
	 */
	private $files;

	/**
	 * Destination labels by id (loaded once, on first use).
	 *
	 * @var array<int,string>|null
	 */
	private $labels;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'backup',
				'plural'   => 'backups',
				'ajax'     => false,
			)
		);
		$this->files = new BackupFilesRepository();
	}

	/**
	 * Columns.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'created'  => __( 'Date', 'openvault-backup' ),
			'trigger'  => __( 'Type', 'openvault-backup' ),
			'status'   => __( 'Status', 'openvault-backup' ),
			'size'     => __( 'Size', 'openvault-backup' ),
			'contents' => __( 'Contents', 'openvault-backup' ),
			'parts'    => __( 'Parts', 'openvault-backup' ),
			'note'     => __( 'Note', 'openvault-backup' ),
			'locked'   => __( 'Lock', 'openvault-backup' ),
		);
	}

	/**
	 * Primary column (holds the row actions).
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'created';
	}

	/**
	 * Load one page, newest first.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$page   = max( 1, $this->get_pagenum() );
		$result = ( new BackupsRepository() )->paginate(
			array(
				'per_page' => self::PER_PAGE,
				'page'     => $page,
				'orderby'  => 'created_at',
				'order'    => 'DESC',
			)
		);

		$this->_column_headers = array( $this->get_columns(), array(), array(), 'created' );
		$this->items           = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/**
	 * Date column with row actions and the download list.
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_created( $item ) {
		$id        = (int) $item['id'];
		$timestamp = strtotime( $item['created_at'] . ' UTC' );
		$date      = false === $timestamp ? $item['created_at'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		$ago       = false === $timestamp ? '' : sprintf(
			/* translators: %s: human readable time difference, for example "3 hours". */
			__( '%s ago', 'openvault-backup' ),
			human_time_diff( $timestamp, time() )
		);

		$status  = (string) $item['status'];
		$actions = array();
		$list    = '';

		if ( BackupRowPresenter::has_files( $status ) ) {
			$actions['download'] = '<a href="#ovb-dl-' . $id . '" data-ovb-action="downloads" data-ovb-id="' . $id . '" aria-controls="ovb-dl-' . $id . '">' . esc_html__( 'Download', 'openvault-backup' ) . '</a>';
			$list                = $this->download_list( $id );
			$actions['lock']     = '<a href="#" role="button" data-ovb-action="lock" data-ovb-locked="' . ( $item['locked'] ? '0' : '1' ) . '" data-ovb-id="' . $id . '">' . esc_html( $item['locked'] ? __( 'Unlock', 'openvault-backup' ) : __( 'Lock', 'openvault-backup' ) ) . '</a>';
		}
		if ( BackupRowPresenter::is_running( $status ) ) {
			$actions['progress'] = '<a href="' . esc_url( \OpenVault\Backup\Admin\Menu::url( 'openvault-backup-now' ) ) . '">' . esc_html__( 'View progress', 'openvault-backup' ) . '</a>';
			$actions['cancel']   = '<a href="#" role="button" data-ovb-action="cancel" data-ovb-id="' . $id . '">' . esc_html__( 'Cancel', 'openvault-backup' ) . '</a>';
		} elseif ( ! $item['locked'] ) {
			$actions['delete'] = '<a href="#" role="button" class="submitdelete" data-ovb-action="delete" data-ovb-id="' . $id . '">' . esc_html__( 'Delete', 'openvault-backup' ) . '</a>';
		}
		$actions['log'] = '<a href="' . esc_url( \OpenVault\Backup\Admin\Menu::url( 'openvault-logs', array( 'backup_id' => (string) $id ) ) ) . '">' . esc_html__( 'View log', 'openvault-backup' ) . '</a>';

		return '<strong>' . esc_html( $date ) . '</strong>' . ( '' !== $ago ? '<br><span class="description">' . esc_html( $ago ) . '</span>' : '' )
			. $list
			. $this->row_actions( $actions ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Every part is escaped above.
	}

	/**
	 * Download links of a backup: its parts and the manifest, each through the authenticated endpoint.
	 *
	 * @param int $backup_id Backup id.
	 * @return string HTML.
	 */
	private function download_list( $backup_id ) {
		$html = '';
		foreach ( $this->files->for_backup_and_destination( $backup_id, 0 ) as $file ) {
			if ( 'done' !== $file['upload_status'] ) {
				continue;
			}
			$html .= '<li><a href="' . esc_url( DownloadController::url( $backup_id, (int) $file['id'] ) ) . '">' . esc_html( $file['file_name'] ) . '</a> <span class="description">(' . esc_html( BackupRowPresenter::format_bytes( (int) $file['size'] ) ) . ')</span></li>';
		}
		if ( '' === $html ) {
			return '';
		}
		return '<div class="ovb-downloads" id="ovb-dl-' . (int) $backup_id . '"><ul>' . $html . '</ul></div>';
	}

	/**
	 * Type column.
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_trigger( $item ) {
		return esc_html( BackupRowPresenter::trigger_label( (string) $item['trigger_type'] ) );
	}

	/**
	 * Status column: a badge that always shows the status as text.
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_status( $item ) {
		$status = (string) $item['status'];
		$html   = '<span class="' . esc_attr( BackupRowPresenter::status_class( $status ) ) . '">' . esc_html( BackupRowPresenter::status_label( $status ) ) . '</span>';

		$line = BackupRowPresenter::destinations_line( $this->destination_summary( (int) $item['id'] ) );
		if ( '' !== $line ) {
			$html .= '<br><span class="description ovb-destinations">' . esc_html( $line ) . '</span>';
		}
		return $html;
	}

	/**
	 * Upload summary of a backup (remote destinations only).
	 *
	 * @param int $backup_id Backup id.
	 * @return array<int,array<string,mixed>>
	 */
	private function destination_summary( $backup_id ) {
		$rows = $this->files->for_backup( $backup_id );
		if ( array() === $rows ) {
			return array();
		}
		if ( null === $this->labels ) {
			$this->labels = array();
			foreach ( ( new DestinationsRepository() )->all() as $destination ) {
				$this->labels[ $destination['id'] ] = $destination['label'];
			}
		}
		return UploadSummary::summarise( $rows, $this->labels );
	}

	/**
	 * Size column.
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_size( $item ) {
		return $item['total_size'] > 0 ? esc_html( BackupRowPresenter::format_bytes( (int) $item['total_size'] ) ) : '-';
	}

	/**
	 * Contents column.
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_contents( $item ) {
		return esc_html( BackupRowPresenter::contents_summary( is_array( $item['contents'] ) ? $item['contents'] : array() ) );
	}

	/**
	 * Parts column (archive parts, not counting the manifest).
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_parts( $item ) {
		if ( ! BackupRowPresenter::has_files( (string) $item['status'] ) ) {
			return '-';
		}
		$count = 0;
		foreach ( $this->files->for_backup_and_destination( (int) $item['id'], 0 ) as $file ) {
			if ( 'done' === $file['upload_status'] && 'manifest.json' !== $file['file_name'] ) {
				++$count;
			}
		}
		return esc_html( (string) $count );
	}

	/**
	 * Note column.
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_note( $item ) {
		return '' === $item['note'] ? '-' : esc_html( $item['note'] );
	}

	/**
	 * Lock column (text, not just an icon).
	 *
	 * @param array<string,mixed> $item Backup row.
	 * @return string
	 */
	protected function column_locked( $item ) {
		return $item['locked']
			? '<span class="dashicons dashicons-lock" aria-hidden="true"></span> ' . esc_html__( 'Locked', 'openvault-backup' )
			: esc_html__( 'Not locked', 'openvault-backup' );
	}

	/**
	 * Fallback for columns without a method.
	 *
	 * @param array<string,mixed> $item        Backup row.
	 * @param string              $column_name Column.
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return '';
	}
}
