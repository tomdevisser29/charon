<?php
/**
 * Plugin Name: Charon
 * Description: Forwards PHP errors to an external error-tracking service — so nothing is left behind.
 * Version: 1.0.0
 * Author: Tom de Visser
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: charon
 */

defined( 'ABSPATH' ) or die;
define( 'HADES_ENDPOINT', 'http://127.0.0.1:3000/api/errors' );

/**
 * On activation, enable error reporting.
 */
function charon_activate() {
	error_reporting( E_ALL );
}
register_activation_hook( __FILE__, 'charon_activate' );

/**
 * Initialize custom error handling.
 */
function charon_boot() {
	set_error_handler( 'charon_handle_error' );
	set_exception_handler( 'charon_handle_exception' );
	register_shutdown_function( 'charon_handle_shutdown' );
}

/**
 * Sends error data to the Charon endpoint.
 *
 * @param array $data Error or exception data.
 */
function charon_send_payload( $data ) {
	$theme = wp_get_theme();

	$data['site']        = site_url();
	$data['php_version'] = phpversion();
	$data['wp_version']  = get_bloginfo( 'version' );
	$data['wp_theme']    = array(
		'name'       => $theme->get( 'Name' ),
		'version'    => $theme->get( 'Version' ),
		'stylesheet' => $theme->get_stylesheet(),
	);
	$data['wp_plugins']  = get_plugins();
	$data['fingerprint'] = md5( $data['type'] . $data['message'] . $data['file'] . $data['line'] );

	wp_remote_post(
		HADES_ENDPOINT,
		array(
			'headers'  => array(
				'Content-Type' => 'application/json',
			),
			'body'     => wp_json_encode( $data ),
			'timeout'  => 0.1,
			'blocking' => false,
		)
	);
}

/**
 * Handle standard PHP errors.
 *
 * @param int    $errno   Error number.
 * @param string $errstr  Error message.
 * @param string $errfile Filename.
 * @param int    $errline Line number.
 *
 * @return bool False to continue default error handling.
 */
function charon_handle_error( $errno, $errstr, $errfile, $errline ) {
	if ( ! ( error_reporting() & $errno ) ) {
		return false;
	}

	$data = array(
		'type'      => charon_get_error_type( $errno ),
		'errno'     => $errno,
		'message'   => $errstr,
		'file'      => $errfile,
		'line'      => $errline,
		'backtrace' => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ),
		'timestamp' => time(),
	);

	charon_send_payload( $data );

	return false;
}

/**
 * Handle uncaught exceptions.
 *
 * @param Throwable $exception Exception instance.
 */
function charon_handle_exception( $exception ) {
	$data = array(
		'type'      => 'exception',
		'message'   => $exception->getMessage(),
		'file'      => $exception->getFile(),
		'line'      => $exception->getLine(),
		'trace'     => $exception->getTrace(),
		'timestamp' => time(),
	);

	charon_send_payload( $data );
}

/**
 * Handle fatal shutdown errors.
 */
function charon_handle_shutdown() {
	$error = error_get_last();

	if ( is_array( $error ) ) {
		$data = array(
			'type'      => 'shutdown',
			'message'   => $error['message'],
			'file'      => $error['file'],
			'line'      => $error['line'],
			'errno'     => $error['type'],
			'timestamp' => time(),
		);

		charon_send_payload( $data );
	}
}

/**
 * Get the string representation of a PHP error type.
 *
 * @param int $errno The PHP error number.
 * @return string The error type string (e.g., 'php_warning', 'php_notice').
 */
function charon_get_error_type( $errno ) {
	switch ( $errno ) {
		case E_WARNING:
		case E_USER_WARNING:
			return 'php_warning';
		case E_NOTICE:
		case E_USER_NOTICE:
			return 'php_notice';
		case E_DEPRECATED:
		case E_USER_DEPRECATED:
			return 'php_deprecated';
		case E_STRICT:
			return 'php_strict';
		case E_ERROR:
		case E_USER_ERROR:
			return 'php_fatal';
		default:
			return 'php_error';
	}
}

charon_boot();
