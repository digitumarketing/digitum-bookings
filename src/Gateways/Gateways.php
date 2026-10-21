<?php
/**
 * Registry of payment gateways.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Gateways;

use Digitum\Bookings\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Built-in gateways in checkout order; other plugins add theirs with the `dgb_gateways` filter.
 */
final class Gateways {

	/**
	 * Gateways by id (built once per request).
	 *
	 * @var array<string, GatewayInterface>|null
	 */
	private ?array $gateways = null;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings service.
	 */
	public function __construct( private Settings $settings ) {}

	/**
	 * Every registered gateway, enabled or not.
	 *
	 * @return array<string, GatewayInterface>
	 */
	public function all(): array {
		if ( null !== $this->gateways ) {
			return $this->gateways;
		}
		$list = array(
			new Stripe( $this->settings ),
			new PayPal( $this->settings ),
			new BankTransfer( $this->settings ),
			new PayOnArrival( $this->settings ),
			new Telr( $this->settings ),
			new NGenius( $this->settings ),
		);

		/**
		 * Filters the payment gateways. Add an object implementing GatewayInterface to offer a new method.
		 *
		 * @param GatewayInterface[] $list     Gateways in checkout order.
		 * @param Settings           $settings Settings service.
		 */
		$list = (array) apply_filters( 'dgb_gateways', $list, $this->settings );

		$this->gateways = array();
		foreach ( $list as $gateway ) {
			if ( $gateway instanceof GatewayInterface ) {
				$this->gateways[ $gateway->id() ] = $gateway;
			}
		}
		return $this->gateways;
	}

	/**
	 * One gateway.
	 *
	 * @param string $id Gateway id.
	 */
	public function get( string $id ): ?GatewayInterface {
		return $this->all()[ $id ] ?? null;
	}

	/**
	 * Gateways the guest can choose.
	 *
	 * @return array<string, GatewayInterface>
	 */
	public function enabled(): array {
		return array_filter( $this->all(), static fn( GatewayInterface $gateway ) => $gateway->is_enabled() );
	}
}
