<?php
/**
 * Destinations page.
 *
 * Phase: MVP
 *
 * Purpose: Add, test, edit and remove storage destinations (S3-compatible storage and local folders).
 *
 * A classic PHP page (decision D3) enhanced by assets/admin/js/destinations.js. The list of destinations and the form are
 * rendered here, the script only talks to the REST API (/destinations): test, save, remove. Without JavaScript the list is
 * shown and a notice explains that JavaScript is required to change anything.
 *
 * Secrets are write-only: the access key and the secret key are never rendered, not even as a value attribute. Editing a
 * destination shows "saved" and a "Replace the keys" button; leaving the keys empty keeps the stored ones. A destination
 * whose credentials can no longer be decrypted says so and asks for the keys again.
 *
 * The provider help (endpoint pattern, region note, what is needed, a link to the provider's own documentation) is static
 * text taken from website/src/data/providers.ts: no network calls. Google Drive is not offered (planned for later).
 *
 * @package OpenVault\Backup
 */

namespace OpenVault\Backup\Admin\Pages;

use OpenVault\Backup\Database\DestinationsRepository;
use OpenVault\Backup\Destinations\DestinationPresenter;
use OpenVault\Backup\Destinations\DestinationRegistry;
use OpenVault\Backup\Destinations\DestinationSettings;
use OpenVault\Backup\Destinations\S3\S3Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Destinations.
 */
class DestinationsPage extends AbstractPage {

	/**
	 * Page title.
	 *
	 * @return string
	 */
	public function title() {
		return __( 'Destinations', 'openvault-backup' );
	}

	/**
	 * Static help per preset: endpoint pattern, region note and what the user needs (from website/src/data/providers.ts).
	 *
	 * @return array<string,array{endpoint:string,note:string,needs:string[]}>
	 */
	public static function preset_help() {
		return array(
			'aws'    => array(
				'endpoint' => 'https://s3.<region>.amazonaws.com',
				'note'     => __( 'The region code (for example us-east-1 or eu-central-1) appears in the endpoint and must be the region your bucket lives in.', 'openvault-backup' ),
				'needs'    => array(
					__( 'An AWS account and a bucket in the region you choose', 'openvault-backup' ),
					__( 'An access key ID and secret access key for an identity that may use that bucket', 'openvault-backup' ),
				),
			),
			'r2'     => array(
				'endpoint' => 'https://<ACCOUNT_ID>.r2.cloudflarestorage.com',
				'note'     => __( 'The endpoint is built from your Cloudflare account ID, and the region is always "auto", so there is no region to enter.', 'openvault-backup' ),
				'needs'    => array(
					__( 'A Cloudflare account and an R2 bucket', 'openvault-backup' ),
					__( 'Your Cloudflare account ID', 'openvault-backup' ),
					__( 'An R2 API token with Object Read & Write, ideally limited to the bucket. Cloudflare shows the secret only once, so copy it right away.', 'openvault-backup' ),
				),
			),
			'b2'     => array(
				'endpoint' => 'https://s3.<region>.backblazeb2.com',
				'note'     => __( 'The region is part of the hostname (for example us-east-005). Use the S3 endpoint your own bucket shows.', 'openvault-backup' ),
				'needs'    => array(
					__( 'A Backblaze account and a private B2 bucket', 'openvault-backup' ),
					__( 'An application key, ideally restricted to that bucket. Backblaze documents how its key ID and application key map to the access key and secret key.', 'openvault-backup' ),
				),
			),
			'wasabi' => array(
				'endpoint' => 'https://s3.<region>.wasabisys.com',
				'note'     => __( 'The region code sits in the hostname. Pick the region your bucket was created in.', 'openvault-backup' ),
				'needs'    => array(
					__( 'A Wasabi account and a bucket in one of its regions', 'openvault-backup' ),
					__( 'An access key and secret key', 'openvault-backup' ),
				),
			),
			'spaces' => array(
				'endpoint' => 'https://<region>.digitaloceanspaces.com',
				'note'     => __( 'The Spaces region slug (for example nyc3 or fra1) is the first part of the hostname.', 'openvault-backup' ),
				'needs'    => array(
					__( 'A DigitalOcean account and a Space in a Spaces region', 'openvault-backup' ),
					__( 'A Spaces access key and secret', 'openvault-backup' ),
				),
			),
			'custom' => array(
				'endpoint' => 'https://s3.example.com',
				'note'     => __( 'Any S3-compatible server, for example MinIO or Ceph. Enter its endpoint. The region is optional and defaults to us-east-1.', 'openvault-backup' ),
				'needs'    => array(
					__( 'The endpoint of the server and a bucket on it', 'openvault-backup' ),
					__( 'An access key and secret key for that bucket', 'openvault-backup' ),
				),
			),
		);
	}

