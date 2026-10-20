<?php
/**
 * Booking references such as DGB-261020-7KQ3.
 *
 * @package Digitum\Bookings
 */

namespace Digitum\Bookings\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Random part uses letters and digits that cannot be confused (no 0/O, 1/I).
 * Uniqueness is enforced by the UNIQUE key on `dgb_bookings.reference`; the repository retries on a clash.
 */
final class ReferenceGenerator {

	public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	/**
	 * Random index source: fn( int $max ): int returning 0…$max.
	 *
	 * @var callable
	 */
	private $random;

	/**
	 * Constructor.
	 *
	 * @param string        $alphabet Characters of the random part.
	 * @param int           $length   Length of the random part.
	 * @param callable|null $random   Random source (tests).
	 */
	public function __construct(
		private string $alphabet = self::ALPHABET,
		private int $length = 4,
		?callable $random = null
	) {
		$this->random = $random ?? static fn( int $max ): int => random_int( 0, $max );
	}

	/**
	 * A new reference for a booking created on a date.
	 *
	 * @param \DateTimeImmutable $date Creation date in the site time zone.
	 */
	public function generate( \DateTimeImmutable $date ): string {
		$max  = strlen( $this->alphabet ) - 1;
		$code = '';
		for ( $i = 0; $i < $this->length; $i++ ) {
			$code .= $this->alphabet[ ( $this->random )( $max ) ];
		}
		return 'DGB-' . $date->format( 'ymd' ) . '-' . $code;
	}
}
