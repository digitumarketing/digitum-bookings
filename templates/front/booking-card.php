<?php
/**
 * Booking card. Override in a theme at digitum-bookings/front/booking-card.php
 * (keep the data-dgb-* attributes and field names: the script relies on them).
 *
 * @package Digitum\Bookings
 *
 * @var array $view See BookingCard::view().
 */

defined( 'ABSPATH' ) || exit;

$dgb_id      = $view['id'];
$dgb_enquiry = $view['on_request'];
$dgb_steps   = 'per_vehicle' === $view['mode']
	? array( 'units' => array( $view['quantity'], 1, 1 ) )
	: array(
		'adults'   => array( __( 'Adults', 'digitum-bookings' ), 1, 2 ),
		'children' => array( __( 'Children', 'digitum-bookings' ), 0, 0 ),
	);
?>
<section class="dgb-w dgb-bc<?php echo $view['sticky'] ? ' is-sticky' : ''; ?>" id="<?php echo esc_attr( $dgb_id ); ?>" data-dgb-card aria-labelledby="<?php echo esc_attr( $dgb_id ); ?>-title">
	<script type="application/json" data-dgb-card-config><?php echo wp_json_encode( $view['script'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>
	<div class="dgb-bc-head">
		<h2 class="dgb-bc-title" id="<?php echo esc_attr( $dgb_id ); ?>-title"><?php echo esc_html( $view['title'] ); ?></h2>
		<?php if ( $view['arrival'] && ! $dgb_enquiry ) : ?>
			<span class="dgb-bc-badge"><?php esc_html_e( 'Pay on arrival', 'digitum-bookings' ); ?></span>
		<?php endif; ?>
	</div>

	<?php // Without JavaScript the button still opens the tour page with the booking form. ?>
	<form class="dgb-bc-form" data-dgb-card-form method="get" action="<?php echo esc_url( (string) get_permalink( $view['product'] ) ); ?>" novalidate>
		<input type="hidden" name="book" value="1">

		<div class="dgb-bc-field">
			<span class="dgb-bc-label" id="<?php echo esc_attr( $dgb_id ); ?>-date-label"><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></span>
			<button type="button" class="dgb-bc-date" data-dgb-card-date aria-haspopup="dialog" aria-expanded="false" aria-controls="<?php echo esc_attr( $dgb_id ); ?>-pop" aria-labelledby="<?php echo esc_attr( $dgb_id ); ?>-date-label <?php echo esc_attr( $dgb_id ); ?>-date-text">
				<span id="<?php echo esc_attr( $dgb_id ); ?>-date-text" data-dgb-card-date-text><?php esc_html_e( 'Choose a date', 'digitum-bookings' ); ?></span>
				<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/></svg>
			</button>
			<div class="dgb-bc-pop" id="<?php echo esc_attr( $dgb_id ); ?>-pop" data-dgb-card-pop role="dialog" aria-label="<?php esc_attr_e( 'Choose a date', 'digitum-bookings' ); ?>" hidden>
				<div class="dgb-cal-head">
					<button type="button" class="dgb-icon-btn" data-dgb-card-prev aria-label="<?php esc_attr_e( 'Previous month', 'digitum-bookings' ); ?>">‹</button>
					<p class="dgb-cal-month" data-dgb-card-month aria-live="polite"></p>
					<button type="button" class="dgb-icon-btn" data-dgb-card-next aria-label="<?php esc_attr_e( 'Next month', 'digitum-bookings' ); ?>">›</button>
				</div>
				<div class="dgb-cal-grid" data-dgb-card-days></div>
			</div>
		</div>

		<?php if ( count( $view['variants'] ) > 1 ) : ?>
			<fieldset class="dgb-bc-field dgb-bc-pills">
				<legend class="dgb-bc-label"><?php esc_html_e( 'Option', 'digitum-bookings' ); ?></legend>
				<div class="dgb-bc-pill-row">
				<?php foreach ( $view['variants'] as $dgb_i => $dgb_v ) : ?>
					<label class="dgb-bc-pill">
						<input type="radio" name="variant_id" value="<?php echo esc_attr( (string) $dgb_v['id'] ); ?>" <?php checked( 0, $dgb_i ); ?>>
						<span class="dgb-bc-pill-name"><?php echo esc_html( $dgb_v['label'] ); ?></span>
						<?php if ( '' !== $dgb_v['price'] && ! $dgb_enquiry ) : ?>
							<span class="dgb-bc-pill-price"><?php echo esc_html( $dgb_v['price'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $dgb_v['badge'] ) : ?>
							<span class="dgb-bc-pill-badge"><?php echo esc_html( $dgb_v['badge'] ); ?></span>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
				</div>
			</fieldset>
		<?php elseif ( 1 === count( $view['variants'] ) ) : ?>
			<input type="hidden" name="variant_id" value="<?php echo esc_attr( (string) $view['variants'][0]['id'] ); ?>">
		<?php endif; ?>

		<div class="dgb-bc-steppers">
			<?php foreach ( $dgb_steps as $dgb_key => $dgb_step ) : ?>
				<div class="dgb-bc-field dgb-bc-stepper">
					<label class="dgb-bc-label" for="<?php echo esc_attr( $dgb_id . '-' . $dgb_key ); ?>"><?php echo esc_html( $dgb_step[0] ); ?></label>
					<div class="dgb-bc-step">
						<button type="button" class="dgb-bc-btn" data-dgb-card-dec="<?php echo esc_attr( $dgb_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: field, e.g. Adults. */ __( 'Fewer: %s', 'digitum-bookings' ), $dgb_step[0] ) ); ?>">−</button>
						<input type="number" inputmode="numeric" id="<?php echo esc_attr( $dgb_id . '-' . $dgb_key ); ?>" name="<?php echo esc_attr( $dgb_key ); ?>" value="<?php echo esc_attr( (string) $dgb_step[2] ); ?>" min="<?php echo esc_attr( (string) $dgb_step[1] ); ?>" max="50">
						<button type="button" class="dgb-bc-btn" data-dgb-card-inc="<?php echo esc_attr( $dgb_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: field, e.g. Adults. */ __( 'More: %s', 'digitum-bookings' ), $dgb_step[0] ) ); ?>">+</button>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( count( $view['pickups'] ) > 1 ) : ?>
			<div class="dgb-bc-field">
				<label class="dgb-bc-label" for="<?php echo esc_attr( $dgb_id ); ?>-pickup"><?php esc_html_e( 'Pickup', 'digitum-bookings' ); ?></label>
				<select id="<?php echo esc_attr( $dgb_id ); ?>-pickup" name="pickup_choice" data-dgb-card-pickup>
					<?php foreach ( $view['pickups'] as $dgb_i => $dgb_p ) : ?>
						<option value="<?php echo esc_attr( (string) $dgb_i ); ?>" <?php selected( $view['default'], $dgb_i ); ?>><?php echo esc_html( $dgb_p['label'] ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
		<?php endif; ?>

		<?php if ( ! $dgb_enquiry ) : ?>
			<p class="dgb-bc-total">
				<span data-dgb-card-total-label><?php esc_html_e( 'Total', 'digitum-bookings' ); ?></span>
				<strong data-dgb-card-total aria-live="polite"><?php echo esc_html( $view['from'] ); ?></strong>
			</p>
		<?php endif; ?>

		<div class="dgb-bc-actions">
			<button type="submit" class="dgb-btn dgb-btn-primary" data-dgb-card-book><?php echo $dgb_enquiry ? esc_html__( 'Request a quote', 'digitum-bookings' ) : esc_html__( 'Check availability', 'digitum-bookings' ); ?></button>
			<?php if ( '' !== $view['whatsapp'] ) : ?>
				<a class="dgb-btn dgb-bc-wa" href="<?php echo esc_url( 'https://wa.me/' . $view['whatsapp'] ); ?>" target="_blank" rel="noopener" data-dgb-card-wa>
					<svg aria-hidden="true" width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.3-.4.7-1.3a.4.4 0 0 0 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6a2.7 2.7 0 0 0 1.8-1.2 2.2 2.2 0 0 0 .1-1.3c0-.1-.2-.2-.5-.3Z"/></svg>
					<?php esc_html_e( 'Ask on WhatsApp', 'digitum-bookings' ); ?>
				</a>
			<?php endif; ?>
		</div>

		<?php if ( array() !== $view['trust'] ) : ?>
			<ul class="dgb-bc-trust">
				<?php foreach ( $view['trust'] as $dgb_line ) : ?>
					<li><?php echo esc_html( $dgb_line ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</form>
</section>