	/**
	 * Page body.
	 *
	 * @return void
	 */
	protected function content() {
		$repository = new DestinationsRepository();
		$registry   = new DestinationRegistry();
		$items      = array();
		foreach ( $repository->all() as $row ) {
			$items[] = DestinationPresenter::item( $row );
		}

		echo '<noscript><div class="notice notice-warning inline"><p>' . esc_html__( 'JavaScript is required on this page to add, test, edit or remove destinations. Enable JavaScript and reload the page.', 'openvault-backup' ) . '</p></div></noscript>';
		echo '<div id="ovb-destinations">';
		echo '<div id="ovb-dest-notice" class="notice inline" role="status" hidden><p></p></div>';
		echo '<p>' . esc_html__( 'A destination is a place where backups are sent in addition to the local backup folder on this server. You use your own account and your own keys: nothing goes through any other server.', 'openvault-backup' ) . '</p>';

		echo '<section class="ovb-panel" aria-labelledby="ovb-dest-list-title"><h2 id="ovb-dest-list-title">' . esc_html__( 'Your destinations', 'openvault-backup' ) . '</h2>';
		echo '<div id="ovb-dest-list">';
		if ( array() === $items ) {
			echo '<p class="ovb-dest-empty">' . esc_html__( 'No destinations yet. Backups are stored in the local backup folder only. Add a destination below to send a copy to cloud storage.', 'openvault-backup' ) . '</p>';
		}
		foreach ( $items as $item ) {
			$this->render_card( $item, $registry->has( $item['type'] ) );
		}
		echo '</div></section>';

		$this->render_form();

		echo '<p class="description ovb-more">' . esc_html__( 'More destinations are planned.', 'openvault-backup' ) . '</p>';
		echo '</div>';
	}

