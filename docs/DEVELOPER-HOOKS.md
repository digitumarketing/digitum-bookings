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

## Actions

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

The remaining hooks from section 17 of `CLAUDE.md` (`dgb_booking_created`, `dgb_payment_completed`, `dgb_email_template` and others) arrive with the milestones that introduce bookings, payments and emails.

## Options and meta (for reference)

| Name | Type | Contents |
|---|---|---|
| `dgb_settings` | option | All settings, grouped by tab: `general`, `currency`, `payments`, `privacy`, `branding` |
| `dgb_db_version` | option | Schema version applied by the migrations runner (1.1.0 in this release) |
| `dgb_version` | option | Plugin version that last ran its upgrade routine |
| `_dgb_*` | post meta | Booking settings of a tour (see `src/Domain/Product.php`) |
| `{prefix}dgb_*` | tables | See `src/Database/Schema.php` |
