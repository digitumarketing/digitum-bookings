# Changelog

## 0.7.0 (M7 Release)

- **Updates from GitHub**: the plugin reads the latest release of github.com/digitumarketing/digitum-bookings; WordPress shows “update available” under Dashboard › Updates and on the Plugins screen, installs `digitum-bookings.zip` from the release, and automatic updates can be switched on. “View details” shows the release changelog and requirements. Settings › **Updates** shows the installed and latest version, has “Check for updates now”, and takes a read-only token if the repository is ever private (the zip is then fetched through the GitHub API).
- **Arabic**: complete Arabic translation (1093 strings, six plural forms) with PHP, `.mo` and block-editor files; right-to-left fixes (mirrored calendar arrows and arrow keys, option and session labels keep their own direction).
- **Import Desert Bookings demo data** (Tools, administrators): business details, AED and 5% VAT, pickup zones, the Al Aweer meeting point and all 27 tours of section 13 with options, sessions and pickup rules. Safe on the live site and when run twice; bookings stay off until each tour's [CONFIRM] items are checked.
- **Coupons** screen (booking managers) and a coupon field in the payment step of the widget; uses are counted when a booking is saved, and the code is stored on the booking, in the CSV export and in webhooks.
- **Reports**: bookings made, sold, booked value, money collected, conversion, cancellation rate and quote requests for a period, by tour, by source and by coupon.
- No white label: the admin menu is always “Digitum Bookings”, and the “Admin menu name” setting (Settings › Branding) is gone. Digitum branding is used everywhere.
- “Booking by Digitum” (linked to digitum.marketing) is always shown under every booking widget and booking page; the on/off setting is gone, and theme template overrides cannot remove it.
- Admin pages of the plugin show “Digitum Bookings by Digitum Marketing” in the footer; the Plugins screen links the admin guide and Digitum support.

## 0.6.0 (M6 Integrations)

