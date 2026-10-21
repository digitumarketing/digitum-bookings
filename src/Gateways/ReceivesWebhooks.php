<?php
/**
 * Gateways that receive webhooks at /wp-json/dgb/v1/webhooks/{gateway}.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Services\PaymentService;

defined( 'ABSPATH' ) || exit;

/**
 * Implemented by gateways that send signed event notifications.
 */
interface ReceivesWebhooks {

	/**
	 * Verify and handle one webhook delivery. Must be idempotent: gateways retry deliveries.
	 *
	 * @param string                $body     Raw request body.
	 * @param array<string, string> $headers  Request headers, lower-case names.
	 * @param PaymentService        $payments Payment service.
	 * @return array<string, mixed>|\WP_Error Result for the response body; an error with status 400 when the signature fails.
	 */
	public function handle_webhook( string $body, array $headers, PaymentService $payments ): array|\WP_Error;
}
