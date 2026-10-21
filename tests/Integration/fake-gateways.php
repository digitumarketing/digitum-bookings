<?php
/**
 * Test-only must-use plugin: in-memory Stripe and PayPal APIs.
 *
 * The gateways talk to https://stripe.fake and https://paypal.fake (through the dgb_*_api_base filters);
 * requests are answered here from state kept in an option, so every test step (a fresh PHP process) and
 * every browser request sees the same accounts. The fake browser SDKs in tests/e2e call the dgb-test/v1
 * routes below to "pay". Never install this on a real site.
 *
 * @package Digitum\Bookings
 */

// phpcs:ignoreFile -- test harness, not shipped.

const DGB_FAKE_OPTION  = 'dgb_fake_gateways';
const DGB_FAKE_WEBHOOK = 'whsec_fake_secret';

add_filter( 'dgb_stripe_api_base', static fn() => 'https://stripe.fake' );
add_filter( 'dgb_paypal_api_base', static fn() => 'https://paypal.fake' );
add_filter( 'pre_http_request', 'dgb_fake_http', 10, 3 );

foreach ( array( 'dgb_payment_completed', 'dgb_payment_failed', 'dgb_payment_refunded', 'dgb_payment_proof_uploaded', 'dgb_booking_status_changed' ) as $dgb_hook ) {
	add_action(
		$dgb_hook,
		static function () use ( $dgb_hook ) {
			$state                      = dgb_fake_state();
			$state['hooks'][ $dgb_hook ] = ( $state['hooks'][ $dgb_hook ] ?? 0 ) + 1;
			dgb_fake_state( $state );
		}
	);
}

function dgb_fake_state( ?array $set = null ): array {
	global $wpdb;
	if ( null !== $set ) {
		// Written straight to the table: the options cache of a parallel request may be stale.
		$value = maybe_serialize( $set );
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", DGB_FAKE_OPTION ) );
		if ( $found ) {
			$wpdb->update( $wpdb->options, array( 'option_value' => $value ), array( 'option_name' => DGB_FAKE_OPTION ) );
		} else {
			$wpdb->insert( $wpdb->options, array( 'option_name' => DGB_FAKE_OPTION, 'option_value' => $value, 'autoload' => 'no' ) );
		}
		wp_cache_delete( DGB_FAKE_OPTION, 'options' );
		return $set;
	}
	// Read past the object cache: browser requests run in parallel processes.
	$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", DGB_FAKE_OPTION ) );
	$state = $raw ? maybe_unserialize( $raw ) : array();
	return array_merge( array( 'n' => 0, 'pi' => array(), 'refunds' => array(), 'orders' => array(), 'pp_refunds' => array(), 'idem' => array(), 'log' => array(), 'hooks' => array() ), is_array( $state ) ? $state : array() );
}

function dgb_fake_id( array &$state, string $prefix ): string {
	++$state['n'];
	return $prefix . str_pad( (string) $state['n'], 6, '0', STR_PAD_LEFT );
}

function dgb_fake_response( int $code, $body ): array {
	return array(
		'headers'  => array( 'content-type' => 'application/json' ),
		'body'     => is_string( $body ) ? $body : wp_json_encode( $body ),
		'response' => array( 'code' => $code, 'message' => '' ),
		'cookies'  => array(),
		'filename' => null,
	);
}

function dgb_fake_http( $pre, $args, $url ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( ! in_array( $host, array( 'stripe.fake', 'paypal.fake' ), true ) ) {
		return $pre;
	}
	$state   = dgb_fake_state();
	$method  = strtoupper( (string) ( $args['method'] ?? 'GET' ) );
	$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ), CASE_LOWER );
	$body    = $args['body'] ?? null;

	$state['log'][] = array(
		'method'  => $method,
		'url'     => $url,
		'auth'    => (string) ( $headers['authorization'] ?? '' ),
		'idem'    => (string) ( $headers['idempotency-key'] ?? $headers['paypal-request-id'] ?? '' ),
		'body'    => is_string( $body ) ? $body : $body,
	);
	$state['log'] = array_slice( $state['log'], -80 );

	[ $code, $response ] = 'stripe.fake' === $host
		? dgb_fake_stripe( $state, $method, $path, $query, $headers, is_array( $body ) ? $body : array() )
		: dgb_fake_paypal( $state, $method, $path, $headers, is_string( $body ) ? $body : '' );

	dgb_fake_state( $state );
	return dgb_fake_response( $code, $response );
}

