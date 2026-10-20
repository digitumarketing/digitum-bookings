<?php
/**
 * Booking confirmation. Override in a theme at digitum-bookings/front/confirmation.php.
 *
 * @package Digitum\Bookings
 *
 * @var array $view See Confirmation::view().
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="dgb-confirm" data-dgb-confirmation tabindex="-1"<?php echo $view['event'] ? ' data-dgb-event="' . esc_attr( (string) wp_json_encode( $view['event'] ) ) . '"' : ''; ?>>
	<h2 class="dgb-confirm-title"><?php echo esc_html( $view['title'] ); ?></h2>
	<p><?php echo esc_html( $view['text'] ); ?></p>

	<p class="dgb-ref">
		<span><?php echo 'quote_requested' === $view['status'] ? esc_html__( 'Request reference', 'digitum-bookings' ) : esc_html__( 'Booking reference', 'digitum-bookings' ); ?></span>
		<strong><?php echo esc_html( $view['reference'] ); ?></strong>
	</p>

	<dl class="dgb-summary">
		<dt><?php esc_html_e( 'Tour', 'digitum-bookings' ); ?></dt>
		<dd><?php echo esc_html( $view['tour'] ); ?><?php echo '' !== $view['option'] ? ' · ' . esc_html( $view['option'] ) : ''; ?></dd>
		<?php if ( '' !== $view['date'] ) : ?>
			<dt><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></dt>
			<dd><?php echo esc_html( $view['date'] ); ?></dd>
		<?php endif; ?>
		<?php if ( '' !== $view['session'] ) : ?>
			<dt><?php esc_html_e( 'Session', 'digitum-bookings' ); ?></dt>
			<dd><?php echo esc_html( $view['session'] . ( '' !== $view['start'] ? ' · ' . $view['start'] : '' ) ); ?></dd>
		<?php endif; ?>
		<dt><?php esc_html_e( 'Guests', 'digitum-bookings' ); ?></dt>
		<dd>
			<?php
			echo esc_html( $view['guests'] );
			if ( in_array( $view['mode'], array( 'per_vehicle', 'per_group' ), true ) ) {
				/* translators: %d: number of vehicles. */
				echo ' · ' . esc_html( sprintf( _n( '%d vehicle', '%d vehicles', $view['units'], 'digitum-bookings' ), $view['units'] ) );
			}
			?>
		</dd>
	</dl>

	<div class="dgb-box">
		<?php if ( $view['pickup'] ) : ?>
			<h3><?php esc_html_e( 'Hotel pickup', 'digitum-bookings' ); ?></h3>
			<p><?php echo esc_html( $view['pickup_text'] ); ?></p>
			<?php if ( '' !== $view['window'] ) : ?>
				<p>
					<?php
					/* translators: %s: pickup window, e.g. 2:15 pm – 2:45 pm. */
					echo esc_html( sprintf( __( 'Please be ready in the lobby between %s.', 'digitum-bookings' ), $view['window'] ) );
					?>
				</p>
			<?php endif; ?>
		<?php elseif ( $view['meeting'] ) : ?>
			<h3><?php esc_html_e( 'Where to meet', 'digitum-bookings' ); ?></h3>
			<p><strong><?php echo esc_html( $view['meeting']['name'] ); ?></strong><?php echo '' !== $view['start'] ? ' · ' . esc_html( sprintf( /* translators: %s: start time. */ __( 'session starts %s', 'digitum-bookings' ), $view['start'] ) ) : ''; ?></p>
			<?php if ( '' !== (string) $view['meeting']['address'] ) : ?>
				<p><?php echo esc_html( $view['meeting']['address'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== (string) $view['meeting']['instructions'] ) : ?>
				<p><?php echo esc_html( $view['meeting']['instructions'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== (string) $view['meeting']['map_url'] ) : ?>
				<p><a href="<?php echo esc_url( $view['meeting']['map_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in maps', 'digitum-bookings' ); ?></a></p>
			<?php endif; ?>
		<?php endif; ?>
	</div>

	<?php if ( $view['bank'] ) : ?>
		<div class="dgb-box dgb-bank">
			<h3>
				<?php
				/* translators: %s: amount to transfer. */
				echo esc_html( sprintf( __( 'Transfer %s', 'digitum-bookings' ), $view['bank']['amount'] ) );
				?>
			</h3>
			<dl class="dgb-summary">
				<?php
				$dgb_bank_rows = array(
					'account_name' => __( 'Account name', 'digitum-bookings' ),
					'bank_name'    => __( 'Bank', 'digitum-bookings' ),
					'iban'         => __( 'IBAN', 'digitum-bookings' ),
					'account'      => __( 'Account number', 'digitum-bookings' ),
					'swift'        => __( 'SWIFT / BIC', 'digitum-bookings' ),
				);
				foreach ( $dgb_bank_rows as $dgb_key => $dgb_label ) :
					if ( '' === $view['bank'][ $dgb_key ] ) {
						continue;
					}
					?>
					<dt><?php echo esc_html( $dgb_label ); ?></dt>
					<dd><span class="dgb-copyable"><?php echo esc_html( $view['bank'][ $dgb_key ] ); ?></span></dd>
				<?php endforeach; ?>
				<dt><?php esc_html_e( 'Payment reference', 'digitum-bookings' ); ?></dt>
				<dd><strong><?php echo esc_html( $view['reference'] ); ?></strong></dd>
			</dl>
			<?php if ( '' !== $view['bank']['instructions'] ) : ?>
				<p><?php echo esc_html( $view['bank']['instructions'] ); ?></p>
			<?php endif; ?>
			<?php if ( '' !== $view['bank']['hold'] ) : ?>
				<p>
					<?php
					/* translators: %s: date and time the booking is held until. */
					echo esc_html( sprintf( __( 'Your place is held until %s.', 'digitum-bookings' ), $view['bank']['hold'] ) );
					?>
				</p>
			<?php endif; ?>
			<?php if ( '' !== $view['bank']['whatsapp'] ) : ?>
				<p><a href="<?php echo esc_url( $view['bank']['whatsapp'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Send the transfer screenshot on WhatsApp', 'digitum-bookings' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $view['priced'] ) : ?>
		<dl class="dgb-summary dgb-money">
			<dt><?php esc_html_e( 'Total', 'digitum-bookings' ); ?></dt>
			<dd><strong><?php echo esc_html( $view['total'] ); ?></strong></dd>
			<dt><?php esc_html_e( 'Includes VAT', 'digitum-bookings' ); ?></dt>
			<dd><?php echo esc_html( $view['vat'] ); ?></dd>
			<?php if ( '' !== $view['deposit'] ) : ?>
				<dt><?php esc_html_e( 'Deposit', 'digitum-bookings' ); ?></dt>
				<dd><?php echo esc_html( $view['deposit'] ); ?></dd>
			<?php endif; ?>
			<dt><?php echo 'arrival' === $view['method'] ? esc_html__( 'To pay on arrival', 'digitum-bookings' ) : esc_html__( 'Still to pay', 'digitum-bookings' ); ?></dt>
			<dd><?php echo esc_html( $view['due'] ); ?></dd>
		</dl>
	<?php endif; ?>

	<?php if ( '' !== $view['cancellation'] ) : ?>
		<p class="dgb-note"><?php echo esc_html( $view['cancellation'] ); ?></p>
	<?php endif; ?>

	<p class="dgb-actions">
		<?php if ( '' !== $view['ics'] ) : ?>
			<a class="dgb-btn" href="<?php echo esc_url( $view['ics'] ); ?>" download><?php esc_html_e( 'Add to calendar', 'digitum-bookings' ); ?></a>
		<?php endif; ?>
		<?php if ( '' !== $view['email'] ) : ?>
			<a class="dgb-btn dgb-btn-quiet" href="<?php echo esc_url( 'mailto:' . $view['email'] . '?subject=' . rawurlencode( $view['reference'] ) ); ?>"><?php esc_html_e( 'Email us', 'digitum-bookings' ); ?></a>
		<?php endif; ?>
		<?php if ( '' !== $view['phone'] ) : ?>
			<a class="dgb-btn dgb-btn-quiet" href="<?php echo esc_url( 'tel:' . preg_replace( '/[^\d+]/', '', $view['phone'] ) ); ?>"><?php echo esc_html( $view['phone'] ); ?></a>
		<?php endif; ?>
	</p>
</div>