- **Structured data** (JSON-LD) on every tour that takes bookings: `Product` (or `TouristTrip`, set per tour) with an `Offer`, or an `AggregateOffer` with one `Offer` per option, each with a `UnitPriceSpecification` and `referenceQuantity` (one person, one vehicle with its seats, one car with its guest limit) and VAT included or not. Prices come from the same calculator call as the "From" price on the page; tests check they match. Price-on-request tours have no price at all, a "Request a quote" `QuoteAction`, and are marked up as `TouristTrip`; so are tours without any image (Google's Product result needs one, and the site logo is used before giving up). Ratings only through a connected review source (`dgb_schema_rating`). The business (name, phone, email, address) is the seller.
- **Rank Math and Yoast SEO**: the tour node joins their graph (one script, one graph), points to their WebPage and organisation, and replaces any other Product node on the page, such as one made in Rank Math's Schema Generator. Without an SEO plugin the plugin prints its own graph. Settings › **Schema & SEO**: automatic, always standalone, or off; business type.
- **/llms.txt** for AI assistants: business and contact, VAT basis, every tour with its price basis, "From" price or "Price on request", options with durations, sessions with pickup windows and weekdays, pickup policy per area, meeting point, age and licence rules, and the booking terms. Served without a rewrite rule, cached for an hour, refreshed when a tour, a zone or the settings change; can be switched off.
- **REST API** (`dgb/v1`): public `products` list (paged) and richer `products/{id}` with sessions; staff `admin/bookings` list with the bookings-screen filters, create (taken by staff, source "admin", payment link returned), update (guest details, requests, vehicle, internal note, date and session, status) and DELETE, which cancels (bookings are never deleted); `admin/bookings/{id}/checkin` for guides. Works with the REST nonce or application passwords.
- **Traveler importer** (Digitum Bookings › Tools): lists the Traveler theme's tours (`st_tours`) with what it found, then imports the ticked ones. Each tour keeps its ID, slug, title, text and featured image; the Traveler gallery becomes the tour gallery; "Duration | Old Price | New Price" tables become options with durations (old prices are logged, never imported). Tours without a table use Traveler's adult and child prices, or become price on request. Seats come from titles such as "2-Seater". When the plugin owns /tour/ the posts move to its post type in place; a slug that another tour already uses stops that tour with a log line. Options: pricing mode for tables, switch bookings on, remove the price tables from the text (a revision keeps the old text), replace existing options.
- **CSV import and export** of tour booking settings, options and sessions (Tools). Tours are matched by id, then slug; slugs, titles and text never change; unknown tours are added as drafts. Rows are updated by id or added; "replace" makes the file the full list. Accepts prices like "1,599" and weekdays like "Fri;Sat"; spreadsheet formulas are neutralised on export. Each line is reported.
- **Privacy**: WordPress's Export Personal Data and Erase Personal Data tools now include bookings (details, screenshots, emails sent). Erasing works like "Erase guest data": the bookings and their amounts stay. Suggested privacy policy text for Settings › Privacy.
- Tour gallery field (Booking settings › Schema & SEO) with a media picker.
- Tests: integration scenarios for schema vs visible price, Google Rich Results checks (`@adobe/structured-data-validator`), llms.txt, REST permissions and application passwords, privacy export and erase, Traveler import in both modes and CSV round trips; Rank Math and Yoast merge tests on WordPress 6.7+ (CI builds both from their repositories); browser tests for the same.

## 0.5.0 (M5 Admin operations)

- **Bookings** list: status tabs with counts, filters (tour, dates, source, payment method), search by reference, name, email or phone, bulk cancel and bulk erase of guest data, CSV export of the filtered list (spreadsheet formulas neutralised).
- **Booking page**: trip (tour, option, date, session, guests, pickup, extras, reminder time), guest, source and UTM / gclid, payments (Mark as paid, payment link, manual payments, refunds, screenshots), quote form for price-on-request tours, internal notes, email log with Resend, timeline with staff names, erase guest data. Actions: confirm, cancel, mark arrived / no-show / undo, change date or session (the guest is emailed), email the confirmation again.
- **Calendar**: month, week and day views; one tour shows all its sessions with bookings and guests, all tours show sessions with bookings. Drag a booking to another day (or session in the day view) to move it; close or open a whole day or one session. Works without JavaScript for viewing; every booking links to its page.
- **Manifest** per day, tour and session: guests, phone, adults / children / infants, vehicles, pickup zone / hotel / room / window, vehicle or guide assignment, amount to collect. Print view (A4 landscape, header on every page) and CSV.
- **Check-in** screen for guides on a phone: Arrived / No-show / Undo without a page reload (plain forms without JavaScript).
- **Dashboard**: today's sessions (bookings, guests, pickups, unpaid), revenue for 7 and 30 days, open quote requests, bank transfers waiting.
- **Emails** for every event of the spec (booking confirmed with calendar file, bank transfer received with bank details and upload link, screenshot uploaded with the file attached, hold expired, quote request, quote sent with payment link, payment received, booking changed, reminder, cancelled, refund, daily summary of tomorrow), to the guest and to staff. Each can be switched off and edited (subject, HTML, plain text) on Digitum Bookings › Emails, with preview and a test email. HTML with a plain-text part; staff emails reply to the guest. Every email is logged on the booking.
- **Reminders** before the session (24 hours by default, from the pickup time) and the **daily summary** (18:00 by default) run through Action Scheduler, now bundled with the plugin. Reminders move when a booking is moved and are removed when it is cancelled.
- **Webhooks** to Zapier, Make, GoHighLevel and similar: booking created, status changed, changed, payment completed, refunded, as signed JSON (HMAC-SHA256), delivered in the background and retried after 1 minute, 10 minutes and 1 hour.
- `dgb_send_sms` filter to send a text message alongside guest emails through any SMS service.
- Staff REST API (`dgb/v1/admin/…`): calendar, booking, move, status (guides may check in), closures, manifest.
- Settings › Emails: sender, staff recipients, reminder hours, daily summary time, webhook URL and secret.
- Database schema 1.4.0 (vehicle assignment; audience and subject in the notification log).
- Release workflow can be started from GitHub (Actions › Release › Run workflow) as well as by pushing a tag.

## 0.4.0 (M4 Payments)

- Payment gateways behind one `GatewayInterface`; new gateways plug in with the `dgb_gateways` filter. Built in: Stripe, PayPal, bank transfer, pay on arrival. Telr and Network International (N-Genius) are registered as stubs with TODOs and are never offered.
- **Stripe** (cards, Apple Pay, Google Pay through the Payment Element). The booking is held as `pending_payment`; the guest pays on the private booking page. Card details go from the browser straight to Stripe (SAQ A). 3-D Secure is handled by Stripe.js; when the guest comes back from a redirect, or the browser reports success before the webhook, the site asks Stripe and confirms the booking. Webhooks `payment_intent.succeeded`, `payment_intent.payment_failed` and `charge.refunded` with signature check (5-minute tolerance). Test and live keys.
- **PayPal** Checkout (Orders v2): the buttons create the order through the site, the site captures it after approval. A declined funding source lets the guest choose another one. PayPal does not accept AED, SAR, OMR, QAR, BHD or KWD, so those amounts are converted at a rate set in Settings › Payments (default 3.6725 for AED to USD) and the guest sees both amounts. Webhooks capture completed, denied and refunded, verified through PayPal's API. Sandbox and live.
- Payments are idempotent: each gateway transaction is stored once (unique key), so repeated webhooks, double clicks and a webhook arriving after the browser confirmation never record money twice. Declined attempts are kept for the timeline.
- Deposits: the pay box charges the deposit when the guest chose one; later payments (payment link) charge the balance.
- A card or PayPal payment that arrives after the 15-minute hold expired reinstates the booking. Before releasing a card booking, the site checks with Stripe in case the webhook was missed.
- **Bank transfer screenshot upload** on the "booking received" page: JPG, PNG or PDF up to 5 MB, checked by file content, stored outside the Media Library under random names in a folder that refuses web access. Works without JavaScript too.
- **Payments screen** (Digitum Bookings › Payments) and a "Waiting for bank transfer" panel on the dashboard: screenshot links (staff only), **Mark as paid**, record cash / bank / card-terminal payments, refunds (full or partial; through Stripe and PayPal, recorded only for manual payments), payment link for the balance, timeline. The full booking screens come in M5.
- Booking amount paid, balance and payment status (`unpaid`, `deposit_paid`, `paid`, `partially_refunded`, `refunded`) are recalculated from the payment rows after every change.
- Settings › Payments: Stripe and PayPal sections, webhook URLs to copy, secret keys never shown again after saving.
- Uninstall (when "delete data" is on) also removes the payment screenshots.
- Database schema 1.3.0 (payment transaction ids, refund parent, notes; screenshot file names).
- Tests: integration scenario with fake Stripe and PayPal APIs (success, decline, 3-D Secure, late payment, refunds, webhooks, idempotency, uploads, mark as paid); Stripe requests checked against Stripe's official mock (stripe-mock) in CI; browser tests for card, 3-D Secure, deposit, PayPal, upload (with and without JavaScript), mark as paid and refunds; real-sandbox browser tests that run when sandbox keys are supplied.

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