/* Stripe ------------------------------------------------------------------------------------------------ */

function dgb_fake_stripe( array &$state, string $method, string $path, array $query, array $headers, array $body ): array {
	if ( ! str_starts_with( (string) ( $headers['authorization'] ?? '' ), 'Bearer sk_test_' ) ) {
		return array( 401, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Invalid API Key provided.' ) ) );
	}
	$idem = (string) ( $headers['idempotency-key'] ?? '' );
	if ( 'POST' === $method && '' !== $idem && isset( $state['idem'][ 'stripe:' . $idem ] ) ) {
		return $state['idem'][ 'stripe:' . $idem ];
	}

	if ( 'POST' === $method && '/v1/payment_intents' === $path ) {
		$amount = (int) ( $body['amount'] ?? 0 );
		if ( $amount <= 0 || empty( $body['currency'] ) ) {
			return array( 400, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'Missing amount or currency.' ) ) );
		}
		$id                = dgb_fake_id( $state, 'pi_fake' );
		$state['pi'][ $id ] = array(
			'id'                   => $id,
			'object'               => 'payment_intent',
			'amount'               => $amount,
			'amount_received'      => 0,
			'currency'             => (string) $body['currency'],
			'status'               => 'requires_payment_method',
			'client_secret'        => $id . '_secret_' . substr( md5( $id ), 0, 12 ),
			'metadata'             => (array) ( $body['metadata'] ?? array() ),
			'description'          => (string) ( $body['description'] ?? '' ),
			'receipt_email'        => (string) ( $body['receipt_email'] ?? '' ),
			'payment_method_types' => array( 'card' ),
			'latest_charge'        => null,
			'last_payment_error'   => null,
			'livemode'             => false,
			'created'              => time(),
		);
		$result = array( 200, $state['pi'][ $id ] );
	} elseif ( 'GET' === $method && preg_match( '#^/v1/payment_intents/([A-Za-z0-9_]+)$#', $path, $m ) ) {
		$result = isset( $state['pi'][ $m[1] ] ) ? array( 200, $state['pi'][ $m[1] ] ) : array( 404, array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'message' => 'No such payment_intent.' ) ) );
	} elseif ( 'POST' === $method && '/v1/refunds' === $path ) {
		$pi = $state['pi'][ (string) ( $body['payment_intent'] ?? '' ) ] ?? null;
		if ( ! $pi || 'succeeded' !== $pi['status'] ) {
			return array( 400, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => 'This PaymentIntent has no successful charge to refund.' ) ) );
		}
		$done   = array_sum( array_column( array_filter( $state['refunds'], static fn( $r ) => $r['payment_intent'] === $pi['id'] ), 'amount' ) );
		$amount = (int) ( $body['amount'] ?? ( $pi['amount_received'] - $done ) );
		if ( $amount > $pi['amount_received'] - $done ) {
			return array( 400, array( 'error' => array( 'type' => 'invalid_request_error', 'code' => 'amount_too_large', 'message' => 'Refund amount is greater than the unrefunded amount.' ) ) );
		}
		$id                     = dgb_fake_id( $state, 're_fake' );
		$state['refunds'][ $id ] = array(
			'id'             => $id,
			'object'         => 'refund',
			'amount'         => $amount,
			'charge'         => $pi['latest_charge'],
			'payment_intent' => $pi['id'],
			'currency'       => $pi['currency'],
			'status'         => 'succeeded',
			'metadata'       => (array) ( $body['metadata'] ?? array() ),
		);
		$result = array( 200, $state['refunds'][ $id ] );
	} elseif ( 'GET' === $method && '/v1/refunds' === $path ) {
		$list   = array_values( array_filter( $state['refunds'], static fn( $r ) => $r['payment_intent'] === ( $query['payment_intent'] ?? '' ) ) );
		$result = array( 200, array( 'object' => 'list', 'data' => $list, 'has_more' => false ) );
	} else {
		$result = array( 404, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => "Unrecognized request URL ($method $path)." ) ) );
	}

	if ( 'POST' === $method && '' !== $idem ) {
		$state['idem'][ 'stripe:' . $idem ] = $result;
	}
	return $result;
}

