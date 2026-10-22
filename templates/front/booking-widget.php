<?php
/**
 * Booking widget. Override in a theme at digitum-bookings/front/booking-widget.php
 * (keep the data-dgb-* attributes and field names: the script relies on them).
 *
 * @package Digitum\Bookings
 *
 * @var array $view See Widget::view().
 */

defined( 'ABSPATH' ) || exit;

$dgb_id      = $view['id'];
$dgb_enquiry = $view['on_request'];
$dgb_step    = 0;
$dgb_counts  = array(
	'adults'   => array( __( 'Adults', 'digitum-bookings' ), '', 1 ),
	'children' => array( __( 'Children', 'digitum-bookings' ), '', 0 ),
	'infants'  => array( __( 'Infants', 'digitum-bookings' ), __( 'Free', 'digitum-bookings' ), 0 ),
);
if ( 'per_vehicle' === $view['mode'] ) {
	$dgb_counts = array_merge(
		array(
			/* translators: %d: seats per vehicle. */
			'units' => array( __( 'Vehicles', 'digitum-bookings' ), sprintf( _n( '%d seat each', '%d seats each', $view['seats'], 'digitum-bookings' ), $view['seats'] ), 1 ),
		),
		$dgb_counts
	);
}
$dgb_has_pickup = 'none' !== $view['policy'] || ! empty( $view['point'] );
$dgb_selectable = array_filter( $view['extras'], static fn( $e ) => ! $e['required'] );
?>
<section class="dgb-w" id="<?php echo esc_attr( $dgb_id ); ?>" data-dgb-widget aria-labelledby="<?php echo esc_attr( $dgb_id ); ?>-title" style="--dgb-accent: <?php echo esc_attr( $view['accent'] ); ?>">
	<style>
		.dgb-w{--dgb-accent:#B45309}.dgb-sticky,.dgb-teaser{display:none}.dgb-cal-grid{min-height:300px}.dgb-sessions{min-height:72px}
		@media (max-width:781px){.dgb-teaser{display:flex}.dgb-w .dgb-panel{display:none}.dgb-w .dgb-panel.is-open{display:flex}.dgb-sticky{display:flex}}
	</style>
	<script type="application/json" data-dgb-config><?php echo wp_json_encode( $view['script'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE ); ?></script>

	<div class="dgb-teaser">
		<p class="dgb-teaser-price">
			<?php if ( '' !== $view['from'] && ! $dgb_enquiry ) : ?>
				<span><?php esc_html_e( 'From', 'digitum-bookings' ); ?></span> <strong><?php echo esc_html( $view['from'] ); ?></strong> <span><?php echo esc_html( $view['basis'] ); ?></span>
			<?php else : ?>
				<strong><?php esc_html_e( 'Price on request', 'digitum-bookings' ); ?></strong>
			<?php endif; ?>
		</p>
		<button type="button" class="dgb-btn dgb-btn-primary" data-dgb-open aria-controls="<?php echo esc_attr( $dgb_id ); ?>-panel"><?php echo $dgb_enquiry ? esc_html__( 'Request a quote', 'digitum-bookings' ) : esc_html__( 'Book now', 'digitum-bookings' ); ?></button>
	</div>

	<div class="dgb-panel" id="<?php echo esc_attr( $dgb_id ); ?>-panel" data-dgb-panel>
		<div class="dgb-head">
			<h2 class="dgb-title" id="<?php echo esc_attr( $dgb_id ); ?>-title" tabindex="-1"><?php echo $dgb_enquiry ? esc_html__( 'Request a quote', 'digitum-bookings' ) : esc_html__( 'Book this tour', 'digitum-bookings' ); ?></h2>
			<p class="dgb-from">
				<?php if ( '' !== $view['from'] && ! $dgb_enquiry ) : ?>
					<?php esc_html_e( 'From', 'digitum-bookings' ); ?> <strong><?php echo esc_html( $view['from'] ); ?></strong> <?php echo esc_html( $view['basis'] ); ?>
				<?php else : ?>
					<strong><?php esc_html_e( 'Price on request', 'digitum-bookings' ); ?></strong>
				<?php endif; ?>
			</p>
			<p class="dgb-progress" data-dgb-progress aria-live="polite"></p>
			<button type="button" class="dgb-close" data-dgb-close aria-label="<?php esc_attr_e( 'Close booking', 'digitum-bookings' ); ?>">×</button>
		</div>

		<form class="dgb-form" data-dgb-form novalidate>
			<fieldset class="dgb-step" data-step="choose">
				<legend class="dgb-legend" tabindex="-1"><span class="dgb-num"><?php echo esc_html( (string) ++$dgb_step ); ?></span> <?php esc_html_e( 'Choose', 'digitum-bookings' ); ?></legend>

				<?php if ( $view['variants'] ) : ?>
					<div class="dgb-group" role="radiogroup" aria-labelledby="<?php echo esc_attr( $dgb_id ); ?>-opt-label">
						<p class="dgb-label" id="<?php echo esc_attr( $dgb_id ); ?>-opt-label"><?php esc_html_e( 'Option', 'digitum-bookings' ); ?></p>
						<div class="dgb-options">
							<?php foreach ( $view['variants'] as $dgb_i => $dgb_variant ) : ?>
								<label class="dgb-card">
									<input type="radio" name="variant_id" value="<?php echo esc_attr( (string) $dgb_variant['id'] ); ?>" <?php checked( 1 === count( $view['variants'] ) ); ?>>
									<span class="dgb-card-body">
										<span class="dgb-card-title"><?php echo esc_html( $dgb_variant['label'] ); ?></span>
										<?php if ( ! $dgb_enquiry && null !== $dgb_variant['price'] ) : ?>
											<span class="dgb-card-meta">
												<?php if ( $dgb_variant['compareAt'] ) : ?>
													<s><?php echo esc_html( \Digitum\Bookings\Frontend\Format::money( $dgb_variant['compareAt'], $view['currency'] ) ); ?></s>
												<?php endif; ?>
												<?php echo esc_html( \Digitum\Bookings\Frontend\Format::money( $dgb_variant['price'], $view['currency'] ) ); ?>
											</span>
										<?php endif; ?>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="dgb-field-error" data-dgb-error="variant" hidden></p>
					</div>
				<?php endif; ?>

				<div class="dgb-group">
					<p class="dgb-label" id="<?php echo esc_attr( $dgb_id ); ?>-date-label"><?php esc_html_e( 'Date', 'digitum-bookings' ); ?></p>
					<div class="dgb-cal" data-dgb-calendar>
						<div class="dgb-cal-head">
							<button type="button" class="dgb-icon-btn" data-dgb-prev aria-label="<?php esc_attr_e( 'Previous month', 'digitum-bookings' ); ?>">‹</button>
							<p class="dgb-cal-month" data-dgb-month aria-live="polite"></p>
							<button type="button" class="dgb-icon-btn" data-dgb-next-month aria-label="<?php esc_attr_e( 'Next month', 'digitum-bookings' ); ?>">›</button>
						</div>
						<div class="dgb-cal-grid" data-dgb-days role="group" aria-labelledby="<?php echo esc_attr( $dgb_id ); ?>-date-label"></div>
					</div>
					<p class="dgb-field-error" data-dgb-error="date" hidden></p>
				</div>

				<div class="dgb-group">
					<p class="dgb-label" id="<?php echo esc_attr( $dgb_id ); ?>-session-label"><?php esc_html_e( 'Session', 'digitum-bookings' ); ?></p>
					<div class="dgb-sessions" data-dgb-sessions role="radiogroup" aria-labelledby="<?php echo esc_attr( $dgb_id ); ?>-session-label" aria-live="polite">
						<p class="dgb-hint"><?php esc_html_e( 'Choose a date to see the times.', 'digitum-bookings' ); ?></p>
					</div>
					<p class="dgb-field-error" data-dgb-error="session" hidden></p>
				</div>
			</fieldset>

			<fieldset class="dgb-step" data-step="guests">
				<legend class="dgb-legend" tabindex="-1"><span class="dgb-num"><?php echo esc_html( (string) ++$dgb_step ); ?></span> <?php echo 'per_vehicle' === $view['mode'] ? esc_html__( 'Vehicles and guests', 'digitum-bookings' ) : esc_html__( 'Guests', 'digitum-bookings' ); ?></legend>
				<?php foreach ( $dgb_counts as $dgb_key => $dgb_count ) : ?>
					<div class="dgb-count">
						<label for="<?php echo esc_attr( $dgb_id . '-' . $dgb_key ); ?>">
							<span class="dgb-count-label"><?php echo esc_html( $dgb_count[0] ); ?></span>
							<?php if ( '' !== $dgb_count[1] ) : ?>
								<span class="dgb-count-note"><?php echo esc_html( $dgb_count[1] ); ?></span>
							<?php endif; ?>
						</label>
						<div class="dgb-stepper">
							<button type="button" class="dgb-icon-btn" data-dgb-dec="<?php echo esc_attr( $dgb_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Adults, Children… */ __( 'Fewer: %s', 'digitum-bookings' ), $dgb_count[0] ) ); ?>">−</button>
							<input type="number" inputmode="numeric" id="<?php echo esc_attr( $dgb_id . '-' . $dgb_key ); ?>" name="<?php echo esc_attr( $dgb_key ); ?>" value="<?php echo esc_attr( (string) $dgb_count[2] ); ?>" min="<?php echo 'adults' === $dgb_key || 'units' === $dgb_key ? '1' : '0'; ?>" max="50">
							<button type="button" class="dgb-icon-btn" data-dgb-inc="<?php echo esc_attr( $dgb_key ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: Adults, Children… */ __( 'More: %s', 'digitum-bookings' ), $dgb_count[0] ) ); ?>">+</button>
						</div>
					</div>
				<?php endforeach; ?>
				<?php if ( $view['min_age'] > 0 || $view['licence'] ) : ?>
					<p class="dgb-hint">
						<?php
						$dgb_rules = array();
						if ( $view['min_age'] > 0 ) {
							/* translators: %d: minimum age. */
							$dgb_rules[] = sprintf( __( 'Minimum age %d.', 'digitum-bookings' ), $view['min_age'] );
						}
						if ( $view['licence'] ) {
							$dgb_rules[] = __( 'The driver must show a driving licence.', 'digitum-bookings' );
						}
						echo esc_html( implode( ' ', $dgb_rules ) );
						?>
					</p>
				<?php endif; ?>
				<p class="dgb-field-error" data-dgb-error="guests" hidden></p>
			</fieldset>

			<?php if ( $dgb_has_pickup ) : ?>
				<fieldset class="dgb-step" data-step="pickup">
					<legend class="dgb-legend" tabindex="-1"><span class="dgb-num"><?php echo esc_html( (string) ++$dgb_step ); ?></span> <?php echo 'none' === $view['policy'] ? esc_html__( 'Meeting point', 'digitum-bookings' ) : esc_html__( 'Pickup or meeting point', 'digitum-bookings' ); ?></legend>
					<?php if ( 'none' !== $view['policy'] ) : ?>
						<div class="dgb-options dgb-options-2">
							<label class="dgb-card">
								<input type="radio" name="pickup" value="0" <?php checked( ! $view['pickup_on'] ); ?>>
								<span class="dgb-card-body">
									<span class="dgb-card-title"><?php echo $view['point'] ? esc_html( sprintf( /* translators: %s: meeting point. */ __( 'Meet us at %s', 'digitum-bookings' ), $view['point']['name'] ) ) : esc_html__( 'Meet us at the meeting point', 'digitum-bookings' ); ?></span>
									<span class="dgb-card-meta"><?php esc_html_e( 'Free', 'digitum-bookings' ); ?></span>
								</span>
							</label>
							<label class="dgb-card">
								<input type="radio" name="pickup" value="1" <?php checked( $view['pickup_on'] ); ?>>
								<span class="dgb-card-body">
									<span class="dgb-card-title"><?php esc_html_e( 'Hotel pickup', 'digitum-bookings' ); ?></span>
									<span class="dgb-card-meta"><?php echo 'included' === $view['policy'] ? esc_html__( 'Included', 'digitum-bookings' ) : ( '' !== $view['pickup_price'] ? esc_html( '+ ' . $view['pickup_price'] ) : '' ); ?></span>
								</span>
							</label>
						</div>
						<div class="dgb-pickup" data-dgb-pickup-fields hidden>
							<div class="dgb-field">
								<label for="<?php echo esc_attr( $dgb_id ); ?>-zone"><?php esc_html_e( 'Pickup area', 'digitum-bookings' ); ?></label>
								<select id="<?php echo esc_attr( $dgb_id ); ?>-zone" name="zone_id">
									<option value=""><?php esc_html_e( 'Choose…', 'digitum-bookings' ); ?></option>
									<?php foreach ( $view['zones'] as $dgb_zone ) : ?>
										<option value="<?php echo esc_attr( (string) $dgb_zone['id'] ); ?>" data-allowed="<?php echo $dgb_zone['allowed'] ? '1' : '0'; ?>"><?php echo esc_html( $dgb_zone['name'] ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="dgb-field-error" data-dgb-error="zone" hidden></p>
							</div>
							<div class="dgb-zone-note" data-dgb-zone-note role="status" hidden></div>
							<div class="dgb-field" data-dgb-hotel>
								<label for="<?php echo esc_attr( $dgb_id ); ?>-hotel"><?php esc_html_e( 'Hotel or address', 'digitum-bookings' ); ?></label>
								<input type="text" id="<?php echo esc_attr( $dgb_id ); ?>-hotel" name="hotel" autocomplete="street-address">
								<p class="dgb-field-error" data-dgb-error="hotel" hidden></p>
							</div>
							<div class="dgb-field" data-dgb-hotel>
								<label for="<?php echo esc_attr( $dgb_id ); ?>-room"><?php esc_html_e( 'Room number (optional)', 'digitum-bookings' ); ?></label>
								<input type="text" id="<?php echo esc_attr( $dgb_id ); ?>-room" name="room">
							</div>
							<p class="dgb-hint" data-dgb-window></p>
						</div>
					<?php endif; ?>
					<?php if ( $view['point'] ) : ?>
						<div class="dgb-meet" data-dgb-meet>
							<p><strong><?php echo esc_html( $view['point']['name'] ); ?></strong></p>
							<?php if ( '' !== (string) $view['point']['address'] ) : ?>
								<p><?php echo esc_html( $view['point']['address'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== (string) $view['point']['instructions'] ) : ?>
								<p><?php echo esc_html( $view['point']['instructions'] ); ?></p>
							<?php endif; ?>
							<?php if ( '' !== (string) $view['point']['map_url'] ) : ?>
								<p><a href="<?php echo esc_url( $view['point']['map_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in maps', 'digitum-bookings' ); ?></a></p>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<?php if ( $dgb_selectable || array_filter( $view['extras'], static fn( $e ) => $e['required'] ) ) : ?>
				<fieldset class="dgb-step" data-step="extras">
					<legend class="dgb-legend" tabindex="-1"><span class="dgb-num"><?php echo esc_html( (string) ++$dgb_step ); ?></span> <?php esc_html_e( 'Extras', 'digitum-bookings' ); ?></legend>
					<?php foreach ( $view['extras'] as $dgb_extra ) : ?>
						<label class="dgb-check">
							<input type="checkbox" name="addons[]" value="<?php echo esc_attr( (string) $dgb_extra['id'] ); ?>" <?php checked( $dgb_extra['required'] ); ?> <?php disabled( $dgb_extra['required'] ); ?>>
							<span>
								<?php echo esc_html( $dgb_extra['label'] ); ?>
								<span class="dgb-card-meta">
									<?php
									if ( 'on_request' === $dgb_extra['type'] ) {
										esc_html_e( 'Price on request', 'digitum-bookings' );
									} elseif ( null !== $dgb_extra['price'] && (float) $dgb_extra['price'] > 0 ) {
										$dgb_per = array(
											'per_person'  => __( 'per person', 'digitum-bookings' ),
											'per_vehicle' => __( 'per vehicle', 'digitum-bookings' ),
										)[ $dgb_extra['type'] ] ?? '';
										echo esc_html( trim( '+ ' . \Digitum\Bookings\Frontend\Format::money( $dgb_extra['price'], $view['currency'] ) . ' ' . $dgb_per ) );
									}
									if ( $dgb_extra['required'] ) {
										echo ' · ' . esc_html__( 'included in every booking', 'digitum-bookings' );
									}
									?>
								</span>
							</span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endif; ?>

			<fieldset class="dgb-step" data-step="details">
				<legend class="dgb-legend" tabindex="-1"><span class="dgb-num"><?php echo esc_html( (string) ++$dgb_step ); ?></span> <?php esc_html_e( 'Your details', 'digitum-bookings' ); ?></legend>
				<div class="dgb-field">
					<label for="<?php echo esc_attr( $dgb_id ); ?>-name"><?php esc_html_e( 'Full name', 'digitum-bookings' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $dgb_id ); ?>-name" name="name" autocomplete="name" required>
					<p class="dgb-field-error" data-dgb-error="name" hidden></p>
				</div>
				<div class="dgb-field">
					<label for="<?php echo esc_attr( $dgb_id ); ?>-email"><?php esc_html_e( 'Email', 'digitum-bookings' ); ?></label>
					<input type="email" id="<?php echo esc_attr( $dgb_id ); ?>-email" name="email" autocomplete="email" required aria-describedby="<?php echo esc_attr( $dgb_id ); ?>-email-hint">
					<p class="dgb-hint" id="<?php echo esc_attr( $dgb_id ); ?>-email-hint"><?php echo $dgb_enquiry ? esc_html__( 'We send the quote here.', 'digitum-bookings' ) : esc_html__( 'Your confirmation and receipt are sent here.', 'digitum-bookings' ); ?></p>
					<p class="dgb-field-error" data-dgb-error="email" hidden></p>
				</div>
				<div class="dgb-field">
					<label for="<?php echo esc_attr( $dgb_id ); ?>-phone"><?php esc_html_e( 'Phone', 'digitum-bookings' ); ?></label>
					<div class="dgb-phone">
						<input type="text" name="phone_code" value="<?php echo esc_attr( $view['country_code'] ); ?>" aria-label="<?php esc_attr_e( 'Country code', 'digitum-bookings' ); ?>" autocomplete="tel-country-code" inputmode="tel" size="5">
						<input type="tel" id="<?php echo esc_attr( $dgb_id ); ?>-phone" name="phone" autocomplete="tel-national" required>
					</div>
					<p class="dgb-field-error" data-dgb-error="phone" hidden></p>
				</div>
				<div class="dgb-field">
					<label for="<?php echo esc_attr( $dgb_id ); ?>-nationality"><?php esc_html_e( 'Nationality (optional)', 'digitum-bookings' ); ?></label>
					<input type="text" id="<?php echo esc_attr( $dgb_id ); ?>-nationality" name="nationality" autocomplete="country-name">
				</div>
				<div class="dgb-field">
					<label for="<?php echo esc_attr( $dgb_id ); ?>-requests"><?php echo $dgb_enquiry ? esc_html__( 'What are you planning?', 'digitum-bookings' ) : esc_html__( 'Special requests (optional)', 'digitum-bookings' ); ?></label>
					<textarea id="<?php echo esc_attr( $dgb_id ); ?>-requests" name="requests" rows="3"></textarea>
				</div>
				<label class="dgb-check"><input type="checkbox" name="marketing" value="1"> <span><?php esc_html_e( 'Send me offers and news (optional)', 'digitum-bookings' ); ?></span></label>
				<label class="dgb-check">
					<input type="checkbox" name="terms" value="1" required>
					<span>
						<?php if ( '' !== $view['terms_url'] ) : ?>
							<?php
							printf(
								/* translators: %s: link to the booking terms. */
								esc_html__( 'I accept the %s', 'digitum-bookings' ),
								'<a href="' . esc_url( $view['terms_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'booking terms', 'digitum-bookings' ) . '</a>'
							);
							?>
						<?php else : ?>
							<?php esc_html_e( 'I accept the booking terms', 'digitum-bookings' ); ?>
						<?php endif; ?>
					</span>
				</label>
				<p class="dgb-field-error" data-dgb-error="terms" hidden></p>
				<div class="dgb-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this empty', 'digitum-bookings' ); ?> <input type="text" name="dgb_website" tabindex="-1" autocomplete="off"></label>
				</div>
				<?php if ( '' !== $view['turnstile'] ) : ?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( $view['turnstile'] ); ?>" data-response-field-name="turnstile"></div>
				<?php endif; ?>
			</fieldset>

			<?php if ( ! $dgb_enquiry ) : ?>
				<fieldset class="dgb-step" data-step="pay">
					<legend class="dgb-legend" tabindex="-1"><span class="dgb-num"><?php echo esc_html( (string) ++$dgb_step ); ?></span> <?php esc_html_e( 'Payment', 'digitum-bookings' ); ?></legend>
					<?php if ( $view['methods'] ) : ?>
						<div class="dgb-options" role="radiogroup" aria-label="<?php esc_attr_e( 'Payment method', 'digitum-bookings' ); ?>">
							<?php foreach ( $view['methods'] as $dgb_m => $dgb_method ) : ?>
								<label class="dgb-card">
									<input type="radio" name="payment_method" value="<?php echo esc_attr( $dgb_method['id'] ); ?>" <?php checked( 1 === count( $view['methods'] ) ); ?>>
									<span class="dgb-card-body">
										<span class="dgb-card-title"><?php echo esc_html( $dgb_method['label'] ); ?></span>
										<span class="dgb-card-meta"><?php echo esc_html( $dgb_method['description'] ); ?></span>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php else : ?>
						<p class="dgb-hint"><?php esc_html_e( 'Online booking is not available right now. Please contact us.', 'digitum-bookings' ); ?></p>
					<?php endif; ?>
					<p class="dgb-field-error" data-dgb-error="payment_method" hidden></p>
					<?php if ( ! empty( $view['coupons'] ) ) : ?>
						<div class="dgb-field dgb-coupon">
							<label for="<?php echo esc_attr( $dgb_id ); ?>-coupon"><?php esc_html_e( 'Coupon code (optional)', 'digitum-bookings' ); ?></label>
							<div class="dgb-coupon-row">
								<input type="text" id="<?php echo esc_attr( $dgb_id ); ?>-coupon" name="coupon_entry" autocomplete="off" autocapitalize="characters" spellcheck="false" maxlength="50" aria-describedby="<?php echo esc_attr( $dgb_id ); ?>-coupon-status">
								<button type="button" class="dgb-btn dgb-btn-quiet" data-dgb-coupon><?php esc_html_e( 'Apply', 'digitum-bookings' ); ?></button>
							</div>
							<p class="dgb-hint" id="<?php echo esc_attr( $dgb_id ); ?>-coupon-status" data-dgb-coupon-status aria-live="polite"></p>
						</div>
					<?php endif; ?>
					<div class="dgb-options dgb-options-2" data-dgb-amount role="radiogroup" aria-label="<?php esc_attr_e( 'How much to pay now', 'digitum-bookings' ); ?>" hidden>
						<label class="dgb-card"><input type="radio" name="pay_deposit" value="0" checked> <span class="dgb-card-body"><span class="dgb-card-title"><?php esc_html_e( 'Full amount', 'digitum-bookings' ); ?></span><span class="dgb-card-meta" data-dgb-full-text></span></span></label>
						<label class="dgb-card"><input type="radio" name="pay_deposit" value="1"> <span class="dgb-card-body"><span class="dgb-card-title" data-dgb-deposit-title></span><span class="dgb-card-meta" data-dgb-deposit-text></span></span></label>
					</div>
					<?php if ( '' !== $view['cancellation'] ) : ?>
						<p class="dgb-hint"><?php echo esc_html( $view['cancellation'] ); ?></p>
					<?php endif; ?>
				</fieldset>
			<?php endif; ?>

			<div class="dgb-breakdown" data-dgb-breakdown aria-live="polite"<?php echo $dgb_enquiry ? ' hidden' : ''; ?>></div>
			<div class="dgb-errors" data-dgb-errors role="alert" tabindex="-1" hidden></div>

			<div class="dgb-foot">
				<button type="button" class="dgb-btn dgb-btn-quiet" data-dgb-back hidden><?php esc_html_e( 'Back', 'digitum-bookings' ); ?></button>
				<button type="button" class="dgb-btn dgb-btn-primary" data-dgb-next hidden><?php esc_html_e( 'Continue', 'digitum-bookings' ); ?></button>
				<button type="submit" class="dgb-btn dgb-btn-primary dgb-submit" data-dgb-submit><?php echo $dgb_enquiry ? esc_html__( 'Request a quote', 'digitum-bookings' ) : esc_html__( 'Book now', 'digitum-bookings' ); ?></button>
			</div>
		</form>
	</div>

	<div class="dgb-result" data-dgb-result></div>
	<noscript><p class="dgb-hint"><?php esc_html_e( 'Online booking needs JavaScript. Please enable it or contact us to book.', 'digitum-bookings' ); ?></p></noscript>

	<?php if ( $view['sticky'] ) : ?>
		<div class="dgb-sticky" data-dgb-sticky>
			<p class="dgb-teaser-price">
				<?php if ( '' !== $view['from'] && ! $dgb_enquiry ) : ?>
					<span><?php esc_html_e( 'From', 'digitum-bookings' ); ?></span> <strong><?php echo esc_html( $view['from'] ); ?></strong>
				<?php else : ?>
					<strong><?php esc_html_e( 'Price on request', 'digitum-bookings' ); ?></strong>
				<?php endif; ?>
			</p>
			<button type="button" class="dgb-btn dgb-btn-primary" data-dgb-open aria-controls="<?php echo esc_attr( $dgb_id ); ?>-panel"><?php echo $dgb_enquiry ? esc_html__( 'Request a quote', 'digitum-bookings' ) : esc_html__( 'Book', 'digitum-bookings' ); ?></button>
		</div>
	<?php endif; ?>
</section>
