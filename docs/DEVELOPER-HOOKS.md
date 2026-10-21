# Developer hooks

Every public action and filter of Digitum Bookings is listed here, with the release that added it.

## Filters

### `dgb_price_breakdown` (0.2.0)

Filters a price breakdown before it is shown or stored. Amounts are decimal strings.

```php
add_filter( 'dgb_price_breakdown', function ( array $breakdown, int $product_id, array $input ) {
	// $breakdown keys: currency, on_request, lines[], units, subtotal, discount, vat, vat_inclusive, vat_rate,
	// total, deposit_available, deposit_percent, deposit, pay_now, due_on_arrival, compare_at, has_quote_items, errors[], valid.
	return $breakdown;
}, 10, 3 );
```

### `dgb_available_sessions` (0.2.0)

Filters the sessions offered on a date. Each session has `id`, `name`, `type`, `start_time`, `end_time`, `pickup_window_start`, `pickup_window_end`, `status` (`open`, `past`, `too_far`, `closed`, `cutoff`) and `bookable`.

```php
add_filter( 'dgb_available_sessions', function ( array $sessions, int $product_id, string $date ) {
	return $sessions;
}, 10, 3 );
```

### `dgb_payment_methods` (0.3.0)

Filters the payment methods offered at checkout, built from the enabled gateways. Each method has `id`, `label`, `description`, `status` (the booking status it creates: `confirmed`, `awaiting_transfer` or `pending_payment`) and `online`. To add a new way to pay, use `dgb_gateways`.

```php
add_filter( 'dgb_payment_methods', function ( array $methods, \Digitum\Bookings\Settings\Settings $settings ) {
	unset( $methods['arrival'] ); // no pay on arrival on this site
	return $methods;
}, 10, 2 );
```

### `dgb_template_file` (0.3.0)

Filters the file used for a template (`front/booking-widget`, `front/confirmation`). Themes can also override a template by copying it to `your-theme/digitum-bookings/<name>.php`.

```php
add_filter( 'dgb_template_file', function ( string $file, string $name ) {
	return $file;
}, 10, 2 );
```

### `dgb_client_ip` (0.3.0)

Filters the visitor IP used for rate limiting. Behind Cloudflare or a proxy, return the real client IP.

```php
add_filter( 'dgb_client_ip', function ( string $ip ) {
	return isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) : $ip;
} );
```

### `dgb_gateways` (0.4.0)

Filters the payment gateways. Return objects implementing `Digitum\Bookings\Gateways\GatewayInterface` (usually by extending `AbstractGateway`); implement `ReceivesWebhooks` too to get a webhook URL at `/wp-json/dgb/v1/webhooks/{id}`. A gateway's `pay_box()` returns `type` `stripe`, `paypal` or `redirect` (with `url`, for hosted payment pages).

```php
add_filter( 'dgb_gateways', function ( array $gateways, \Digitum\Bookings\Settings\Settings $settings ) {
	$gateways[] = new My_Gateway( $settings );
	return $gateways;
}, 10, 2 );
```

### `dgb_bank_details` (0.4.0)

Filters the bank details on the "booking received" page: `amount`, `account_name`, `bank_name`, `iban`, `account`, `swift`, `instructions`, `hold`, `whatsapp`.

```php
add_filter( 'dgb_bank_details', function ( array $bank, array $booking ) {
	$bank['instructions'] .= ' Transfers from abroad: add the bank charges.';
	return $bank;
}, 10, 2 );
```

### `dgb_stripe_payment_intent_args` (0.4.0)

Filters the parameters of a new Stripe PaymentIntent (amount, currency, metadata, description, receipt_email…).

```php
add_filter( 'dgb_stripe_payment_intent_args', function ( array $args, array $booking, array $due ) {
	$args['statement_descriptor_suffix'] = 'SAFARI';
	return $args;
}, 10, 3 );
```

### `dgb_proof_dir` (0.4.0)