/**
 * What Stripe.js would do in the browser: confirm the PaymentIntent with a test card.
 * 4242… succeeds, 4000…0002 is declined, 4000002500003155 needs 3-D Secure (pass $authenticate).
 */
function dgb_fake_stripe_confirm( string $client_secret, string $card, ?bool $authenticate = null ): array {
	$state = dgb_fake_state();
	foreach ( $state['pi'] as $id => $pi ) {
		if ( $pi['client_secret'] !== $client_secret ) {
			continue;
		}
		if ( 'succeeded' === $pi['status'] ) {
			return array( 'status' => 'succeeded', 'id' => $id );
		}
		$charge = dgb_fake_id( $state, 'ch_fake' );
		$card   = preg_replace( '/\D/', '', $card );
		$error  = null;
		if ( '4000000000000002' === $card ) {
			$error = array( 'type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Your card was declined.', 'charge' => $charge );
		} elseif ( '4000002500003155' === $card && null === $authenticate ) {
			$state['pi'][ $id ]['status'] = 'requires_action';
			dgb_fake_state( $state );
			return array( 'status' => 'requires_action', 'id' => $id );
		} elseif ( '4000002500003155' === $card && false === $authenticate ) {
			$error = array( 'type' => 'card_error', 'code' => 'payment_intent_authentication_failure', 'message' => 'We are unable to authenticate your payment method. Please choose a different payment method and try again.', 'charge' => $charge );
		} elseif ( ! in_array( $card, array( '4242424242424242', '4000002500003155' ), true ) ) {
			$error = array( 'type' => 'card_error', 'code' => 'incorrect_number', 'message' => 'Your card number is incorrect.', 'charge' => $charge );
		}
		$state['pi'][ $id ]['latest_charge'] = $charge;
		if ( $error ) {
			$state['pi'][ $id ]['status']             = 'requires_payment_method';
			$state['pi'][ $id ]['last_payment_error'] = $error;
		} else {
			$state['pi'][ $id ]['status']             = 'succeeded';
			$state['pi'][ $id ]['amount_received']    = $state['pi'][ $id ]['amount'];
			$state['pi'][ $id ]['last_payment_error'] = null;
		}
		dgb_fake_state( $state );
		return $error ? array( 'status' => 'failed', 'id' => $id, 'error' => $error ) : array( 'status' => 'succeeded', 'id' => $id );
	}
	return array( 'status' => 'missing' );
}

/**
 * A signed Stripe webhook delivery: [ body, Stripe-Signature header ].
 */
function dgb_fake_stripe_event( string $type, string $pi_id, string $secret = DGB_FAKE_WEBHOOK, int $time = 0 ): array {
	$state  = dgb_fake_state();
	$pi     = $state['pi'][ $pi_id ] ?? array( 'id' => $pi_id );
	$object = 'charge.refunded' === $type
		? array( 'id' => $pi['latest_charge'] ?? 'ch_x', 'object' => 'charge', 'payment_intent' => $pi_id, 'amount_refunded' => 1 )
		: $pi;
	$event  = array( 'id' => dgb_fake_id( $state, 'evt_fake' ), 'object' => 'event', 'type' => $type, 'data' => array( 'object' => $object ) );
	dgb_fake_state( $state );
	$body = wp_json_encode( $event );
	$time = $time ? $time : time();
	return array( $body, 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret ) );
}

/* PayPal ------------------------------------------------------------------------------------------------ */

