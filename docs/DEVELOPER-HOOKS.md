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

### `dgb_email_template` (0.5.0)

Filters an email just before it is sent: `subject`, `html` (the full document) and `text`.

```php
add_filter( 'dgb_email_template', function ( array $email, string $event, string $audience, ?array $booking ) {
	if ( 'booking_confirmed' === $event && 'guest' === $audience ) {
		$email['subject'] = '✅ ' . $email['subject'];
	}
	return $email;
}, 10, 4 );
```

Events: `booking_confirmed`, `transfer_received`, `proof_uploaded`, `hold_expired`, `quote_requested`, `quote_sent`, `payment_received`, `booking_changed`, `reminder`, `booking_cancelled`, `refund_issued`, `daily_summary`. Audiences: `guest`, `admin`. The layout can also be overridden from the theme at `digitum-bookings/emails/layout.php`.

### `dgb_email_recipients` (0.5.0)

Filters who receives an email.

```php
add_filter( 'dgb_email_recipients', function ( array $to, string $event, string $audience, ?array $booking ) {
	if ( 'admin' === $audience && 'proof_uploaded' === $event ) {
		$to[] = 'accounts@example.com';
	}
	return $to;
}, 10, 4 );
```

### `dgb_send_sms` (0.5.0)

Called for every guest email with the guest's phone. The plugin sends no SMS itself; return `true` when you sent one (it is logged on the booking).

```php
add_filter( 'dgb_send_sms', function ( bool $sent, string $phone, string $message, string $event, array $booking ) {
	if ( in_array( $event, array( 'booking_confirmed', 'reminder' ), true ) ) {
		return my_sms_gateway_send( $phone, $message ); // your code
	}
	return $sent;
}, 10, 5 );
```

### `dgb_schema_product` (0.6.0)

Filters a tour's structured data node (`Product` or `TouristTrip`) before it is printed or merged into Rank Math or Yoast.

```php
add_filter( 'dgb_schema_product', function ( array $node, int $post_id ) {
	$node['sku'] = 'TOUR-' . $post_id;
	return $node;
}, 10, 2 );
```

### `dgb_schema_rating` (0.6.0)

The connection point for a review source. Nothing about ratings is output unless this returns data. Return `null` or an array: `value` (average), `count`, optional `best` (5) and `worst` (1), and optional `reviews` (each: `author`, `rating`, `body`, `date` as Y-m-d).

```php
add_filter( 'dgb_schema_rating', function ( $rating, int $post_id ) {
	$data = my_reviews_for( $post_id ); // From your review platform.
	return $data ? array( 'value' => $data['average'], 'count' => $data['total'] ) : null;
}, 10, 2 );
```

### `dgb_llms_txt_lines` (0.6.0)

Filters the lines (Markdown) of `/llms.txt`. The file is cached for an hour; call `\Digitum\Bookings\Services\LlmsTxt::flush()` after changing what the filter adds.

```php
add_filter( 'dgb_llms_txt_lines', function ( array $lines ) {
	$lines[] = '';
	$lines[] = '## Opening hours';
	$lines[] = '- Every day 7:00 to 22:00';
	return $lines;
} );
```

### `dgb_update_repository` (0.7.0)

The GitHub repository (owner/name) updates come from. Default `digitumarketing/digitum-bookings`. The latest release must carry a `digitum-bookings.zip` asset; its `readme.txt` (at the release tag) gives "Tested up to", "Requires PHP" and the changelog shown in "View details".

```php
add_filter( 'dgb_update_repository', fn() => 'my-agency/digitum-bookings-fork' );
```

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

### `dgb_booking_changed` (0.5.0)

Fires after staff moved a booking to another date or session.

```php
add_action( 'dgb_booking_changed', function ( int $booking_id, string $changes ) {}, 10, 2 );
```

### `dgb_quote_sent` (0.5.0)

Fires after staff sent a price for a quote request (the guest gets the payment link).

```php
add_action( 'dgb_quote_sent', function ( int $booking_id, string $note ) {}, 10, 2 );
```

### `dgb_booking_erased` (0.5.0)

Fires after a booking's guest data was erased.

```php
add_action( 'dgb_booking_erased', function ( int $booking_id ) {} );
```

### Scheduled actions (0.5.0)

Run by Action Scheduler in the group `digitum-bookings`: `dgb_send_reminder` (argument: booking id), `dgb_daily_summary`, `dgb_deliver_webhook`, `dgb_expire_holds`. See them under Tools › Scheduled Actions.

### `dgb_booking_status_changed` (0.2.0)

Fires after a booking changed status.

```php
add_action( 'dgb_booking_status_changed', function ( int $booking_id, string $from, string $to, string $reason ) {
	// $reason examples: hold_expired.
}, 10, 4 );
```

### `dgb_expire_holds` (0.2.0)

The scheduled job that releases unpaid bookings (every five minutes). Run it by hand with `do_action( 'dgb_expire_holds' )`.

## Outgoing webhooks (0.5.0)

Set a URL and a signing secret in Settings › Emails. Events: `booking.created`, `booking.status_changed`, `booking.changed`, `payment.completed`, `payment.refunded`. Each is a `POST` with a JSON body:

