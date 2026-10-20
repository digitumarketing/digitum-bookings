<?php
/**
 * Payment methods offered at checkout.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Pay on arrival and bank transfer come from the settings; gateways (Stripe, PayPal) add themselves
 * through the `dgb_payment_methods` filter.
 */
final class PaymentMethods {

	/**
	 * Settings service.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Methods the guest can choose, in display order.
	 *
	 * @return array<string, array{id: string, label: string, description: string, status: string}>
	 *         `status` is the booking status a new booking gets with this method.
	 */
	public function available(): array {
		$payments = $this->settings->all()['payments'];
		$methods  = array();

		$has_bank = '' !== trim( (string) $payments['bank_iban'] ) || '' !== trim( (string) $payments['bank_account_number'] );
		if ( ! empty( $payments['bank_enabled'] ) && $has_bank ) {
			$methods['bank_transfer'] = array(
				'id'          => 'bank_transfer',
				'label'       => __( 'Bank transfer', 'digitum-bookings' ),
				'description' => __( 'Bank details are shown after you book. Upload the transfer screenshot there.', 'digitum-bookings' ),
				'status'      => 'awaiting_transfer',
			);
		}
		if ( ! empty( $payments['arrival_enabled'] ) ) {
			$methods['arrival'] = array(
				'id'          => 'arrival',
				'label'       => __( 'Pay on arrival', 'digitum-bookings' ),
				'description' => __( 'Cash or card at the meeting point.', 'digitum-bookings' ),
				'status'      => 'confirmed',
			);
		}

		/**
		 * Filters the payment methods offered at checkout. Gateways add themselves here.
		 *
		 * @param array    $methods  Methods keyed by id: id, label, description, status.
		 * @param Settings $settings Settings service.
		 */
		$methods = (array) apply_filters( 'dgb_payment_methods', $methods, $this->settings );

		return array_filter( $methods, static fn( $method ) => is_array( $method ) && ! empty( $method['id'] ) && ! empty( $method['label'] ) );
	}
}
