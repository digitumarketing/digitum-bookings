<?php
/**
 * Test-only must-use plugin: keep every email (option dgb_test_mails) and every outgoing webhook to
 * hooks.example.test (option dgb_test_hooks; answers 500 while dgb_test_hook_fail is set) instead of sending them.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test harness, not shipped.

function dgb_test_store( string $name, array $entry ): void {
	global $wpdb;
	$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	$list = $raw ? maybe_unserialize( $raw ) : array();
	$list = is_array( $list ) ? $list : array();
	$list[] = $entry;
	if ( null === $raw ) {
		$wpdb->insert( $wpdb->options, array( 'option_name' => $name, 'option_value' => maybe_serialize( $list ), 'autoload' => 'no' ) );
	} else {
		$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $list ) ), array( 'option_name' => $name ) );
	}
}

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 'hooks.example.test' !== wp_parse_url( $url, PHP_URL_HOST ) ) {
			return $pre;
		}
		dgb_test_store( 'dgb_test_hooks', array( 'url' => $url, 'headers' => (array) $args['headers'], 'body' => (string) $args['body'] ) );
		$fail = get_option( 'dgb_test_hook_fail' );
		return array( 'headers' => array(), 'body' => $fail ? 'error' : 'ok', 'response' => array( 'code' => $fail ? 500 : 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		// Let the plugin's phpmailer_init callbacks fill in the plain-text part.
		$mailer          = new stdClass();
		$mailer->AltBody = '';
		do_action_ref_array( 'phpmailer_init', array( &$mailer ) );

		dgb_test_store(
			'dgb_test_mails',
			array(
				'to'          => (array) $atts['to'],
				'subject'     => (string) $atts['subject'],
				'html'        => (string) $atts['message'],
				'text'        => (string) $mailer->AltBody,
				'headers'     => (array) $atts['headers'],
				'attachments' => array_map(
					static function ( $f ) {
						$body = is_readable( $f ) ? (string) file_get_contents( $f ) : '';
						// Binary files (images) cannot go into the options table as text.
						return array(
							'name' => basename( $f ),
							'size' => strlen( $body ),
							'body' => preg_match( '//u', $body ) && false === strpos( $body, "\0" ) ? substr( $body, 0, 2000 ) : '[binary]',
						);
					},
					(array) $atts['attachments']
				),
			)
		);
		return true;
	},
	10,
	2
);
