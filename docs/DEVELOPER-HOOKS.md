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

Filters the payment methods offered at checkout. Each method has `id`, `label`, `description` and `status` (the booking status it creates: `confirmed`, `awaiting_transfer` or `pending_payment`). Payment gateways add themselves here.

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

## Actions

### `dgb_booking_created` (0.3.0)

Fires after a booking or quote request was created from the booking widget.

```php
add_action( 'dgb_booking_created', function ( int $booking_id, array $booking ) {
	// $booking is the dgb_bookings row: reference, status, customer fields, totals, attribution.
}, 10, 2 );
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

The remaining hooks from section 17 of `CLAUDE.md` (`dgb_payment_completed`, `dgb_bank_details`, `dgb_email_template`, `dgb_email_recipients` and others) arrive with the milestones that introduce payments and emails.

## Options and meta (for reference)

| Name | Type | Contents |
|---|---|---|
| `dgb_settings` | option | All settings, grouped by tab: `general`, `currency`, `payments`, `analytics`, `privacy`, `branding` |
| `dgb_db_version` | option | Schema version applied by the migrations runner (1.2.0 in this release) |
| `dgb_version` | option | Plugin version that last ran its upgrade routine |
| `_dgb_*` | post meta | Booking settings of a tour (see `src/Domain/Product.php`) |
| `{prefix}dgb_*` | tables | See `src/Database/Schema.php` |