function dgb_fake_paypal( array &$state, string $method, string $path, array $headers, string $body ): array {
	if ( '/v1/oauth2/token' === $path ) {
		return 'Basic ' . base64_encode( 'client-fake:secret-fake' ) === ( $headers['authorization'] ?? '' )
			? array( 200, array( 'access_token' => 'A21-fake-token', 'token_type' => 'Bearer', 'expires_in' => 32400 ) )
			: array( 401, array( 'error' => 'invalid_client', 'error_description' => 'Client Authentication failed' ) );
	}
	if ( 'Bearer A21-fake-token' !== ( $headers['authorization'] ?? '' ) ) {
		return array( 401, array( 'name' => 'AUTHENTICATION_FAILURE', 'message' => 'Authentication failed.' ) );
	}
	$request_id = (string) ( $headers['paypal-request-id'] ?? '' );
	if ( 'POST' === $method && '' !== $request_id && isset( $state['idem'][ 'paypal:' . $request_id ] ) ) {
		return $state['idem'][ 'paypal:' . $request_id ];
	}
	$json = json_decode( $body, true );

	$error = static fn( string $issue, string $description ) => array( 422, array( 'name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed.', 'details' => array( array( 'issue' => $issue, 'description' => $description ) ) ) );

	if ( 'POST' === $method && '/v2/checkout/orders' === $path ) {
		$id                   = dgb_fake_id( $state, 'ORDER' );
		$state['orders'][ $id ] = array( 'id' => $id, 'intent' => $json['intent'] ?? '', 'status' => 'CREATED', 'purchase_units' => $json['purchase_units'] ?? array(), 'application_context' => $json['application_context'] ?? array() );
		$result               = array( 201, $state['orders'][ $id ] );
	} elseif ( preg_match( '#^/v2/checkout/orders/([A-Z0-9]+)$#', $path, $m ) && 'GET' === $method ) {
		$result = isset( $state['orders'][ $m[1] ] ) ? array( 200, $state['orders'][ $m[1] ] ) : array( 404, array( 'name' => 'RESOURCE_NOT_FOUND', 'details' => array( array( 'issue' => 'INVALID_RESOURCE_ID' ) ) ) );
	} elseif ( preg_match( '#^/v2/checkout/orders/([A-Z0-9]+)/capture$#', $path, $m ) && 'POST' === $method ) {
		$order = $state['orders'][ $m[1] ] ?? null;
		if ( ! $order ) {
			$result = array( 404, array( 'name' => 'RESOURCE_NOT_FOUND', 'details' => array( array( 'issue' => 'INVALID_RESOURCE_ID' ) ) ) );
		} elseif ( 'COMPLETED' === $order['status'] ) {
			$result = $error( 'ORDER_ALREADY_CAPTURED', 'Order already captured.' );
		} elseif ( ! empty( $order['decline'] ) ) {
			unset( $state['orders'][ $m[1] ]['decline'] );
			$state['orders'][ $m[1] ]['status'] = 'CREATED';
			$result = $error( 'INSTRUMENT_DECLINED', 'The instrument presented was either declined by the processor or bank, or it can\'t be used for this payment.' );
		} elseif ( 'APPROVED' !== $order['status'] ) {
			$result = $error( 'ORDER_NOT_APPROVED', 'Payer has not yet approved the Order for payment.' );
		} else {
			$capture = array(
				'id'                 => dgb_fake_id( $state, 'CAP' ),
				'status'             => 'COMPLETED',
				'amount'             => $order['purchase_units'][0]['amount'],
				'custom_id'          => $order['purchase_units'][0]['custom_id'] ?? '',
				'supplementary_data' => array( 'related_ids' => array( 'order_id' => $order['id'] ) ),
			);
			$state['orders'][ $m[1] ]['status']                                   = 'COMPLETED';
			$state['orders'][ $m[1] ]['purchase_units'][0]['payments']['captures'] = array( $capture );
			$state['captures'][ $capture['id'] ]                                 = $capture;
			$result = array( 201, $state['orders'][ $m[1] ] );
		}
	} elseif ( preg_match( '#^/v2/payments/captures/([A-Z0-9]+)/refund$#', $path, $m ) && 'POST' === $method ) {
		$capture = $state['captures'][ $m[1] ] ?? null;
		if ( ! $capture ) {
			$result = array( 404, array( 'name' => 'RESOURCE_NOT_FOUND', 'details' => array( array( 'issue' => 'INVALID_RESOURCE_ID' ) ) ) );
		} else {
			$done  = array_sum( array_map( static fn( $r ) => (float) $r['amount']['value'], array_filter( $state['pp_refunds'], static fn( $r ) => $r['capture'] === $m[1] ) ) );
			$value = (float) ( $json['amount']['value'] ?? ( (float) $capture['amount']['value'] - $done ) );
			if ( $value - ( (float) $capture['amount']['value'] - $done ) > 0.001 ) {
				$result = $error( 'REFUND_AMOUNT_EXCEEDED', 'The refund amount must be less than or equal to the capture amount that has not yet been refunded.' );
			} else {
				$id                         = dgb_fake_id( $state, 'REF' );
				$state['pp_refunds'][ $id ] = array( 'id' => $id, 'status' => 'COMPLETED', 'capture' => $m[1], 'amount' => array( 'value' => number_format( $value, 2, '.', '' ), 'currency_code' => $capture['amount']['currency_code'] ), 'note_to_payer' => $json['note_to_payer'] ?? '' );
				$result                     = array( 201, $state['pp_refunds'][ $id ] );
			}
		}
	} elseif ( '/v1/notifications/verify-webhook-signature' === $path && 'POST' === $method ) {
		$ok     = is_array( $json ) && 'valid-sig' === ( $json['transmission_sig'] ?? '' ) && is_array( $json['webhook_event'] ?? null )
			&& \Digitum\Bookings\Plugin::instance()->settings()->get( 'payments', 'paypal_webhook_id' ) === ( $json['webhook_id'] ?? '' );
		$result = array( 200, array( 'verification_status' => $ok ? 'SUCCESS' : 'FAILURE' ) );
	} else {
		$result = array( 404, array( 'name' => 'NOT_FOUND', 'message' => "No route ($method $path)" ) );
	}

	if ( 'POST' === $method && '' !== $request_id ) {
		$state['idem'][ 'paypal:' . $request_id ] = $result;
	}
	return $result;
}

