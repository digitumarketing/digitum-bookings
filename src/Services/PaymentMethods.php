<?php
/**
 * Payment methods offered at checkout.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

use Digitum\Bookings\Gateways\Gateways;
use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * The enabled gateways as checkout options. The `dgb_payment_methods` filter can still reorder, relabel or
 * remove them.
 */
final class PaymentMethods {

	/**
	 * Constructor.
	 *
	 * @param Settings      $settings Settings service.
	 * @param Gateways|null $gateways Gateway registry.
	 */
	public function __construct( private Settings $settings, private ?Gateways $gateways = null ) {}

	/**
	 * Methods the guest can choose, in display order.
	 *
	 * @return array<string, array{id: string, label: string, description: string, status: string, online: bool}>
	 *         `status` is the booking status a new booking gets with this method.
	 */
	public function available(): array {
		$methods = array();
		foreach ( ( $this->gateways ?? new Gateways( $this->settings ) )->enabled() as $id => $gateway ) {
			$methods[ $id ] = array(
				'id'          => $id,
				'label'       => $gateway->label(),
				'description' => $gateway->description(),
				'status'      => $gateway->booking_status(),
				'online'      => $gateway->is_online(),
			);
		}

		/**
		 * Filters the payment methods offered at checkout.
		 *
		 * @param array    $methods  Methods keyed by id: id, label, description, status, online.
		 * @param Settings $settings Settings service.
		 */
		$methods = (array) apply_filters( 'dgb_payment_methods', $methods, $this->settings );

		return array_filter( $methods, static fn( $method ) => is_array( $method ) && ! empty( $method['id'] ) && ! empty( $method['label'] ) );
	}
}