```json
{
  "event": "booking.status_changed",
  "sent_at": "2026-10-21T16:15:25+00:00",
  "data": { "from": "pending_payment", "to": "confirmed", "reason": "payment_received" },
  "booking": { "id": 12, "reference": "DGB-261021-AB12", "status": "confirmed", "total": 899, "discount": 0, "coupon": "", "customer": { "name": "…", "email": "…", "phone": "…" }, "tour": { "name": "…", "date": "2026-10-24", "session": "Evening" }, "pickup": { "zone": "Dubai", "hotel": "…", "window": "2:15 pm – 2:45 pm" }, "attribution": { "source": "ads", "gclid": "…" }, "booking_link": "…", "admin_link": "…" }
}
```

Headers: `X-DGB-Event`, `X-DGB-Delivery` (unique id), `X-DGB-Timestamp`, `X-DGB-Signature: sha256=<hex>`. Verify:

```php
$expected = 'sha256=' . hash_hmac( 'sha256', $_SERVER['HTTP_X_DGB_TIMESTAMP'] . '.' . file_get_contents( 'php://input' ), $secret );
$valid    = hash_equals( $expected, $_SERVER['HTTP_X_DGB_SIGNATURE'] ) && abs( time() - (int) $_SERVER['HTTP_X_DGB_TIMESTAMP'] ) < 300;
```

Answer with a 2xx status. Failed deliveries are retried after 1 minute, 10 minutes and 1 hour; every attempt is in the booking's email log.

## Staff REST API (0.5.0, extended in 0.6.0)

Namespace `dgb/v1`. Logged-in staff send the REST nonce (`X-WP-Nonce`); external tools (Zapier, Make, a phone app) use an application password from Users › Profile with HTTP Basic auth over HTTPS.

| Route | Method | Who | |
|---|---|---|---|
| `admin/bookings?status=&product=&date_from=&date_to=&source=&payment_method=&search=&orderby=&order=&page=&per_page=` | GET | managers | bookings (webhook shape); `X-WP-Total` and `X-WP-TotalPages` headers |
| `admin/bookings` | POST `product` + the public booking fields (`variant_id`, `date`, `session_id`, `adults`, `children`, `infants`, `units`, `pickup`, `zone_id`, `hotel`, `room`, `addons`, `payment_method`, `name`, `email`, `phone_code`, `phone`, …) | managers | booking or enquiry taken by staff (source `admin`, no terms checkbox); returns `booking_page` and `payment_link`; 201 |
| `admin/bookings/{id}` | GET | managers | one booking |
| `admin/bookings/{id}` | PATCH `customer` {`name`, `email`, `phone`, `nationality`}, `special_requests`, `vehicle`, `note`, `date` + `session`, `action` | managers | change a booking |
| `admin/bookings/{id}` | DELETE | managers | cancels the booking (bookings are never deleted; use the privacy eraser for guest data) |
| `admin/bookings/{id}/checkin` | POST `arrived` (true / false) | guides and managers | arrived or no-show |
| `admin/bookings/{id}/reschedule` | POST `date`, `session` | managers | move a booking (the guest is emailed) |
| `admin/bookings/{id}/status` | POST `action` | guides: `check_in`, `no_show`, `undo`; managers: also `confirm`, `cancel` | |
| `admin/calendar?from=&to=&product=` | GET | managers | sessions and bookings per day |
| `admin/closures` | POST `product`, `date`, `session`, `closed` | managers | close or open a date or session |
| `admin/manifest?date=&product=&session=` | GET | guides and managers | |

Public routes (no login): `products` (paged, `page`, `per_page`), `products/{id}`, `availability?product=&month=`, `availability/sessions?product=&date=`, `quote`, `bookings`, `enquiries` (these three need the `X-DGB-Nonce` the widget prints, and are rate limited), `bookings/{reference}/proof` (payment screenshot, authorised by the booking's private key).

Errors use WordPress's REST format: `{ "code": "dgb_invalid", "message": "…", "data": { "status": 400, "fields": { "email": "…" } } }`. A status change that is not possible (for example cancelling a checked-in booking) answers 409.

## Options and meta (for reference)

| Name | Type | Contents |
|---|---|---|
| `dgb_settings` | option | All settings, grouped by tab: `general`, `currency`, `payments`, `emails`, `analytics`, `seo`, `privacy`, `branding`, `updates` |
| `dgb_emails` | option | Email templates edited on the Emails screen |
| `dgb_db_version` | option | Schema version applied by the migrations runner (1.5.0 in this release) |
| `dgb_version` | option | Plugin version that last ran its upgrade routine |
| `dgb_traveler_import_log` | option | Result of the last Traveler import |
| `dgb_demo_report` | option | Result of the last demo data import |
| `dgb_update_release` | site transient | Latest GitHub release (6 hours) |
| `_dgb_demo` | post meta | Set on tours created or set up by the demo data import |
| `_dgb_*` | post meta | Booking settings of a tour (see `src/Domain/Product.php`); `_dgb_gallery` holds image IDs |
| `_dgb_imported_from` | post meta | `traveler` on tours brought in by the importer |
| `{prefix}dgb_*` | tables | See `src/Database/Schema.php` |