	/**
	 * One destination card.
	 *
	 * @param array<string,mixed> $item  DestinationPresenter::item().
	 * @param bool                $known Whether the provider is available.
	 * @return void
	 */
	private function render_card( array $item, $known ) {
		$id        = (int) $item['id'];
		$editable  = DestinationSettings::is_supported_type( $item['type'] ) && $known;
		$reconnect = DestinationsRepository::STATUS_NEEDS_RECONNECT === $item['status'];
		$kind      = '' !== $item['preset_label'] ? $item['preset_label'] : $item['type_label'];

		echo '<article class="ovb-card" id="ovb-dest-' . esc_attr( (string) $id ) . '" data-ovb-id="' . esc_attr( (string) $id ) . '" data-ovb-dest="' . esc_attr( (string) wp_json_encode( $item ) ) . '" aria-labelledby="ovb-dest-title-' . esc_attr( (string) $id ) . '">';
		echo '<h3 id="ovb-dest-title-' . esc_attr( (string) $id ) . '" class="ovb-card-title">' . esc_html( $item['label'] ) . ' <span class="ovb-status ovb-status--' . esc_attr( (string) $item['status'] ) . '" data-ovb-role="status-badge">' . esc_html( $item['status_label'] ) . '</span></h3>';
		echo '<p class="ovb-meta"><strong>' . esc_html( $kind ) . '</strong>';
		if ( '' !== $item['details'] ) {
			echo ' &middot; <span class="ovb-dest-details">' . esc_html( $item['details'] ) . '</span>';
		}
		echo '</p>';

		if ( $reconnect ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Saved credentials can no longer be read (the site\'s encryption key changed). Enter the keys again.', 'openvault-backup' ) . '</p></div>';
		}

		echo '<p class="ovb-meta" data-ovb-role="tested">' . esc_html( $this->tested_text( $item ) ) . '</p>';
		echo '<p class="ovb-dest-error" data-ovb-role="error"' . ( null === $item['last_error'] || $reconnect ? ' hidden' : '' ) . '>' . esc_html( null === $item['last_error'] ? '' : $item['last_error'] ) . '</p>';
		echo '<p class="ovb-dest-result" data-ovb-role="result" role="status" aria-live="polite"></p>';

		echo '<p class="ovb-card-actions">';
		if ( ! $reconnect && $editable ) {
			echo '<button type="button" class="button" data-ovb-action="test">' . esc_html__( 'Test connection', 'openvault-backup' ) . '</button> ';
		}
		if ( $editable ) {
			echo '<button type="button" class="button' . ( $reconnect ? ' button-primary' : '' ) . '" data-ovb-action="edit">' . esc_html( $reconnect ? __( 'Enter keys again', 'openvault-backup' ) : __( 'Edit', 'openvault-backup' ) ) . '</button> ';
		}
		echo '<button type="button" class="button button-link-delete" data-ovb-action="remove">' . esc_html__( 'Remove', 'openvault-backup' ) . '</button>';
		echo '</p></article>';
	}