Folder for bank transfer screenshots (default `wp-content/uploads/dgb-private`, protected by `.htaccess` and `web.config`). On nginx, return a path outside the web root.

```php
add_filter( 'dgb_proof_dir', fn() => '/home/site/private/dgb-proofs' );
```

### `dgb_rate_limit` (0.4.0)

Filters the per-IP limits of the public endpoints. Actions and defaults: `quote` 120 per minute, `create` 10 per hour, `pay` 30 and `proof` 10 per 10 minutes.

```php
add_filter( 'dgb_rate_limit', function ( int $limit, string $action, int $window ) {
	return 'create' === $action ? 30 : $limit; // e.g. a hotel desk booking for many guests
}, 10, 3 );
```

### `dgb_stripe_api_base`, `dgb_paypal_api_base` (0.4.0)

Base URLs of the Stripe and PayPal APIs. Only for tests and proxies.

## Actions

### `dgb_booking_created` (0.3.0)

Fires after a booking or quote request was created from the booking widget.

```php
add_action( 'dgb_booking_created', function ( int $booking_id, array $booking ) {
	// $booking is the dgb_bookings row: reference, status, customer fields, totals, attribution.
}, 10, 2 );
```

### `dgb_payment_completed` (0.4.0)

Fires after a payment was recorded: online (Stripe, PayPal) or by staff (Mark as paid, manual payment). Fires once per gateway transaction, however often the gateway repeats its webhook.

```php
add_action( 'dgb_payment_completed', function ( int $booking_id, array $payment ) {
	// $payment: gateway, intent_id, transaction_id, amount, currency, type (full, deposit, balance, manual), status.
}, 10, 2 );
```

### `dgb_payment_failed` (0.4.0)

Fires once per declined attempt reported by a gateway (the booking stays held).

```php
add_action( 'dgb_payment_failed', function ( int $booking_id, array $attempt ) {
	// $attempt['note'] holds the reason, e.g. "Your card was declined."
}, 10, 2 );
```

### `dgb_payment_refunded` (0.4.0)

Fires after a refund was recorded (sent through the gateway, recorded by staff, or reported by a webhook).

```php
add_action( 'dgb_payment_refunded', function ( int $booking_id, array $refund ) {
	// $refund['parent_id'] is the refunded payment.
}, 10, 2 );
```

### `dgb_payment_proof_uploaded` (0.4.0)

Fires after a guest uploaded a bank transfer screenshot. `file_path` is relative to the private folder (`dgb_proof_dir`).

```php
add_action( 'dgb_payment_proof_uploaded', function ( int $booking_id, array $proof ) {}, 10, 2 );
```

### `dgb_booking_status_changed` (0.2.0)

Fires after a booking changed status.

```php
add_action( 'dgb_booking_status_changed', function ( int $booking_id, string $from, string $to, string $reason ) {
	// $reason examples: hold_expired.
}, 10, 4 );
```

### `dgb_expire_holds` (0.2.0)

The scheduled job that releases unpaid bookings (every five minutes). Run it by hand with `do_action( 'dgb_expire_holds' )`.

## Planned

The remaining hooks from section 17 of `CLAUDE.md` (`dgb_email_template`, `dgb_email_recipients`, `dgb_send_sms`, `dgb_schema_product`, `dgb_llms_txt_lines`) arrive with the milestones that introduce emails, schema and llms.txt.

## Options and meta (for reference)

| Name | Type | Contents |
|---|---|---|
| `dgb_settings` | option | All settings, grouped by tab: `general`, `currency`, `payments`, `analytics`, `privacy`, `branding` |
| `dgb_db_version` | option | Schema version applied by the migrations runner (1.3.0 in this release) |
| `dgb_version` | option | Plugin version that last ran its upgrade routine |
| `_dgb_*` | post meta | Booking settings of a tour (see `src/Domain/Product.php`) |
| `{prefix}dgb_*` | tables | See `src/Database/Schema.php` |
