# Changelog

## 0.3.0 (M3 Front-end flow)

- Booking widget on every tour that takes bookings: choose option, date and session, guests, pickup, extras, guest details, payment. One screen on desktop; on phones a sticky “From … / Book” bar opens a stepped bottom sheet. The live price comes from the same `QuoteService` the server uses when it saves the booking.
- Placement: automatic on single tour pages (setting), shortcode `[dgb_booking product="123"]`, Gutenberg block “Booking widget” and an Elementor widget (loaded only when Elementor is active). A tour's widget renders once per page.
- Price-on-request tours show no price and end in “Request a quote” (status `quote_requested`).
- Payment methods at checkout: pay on arrival and bank transfer (card and PayPal arrive in 0.4.0). Bank transfer bookings are held for 24 hours and show the bank details on the “booking received” page. The deposit choice only appears when deposits are on; “Full amount” is preselected.
- Pickup: zones where pickup is not offered (e.g. Abu Dhabi) show the meeting point and directions instead; the hotel is only asked for when pickup can happen.
- Confirmation page (private link with a secret key), with the pickup window of the booked session and an “Add to calendar” .ics download.
- REST API `dgb/v1` (public): tour data, month availability, sessions for a date, quote, create booking, create enquiry, .ics download, fresh nonce. Every write checks a nonce, a honeypot, a rate limit (per IP) and, when configured, Cloudflare Turnstile. The server validates everything again.
- Analytics: GA4 ecommerce events in `dataLayer` (`view_item`, `select_item`, `add_to_cart`, `begin_checkout`, `add_payment_info`, `purchase`, `generate_lead`), with SHA-256 email and phone for enhanced conversions (setting). UTM and gclid kept in a first-party cookie (90 days by default) and saved on the booking.
- Settings: terms page, cancellation summary, default phone country code; Payments › pay on arrival and bank transfer switches; new Analytics tab (dataLayer, enhanced conversions, attribution days, Turnstile keys).
- Templates can be overridden from the theme under `digitum-bookings/` (e.g. `digitum-bookings/front/booking-widget.php`).
- Performance: assets load only on pages with a widget; 6 KB gzipped JS (vanilla), widget CSS printed inline (no render-blocking request), space reserved for the calendar (CLS 0).
- Accessibility: keyboard calendar (arrow keys, Home/End), focus moved between steps, errors announced and focused, no axe violations.
- Database schema 1.2.0 (`access_key` on bookings).
- Tests: Playwright browser tests on desktop and phone (with axe), WordPress integration scenario for the whole flow, run in CI.

## 0.2.0 (M2 Pricing and availability)

- `PriceCalculator`: one pure class for every price. Modes per person (child price, free infants), per vehicle (guests checked against seats × vehicles), per group (extra cars added automatically) and on request (no price). Add-ons per booking / person / vehicle / on request; the hotel-pickup add-on is added when the guest picks pickup from an allowed zone; zone surcharges; percent and fixed coupons; VAT inclusive or exclusive; optional deposit by payment method; pay on arrival; compare-at price only during a dated promotion. Amounts are integers in fils/cents internally (three-decimal currencies supported). Unit-test coverage of the pricing engine is 100% and CI enforces it.
- `QuoteService`: loads a tour's settings, option, add-ons, zone, closures and coupon, runs the calculator and applies the `dgb_price_breakdown` filter. The tour editor shows the “From” price guests will see.
- Availability: sessions run on their weekdays and season; a session is bookable unless the date is past, beyond “book up to”, inside the same-day cut-off or minimum notice, or closed by an admin. `dgb_available_sessions` filter. No capacity limits.
- Tour editor › Rules: “Closed dates and price changes” table (whole tour or one session; close, or change prices by ±%).
- Settings › Currency & VAT: weekend days for the weekend uplift (default Saturday and Sunday).
- Digitum Bookings › Pickup zones: add, edit and delete pickup zones (pickup offered or not, surcharge, notes) and meeting points (address, map link, coordinates, arrival instructions).
- Booking references `DGB-YYMMDD-XXXX` are unique under concurrency (database unique key + retry); tested with 320 bookings from 8 parallel processes.
- Holds: card/PayPal bookings are held for 15 minutes, bank transfers for 24 hours (settings). Every five minutes expired holds are cancelled (Action Scheduler when installed, otherwise WP-Cron) with a timeline event and the `dgb_booking_status_changed` action.
- Database schema 1.1.0 (`adjust_percent` and `sort` on calendar overrides), applied automatically on update; tested by upgrading a v0.1.0 site.

## 0.1.0 (M1 Foundation)

- Plugin skeleton with Composer autoloading (and a fallback autoloader for plain checkouts); stops with a notice on PHP below 8.1.
- Database: 13 custom tables created by a versioned migrations runner.
- Settings page with tabs General, Currency & VAT, Payments (optional deposit percentage, bank transfer details, screenshot upload, hold time), Privacy, Branding.
- Tours: use the plugin's own post type (default `dgb_tour`, slug `tour`) or attach to an existing one. On first activation the plugin attaches to any post type that already serves `/tour/` URLs. If an attached post type disappears, the plugin registers it with the same name and slug so URLs keep working.
- Rewrite rules are flushed only after activation or a slug change.
- “Booking settings” box on each tour: Pricing, Options, Sessions, Add-ons, Pickup & location, Rules, Schema & SEO.
- Roles: Booking manager (everything except settings) and Tour guide (manifest and check-in). Administrators get every capability.
- Dashboard with setup checklist and About Digitum panel; white-label menu name.
- Uninstall keeps all data unless “Delete all booking tables and settings” is switched on.
- GitHub Actions: coding standards, unit tests and activation tests on WordPress 6.5 and 7.1; tagged releases build `digitum-bookings.zip`.
