<?php
/**
 * Harness helper for http-e2e.sh (runs INSIDE WordPress with `wp eval-file e2e-s3.php <command> <bucket> [prefix]`).
 * The plugin never creates buckets, so the harness does it with a signed request to the matrix S3 server
 * (OVB_S3_ENDPOINT, OVB_S3_ACCESS, OVB_S3_SECRET from the environment).
 *
 *   bucket-create <bucket>          prints the HTTP status
 *   bucket-delete <bucket>          empties the bucket, deletes it, prints the HTTP status
 *   list <bucket> [prefix]          prints "<size> <key>" per object
 *
 * @package OpenVault\Backup
 */

// phpcs:disable

use OpenVault\Backup\Destinations\S3\S3Client;
use OpenVault\Backup\Destinations\S3\S3Presets;
use OpenVault\Backup\Destinations\S3\SigV4Signer;

$endpoint = (string) getenv( 'OVB_S3_ENDPOINT' );
$access   = (string) getenv( 'OVB_S3_ACCESS' );
$secret   = (string) getenv( 'OVB_S3_SECRET' );
$command  = isset( $args[0] ) ? $args[0] : '';
$bucket   = isset( $args[1] ) ? $args[1] : '';
$prefix   = isset( $args[2] ) ? $args[2] : '';

$call = static function ( $method ) use ( $endpoint, $access, $secret, $bucket ) {
	$parts   = parse_url( $endpoint );
	$host    = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	$signer  = new SigV4Signer( $access, $secret, 'us-east-1' );
	$headers = $signer->sign( $method, '/' . $bucket, array(), array( 'host' => $host ) );
	unset( $headers['host'] );
	$response = wp_remote_request( $endpoint . '/' . $bucket, array( 'method' => $method, 'headers' => $headers, 'timeout' => 20, 'reject_unsafe_urls' => false ) );
	return is_wp_error( $response ) ? $response->get_error_message() : (int) wp_remote_retrieve_response_code( $response );
};

$client = static function ( $prefix_value ) use ( $endpoint, $access, $secret, $bucket ) {
	$config = S3Presets::resolve(
		array(
			'preset'                 => 'custom',
			'endpoint'               => $endpoint,
			'addressing'             => 'path',
			'allow_insecure_http'    => true,
			'allow_private_endpoint' => true,
			'bucket'                 => $bucket,
			'prefix'                 => '',
		)
	);
	return new S3Client( $config, new SigV4Signer( $access, $secret, 'us-east-1' ) );
};

if ( 'bucket-create' === $command ) {
	echo $call( 'PUT' ), "\n";
} elseif ( 'bucket-delete' === $command ) {
	$s3 = $client( '' );
	foreach ( iterator_to_array( $s3->list_objects_v2( '' ), false ) as $object ) {
		$s3->delete_object( $object['key'] );
	}
	echo $call( 'DELETE' ), "\n";
} elseif ( 'list' === $command ) {
	foreach ( $client( '' )->list_objects_v2( $prefix ) as $object ) {
		echo $object['size'], ' ', $object['key'], "\n";
	}
}