	/**
	 * "Last tested ..." text of a card.
	 *
	 * @param array<string,mixed> $item Item.
	 * @return string
	 */
	private function tested_text( array $item ) {
		if ( null === $item['last_tested_at'] ) {
			return __( 'Never tested.', 'openvault-backup' );
		}
		$time = strtotime( (string) $item['last_tested_at'] );
		return sprintf(
			/* translators: %s: date and time of the last connection test. */
			__( 'Last tested: %s', 'openvault-backup' ),
			false === $time ? (string) $item['last_tested_at'] : wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time )
		);
	}

	/**
	 * The add / edit form.
	 *
	 * @return void
	 */
	private function render_form() {
		$presets = S3Presets::all();
		$help    = self::preset_help();

		echo '<section class="ovb-panel" id="ovb-dest-form-panel" aria-labelledby="ovb-dest-form-title"><h2 id="ovb-dest-form-title" data-add="' . esc_attr__( 'Add a destination', 'openvault-backup' ) . '" data-edit="' . esc_attr__( 'Edit destination', 'openvault-backup' ) . '">' . esc_html__( 'Add a destination', 'openvault-backup' ) . '</h2>';
		echo '<form id="ovb-dest-form" class="ovb-form" method="post" action="#" novalidate autocomplete="off">';
		echo '<div id="ovb-dest-form-error" class="notice notice-error inline" role="alert" hidden><p></p></div>';
		echo '<input type="hidden" id="ovb-dest-edit-id" value="">';

		echo '<fieldset class="ovb-fieldset" id="ovb-dest-types"><legend>' . esc_html__( 'Type', 'openvault-backup' ) . '</legend>';
		echo '<p class="ovb-option"><label for="ovb-dest-type-s3"><input type="radio" name="ovb-dest-type" id="ovb-dest-type-s3" value="s3" checked> <strong>' . esc_html__( 'S3-compatible storage', 'openvault-backup' ) . '</strong></label><span class="description ovb-help">' . esc_html__( 'Amazon S3, Cloudflare R2, Backblaze B2, Wasabi, DigitalOcean Spaces or any server that speaks the S3 protocol.', 'openvault-backup' ) . '</span></p>';
		echo '<p class="ovb-option"><label for="ovb-dest-type-local"><input type="radio" name="ovb-dest-type" id="ovb-dest-type-local" value="local"> <strong>' . esc_html__( 'Local folder', 'openvault-backup' ) . '</strong></label><span class="description ovb-help">' . esc_html__( 'Another folder on this server, for example on a mounted drive. It does not protect you if the server itself is lost.', 'openvault-backup' ) . '</span></p>';
		echo '</fieldset>';

		echo '<p class="ovb-option"><label for="ovb-dest-label"><strong>' . esc_html__( 'Name', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-dest-label" class="regular-text" maxlength="' . esc_attr( (string) DestinationSettings::LABEL_MAX ) . '" data-ovb-label aria-describedby="ovb-dest-label-help" required> ';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-dest-label-help">' . esc_html__( 'A name you will recognise, for example "Offsite copy". Shown in the backup list.', 'openvault-backup' ) . '</span></p>';

		// S3 group.
		echo '<div data-ovb-type="s3">';
		echo '<fieldset class="ovb-fieldset"><legend>' . esc_html__( 'Storage', 'openvault-backup' ) . '</legend>';

		echo '<p class="ovb-option"><label for="ovb-s3-preset"><strong>' . esc_html__( 'Provider', 'openvault-backup' ) . '</strong></label><br><select id="ovb-s3-preset" data-ovb-field="preset">';
		foreach ( $presets as $key => $preset ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $preset['label'] ) . '</option>';
		}
		echo '</select></p>';

		foreach ( $presets as $key => $preset ) {
			echo '<div class="ovb-preset-info" data-ovb-presets="' . esc_attr( $key ) . '">';
			echo '<p><strong>' . esc_html__( 'Endpoint:', 'openvault-backup' ) . '</strong> <code>' . esc_html( $help[ $key ]['endpoint'] ) . '</code><br>' . esc_html( $help[ $key ]['note'] ) . '</p>';
			echo '<p><strong>' . esc_html__( 'You need:', 'openvault-backup' ) . '</strong></p><ul class="ovb-needs">';
			foreach ( $help[ $key ]['needs'] as $need ) {
				echo '<li>' . esc_html( $need ) . '</li>';
			}
			echo '</ul>';
			if ( '' !== $preset['docs'] ) {
				echo '<p><a href="' . esc_url( $preset['docs'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Provider documentation', 'openvault-backup' ) . '<span class="screen-reader-text"> ' . esc_html__( '(opens in a new tab)', 'openvault-backup' ) . '</span></a></p>';
			}
			echo '</div>';
		}

		// Region (text with suggestions for AWS).
		echo '<p class="ovb-option" data-ovb-presets="aws b2 wasabi spaces custom"><label for="ovb-s3-region"><strong>' . esc_html__( 'Region', 'openvault-backup' ) . '</strong><span data-ovb-presets="custom"> ' . esc_html__( '(optional)', 'openvault-backup' ) . '</span></label><br>';
		echo '<input type="text" id="ovb-s3-region" class="regular-text" data-ovb-field="region" list="ovb-aws-regions" autocapitalize="none" spellcheck="false" aria-describedby="ovb-s3-region-help">';
		echo '<datalist id="ovb-aws-regions"><option value="us-east-1"><option value="us-east-2"><option value="us-west-1"><option value="us-west-2"><option value="eu-west-1"><option value="eu-west-2"><option value="eu-central-1"><option value="ap-southeast-1"><option value="ap-southeast-2"><option value="ap-northeast-1"><option value="ca-central-1"><option value="sa-east-1"></datalist>';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-s3-region-help">' . esc_html__( 'Use the region your bucket was created in. It must match the endpoint of your provider.', 'openvault-backup' ) . '</span></p>';

		echo '<p class="ovb-option" data-ovb-presets="r2"><label for="ovb-s3-account"><strong>' . esc_html__( 'Cloudflare account ID', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-s3-account" class="regular-text" data-ovb-field="account_id" maxlength="32" autocapitalize="none" spellcheck="false" aria-describedby="ovb-s3-account-help">';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-s3-account-help">' . esc_html__( '32 hexadecimal characters. You find it in the Cloudflare dashboard, on the R2 overview page.', 'openvault-backup' ) . '</span></p>';

		echo '<p class="ovb-option" data-ovb-presets="custom"><label for="ovb-s3-endpoint"><strong>' . esc_html__( 'Endpoint', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="url" id="ovb-s3-endpoint" class="regular-text" data-ovb-field="endpoint" placeholder="https://s3.example.com" autocapitalize="none" spellcheck="false" aria-describedby="ovb-s3-endpoint-help">';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-s3-endpoint-help">' . esc_html__( 'Host name and optional port only, with https://. Addresses on private networks are refused unless you allow them under "Advanced".', 'openvault-backup' ) . '</span></p>';

		echo '<p class="ovb-option"><label for="ovb-s3-bucket"><strong>' . esc_html__( 'Bucket', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-s3-bucket" class="regular-text" data-ovb-field="bucket" maxlength="63" autocapitalize="none" spellcheck="false" aria-describedby="ovb-s3-bucket-help">';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-s3-bucket-help">' . esc_html__( 'The bucket must already exist; the plugin never creates buckets. 3 to 63 lower-case letters, digits, dots or hyphens.', 'openvault-backup' ) . '</span></p>';

		echo '<p class="ovb-option"><label for="ovb-s3-prefix"><strong>' . esc_html__( 'Folder inside the bucket (optional)', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-s3-prefix" class="regular-text" data-ovb-field="prefix" maxlength="200" placeholder="backups/my-site" autocapitalize="none" spellcheck="false" aria-describedby="ovb-s3-prefix-help">';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-s3-prefix-help">' . esc_html__( 'Letters, digits, ".", "_", "-" and "/". No leading slash and no ".." parts. Leave empty to use the top of the bucket.', 'openvault-backup' ) . '</span></p>';
		echo '</fieldset>';

		echo '<fieldset class="ovb-fieldset" id="ovb-dest-keys"><legend>' . esc_html__( 'Access keys', 'openvault-backup' ) . '</legend>';
		echo '<p class="description ovb-keys-saved" id="ovb-keys-saved" hidden>' . esc_html__( 'Access key and secret key are saved. Leave the fields empty to keep them.', 'openvault-backup' ) . ' <button type="button" class="button" id="ovb-keys-replace">' . esc_html__( 'Replace the keys', 'openvault-backup' ) . '</button></p>';
		echo '<div id="ovb-keys-fields">';
		echo '<p class="ovb-option"><label for="ovb-s3-access"><strong>' . esc_html__( 'Access key', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-s3-access" class="regular-text" data-ovb-secret="access_key" autocomplete="off" autocapitalize="none" spellcheck="false" value=""></p>';
		echo '<p class="ovb-option"><label for="ovb-s3-secret"><strong>' . esc_html__( 'Secret key', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="password" id="ovb-s3-secret" class="regular-text" data-ovb-secret="secret_key" autocomplete="off" autocapitalize="none" spellcheck="false" value="" aria-describedby="ovb-s3-secret-help">';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-s3-secret-help">' . esc_html__( 'The secret key is encrypted before it is stored and is never shown again. To change it later, enter new keys.', 'openvault-backup' ) . '</span></p>';
		echo '</div>';
		echo '<ul class="ovb-safety">';
		echo '<li>' . esc_html__( 'Create a key that can only read and write this one bucket, not your whole account.', 'openvault-backup' ) . '</li>';
		echo '<li>' . esc_html__( 'Keep the keys private. Anyone who has them can read your backups.', 'openvault-backup' ) . '</li>';
		echo '<li>' . esc_html__( 'Backups are sent over HTTPS, so they are encrypted while they travel. The archives themselves are not encrypted yet: anyone with access to the bucket can read them. Archive encryption is planned for a later version.', 'openvault-backup' ) . '</li>';
		echo '</ul></fieldset>';

		echo '<details class="ovb-advanced"><summary>' . esc_html__( 'Advanced', 'openvault-backup' ) . '</summary>';
		echo '<p class="ovb-option"><label for="ovb-s3-addressing"><strong>' . esc_html__( 'Addressing style', 'openvault-backup' ) . '</strong></label><br><select id="ovb-s3-addressing" data-ovb-field="addressing">';
		echo '<option value="">' . esc_html__( 'Automatic (recommended)', 'openvault-backup' ) . '</option><option value="path">' . esc_html__( 'Path style', 'openvault-backup' ) . '</option><option value="virtual">' . esc_html__( 'Virtual-hosted style', 'openvault-backup' ) . '</option></select></p>';
		echo '<p class="ovb-option" data-ovb-presets="aws"><label for="ovb-s3-class"><strong>' . esc_html__( 'Storage class', 'openvault-backup' ) . '</strong></label><br><select id="ovb-s3-class" data-ovb-field="storage_class"><option value="">' . esc_html__( 'Standard (default)', 'openvault-backup' ) . '</option>';
		foreach ( array_slice( S3Presets::STORAGE_CLASSES, 1 ) as $class ) {
			echo '<option value="' . esc_attr( $class ) . '">' . esc_html( $class ) . '</option>';
		}
		echo '</select></p>';
		echo '<p class="ovb-option" data-ovb-presets="custom"><label for="ovb-s3-http"><input type="checkbox" id="ovb-s3-http" data-ovb-field="allow_insecure_http" aria-describedby="ovb-s3-http-help"> <strong>' . esc_html__( 'Allow plain http', 'openvault-backup' ) . '</strong></label>';
		echo '<span class="description ovb-help" id="ovb-s3-http-help">' . esc_html__( 'Only for a server on a private network. Without https your backup data and signed requests travel unencrypted. Leave off for anything on the internet.', 'openvault-backup' ) . '</span></p>';
		echo '<p class="ovb-option" data-ovb-presets="custom"><label for="ovb-s3-private"><input type="checkbox" id="ovb-s3-private" data-ovb-field="allow_private_endpoint" aria-describedby="ovb-s3-private-help"> <strong>' . esc_html__( 'Allow a private network address', 'openvault-backup' ) . '</strong></label>';
		echo '<span class="description ovb-help" id="ovb-s3-private-help">' . esc_html__( 'Lets this site connect to an address on a local or private network. Turn it on only for a server you run yourself: it also lets the site reach internal systems. Cloud metadata addresses stay blocked either way.', 'openvault-backup' ) . '</span></p>';
		echo '</details>';
		echo '</div>';

		// Local group.
		echo '<div data-ovb-type="local" hidden><fieldset class="ovb-fieldset"><legend>' . esc_html__( 'Folder', 'openvault-backup' ) . '</legend>';
		echo '<p class="ovb-option"><label for="ovb-local-path"><strong>' . esc_html__( 'Folder path (optional)', 'openvault-backup' ) . '</strong></label><br>';
		echo '<input type="text" id="ovb-local-path" class="regular-text" data-ovb-field="path" maxlength="500" autocapitalize="none" spellcheck="false" aria-describedby="ovb-local-path-help">';
		echo '<span class="description ovb-help ovb-help--flush" id="ovb-local-path-help">' . esc_html__( 'An absolute path, for example /mnt/backups. Leave empty to use the plugin\'s own protected backup folder. The WordPress folder, wp-admin and wp-includes are refused. A folder outside the web root is safest.', 'openvault-backup' ) . '</span></p>';
		echo '</fieldset></div>';

		echo '<p class="ovb-form-actions"><button type="submit" class="button button-primary" id="ovb-dest-save">' . esc_html__( 'Save destination', 'openvault-backup' ) . '</button> ';
		echo '<button type="button" class="button" id="ovb-dest-cancel" hidden>' . esc_html__( 'Cancel editing', 'openvault-backup' ) . '</button></p>';
		echo '</form></section>';
	}
}