/**
 * The guest approves (or the bank declines) an order at PayPal.
 */
function dgb_fake_paypal_approve( string $order_id, string $outcome = 'approve' ): bool {
	$state = dgb_fake_state();
	if ( ! isset( $state['orders'][ $order_id ] ) ) {
		return false;
	}
	$state['orders'][ $order_id ]['status'] = 'APPROVED';
	if ( 'decline' === $outcome ) {
		$state['orders'][ $order_id ]['decline'] = true;
	}
	dgb_fake_state( $state );
	return true;
}

/**
 * A PayPal webhook delivery: [ body, headers ].
 */
function dgb_fake_paypal_event( string $type, array $resource, bool $valid = true ): array {
	$state = dgb_fake_state();
	$event = array( 'id' => dgb_fake_id( $state, 'WH-EVT-' ), 'event_type' => $type, 'resource' => $resource );
	dgb_fake_state( $state );
	return array(
		wp_json_encode( $event ),
		array(
			'paypal-auth-algo'         => 'SHA256withRSA',
			'paypal-cert-url'          => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-fake',
			'paypal-transmission-id'   => 'tx-' . $event['id'],
			'paypal-transmission-sig'  => $valid ? 'valid-sig' : 'forged',
			'paypal-transmission-time' => gmdate( 'c' ),
		),
	);
}

/* Routes for the fake browser SDKs (tests/e2e) -------------------------------------------------------------- */

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'dgb-test/v1',
			'/stripe/confirm',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( WP_REST_Request $r ) => dgb_fake_stripe_confirm( (string) $r['client_secret'], (string) $r['card'], null === $r['authenticate'] ? null : (bool) $r['authenticate'] ),
			)
		);
		register_rest_route(
			'dgb-test/v1',
			'/paypal/approve',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static fn( WP_REST_Request $r ) => array( 'ok' => dgb_fake_paypal_approve( (string) $r['order_id'], (string) ( $r['outcome'] ?? 'approve' ) ) ),
			)
		);
		register_rest_route(
			'dgb-test/v1',
			'/state',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static fn() => dgb_fake_state(),
			)
		);
	}
);
