# Build prompt: Digitum Bookings (WordPress tour booking plugin)

Every session reads this file. It is the source of truth for scope. Decisions made during the design review (October 2026) are already folded in; section 19 lists what changed from the first draft.

Approved layout (clickable): https://claude.ai/artifact/2bkeTdhGqaG8qRdftzd49h

---

## 1. Role and goal

You are a senior WordPress plugin engineer. Build **Digitum Bookings**, a production-grade tour and activity booking plugin. It is owned and branded by Digitum Marketing Ltd (digitum.marketing) and must install on **any** WordPress site, with or without Elementor.

The first install is **desertbookings.com**: a Dubai desert safari, dune buggy, quad bike and KTM dirt bike operator. Every rule in section 13 must work out of the box for that site, but nothing in the code may be hard-coded to it. Everything is configured through settings and product data.

Work in milestones (section 15). After each milestone, stop, list what was built, show the test results, and wait for approval before continuing.

## 2. Non-negotiables

| # | Rule |
|---|---|
| 1 | WordPress 6.5+, PHP 8.1+, MySQL 5.7+ / MariaDB 10.4+. Multisite compatible. |
| 2 | WordPress Coding Standards (PHPCS with WPCS). Namespaced PHP (`Digitum\Bookings`), PSR-4 autoload via Composer, prefix `dgb_` for options, hooks, tables, meta and capabilities. Text domain `digitum-bookings`. |
| 3 | No theme dependency. Works with any theme, Gutenberg and Elementor. Elementor widgets load only when Elementor is active. |
| 4 | No URL changes on the host site. The plugin **attaches to an existing post type** (e.g. Traveler's `st_tours` with rewrite slug `tour`) or registers its own CPT, whose key and rewrite slug are set in settings. Default: CPT `dgb_tour`, slug `tour`. If an attached post type disappears (theme removed), the plugin can register that same key and slug so posts and URLs survive. Never flush rewrite rules except on activation or a slug change. |
| 5 | Security: nonces on every form and AJAX/REST call, capability checks on every admin action, `$wpdb->prepare` for every query, sanitize on input, escape on output, rate limiting on public endpoints, a honeypot plus optional Cloudflare Turnstile on booking and enquiry forms. Uploaded payment screenshots are stored outside the Media Library in a protected folder and served only to users with the right capability. |
| 6 | Performance: front-end assets load **only** on pages that render a booking widget. Front-end JS under 50 KB gzipped, vanilla JS (no jQuery), no render-blocking CSS beyond critical inline styles, zero layout shift (reserve widget height). Booking pages keep LCP < 2.5 s, CLS < 0.1, INP < 200 ms. |
| 7 | Accessibility: WCAG 2.2 AA. Every step keyboard operable, focus managed between steps, errors announced with `aria-live`, AA contrast, no information carried by colour alone. |
| 8 | Internationalisation: every string translatable. RTL (Arabic) tested. Currency, date and number formats follow site settings. |
| 9 | Privacy: register WordPress personal-data exporters and erasers. **No automatic deletion.** Guest data stays until an admin erases it (per booking, or in bulk from the bookings list). Erasing removes name, email, phone and payment screenshots; the booking and amounts stay for reports. Marketing consent checkbox is separate from terms acceptance. |
| 10 | Clean uninstall: a setting decides whether uninstall removes tables and options. Default keeps data. |
| 11 | All links the plugin outputs are root-relative or built with `home_url()`. Nothing is hard-coded to a domain. |

## 3. Data model

Custom tables for transactional data; post meta only for product configuration. Create tables with `dbDelta`, versioned by a migrations runner.

| Entity | Storage | Key fields |
|---|---|---|
| Product (tour or activity) | CPT, or an attached existing post type | title, content, gallery; meta: `pricing_mode`, `schema_type`, `seats_per_unit`, `max_pax_per_unit`, `vat_mode`, `deposit_mode` (site / none / custom), `deposit_percent`, `meeting_point_id`, `pickup_policy` (included / add-on / not offered), `pickup_default`, `min_age`, `licence_required`, `cutoff_hours`, `min_notice_hours`, `max_days_ahead`, inclusions, exclusions, requirements, `enquiry_only` |
| Variant / option | `dgb_variants` | product_id, label, duration_minutes, price, child_price, compare_at_price + promo_start/promo_end (13.4), active, sort |
| Session template | `dgb_sessions` | product_id, name, type (morning, evening, overnight, fixed), start_time, end_time, pickup_window_start, pickup_window_end, days_of_week, season range, active |
| Add-on | `dgb_addons` | product scope, label, price, `price_type` (per booking, per person, per vehicle, on request), required, condition (e.g. `pickup`) |
| Pickup zone | `dgb_zones` | name, allowed (yes/no), surcharge, notes; excluded zones show the meeting point instead of a price |
| Meeting point | `dgb_locations` | name, address, lat, lng, map URL, arrival instructions |
| Booking | `dgb_bookings` | reference (DGB-YYMMDD-XXXX), status, customer fields, source (organic, ads, admin), UTM fields, gclid, totals, deposit, amount paid, balance due, currency, payment method, payment status, hold_expires_at, internal notes, created_by, erased_at |
| Booking item | `dgb_booking_items` | booking_id, product_id, variant_id, session_id, date, start_time, adults, children, units, unit_price, line_total, pickup (type, zone, address, room, time window), add-ons |
| Booking event | `dgb_booking_events` | booking_id, type, message, user_id, created_at (powers the timeline) |
| Payment | `dgb_payments` | booking_id, gateway, intent_id, amount, type (deposit, full, balance, refund), status, raw payload |
| Payment proof | `dgb_payment_proofs` | booking_id, file path (protected), mime, size, uploaded_at, reviewed_by, reviewed_at |
| Coupon | `dgb_coupons` | code, type (percent, fixed), scope, limits, dates |
| Closure / override | `dgb_calendar_overrides` | product, session (optional), date range, rule (`closed`, or `adjust` with `adjust_percent`), note |
| Notification log | `dgb_notifications` | booking_id, channel (email, webhook), template, recipient, status, error, timestamp |

Booking statuses: `pending_payment`, `awaiting_transfer`, `quote_requested`, `confirmed`, `checked_in`, `no_show`, `cancelled`.

There is **no fleet / resource capacity**. Sessions take any number of bookings until an admin closes the date or session (section 5).

## 4. Pricing engine

One service class (`PriceCalculator`) returns a full line-item breakdown, unit-tested for every mode.

| Mode | Total | Example |
|---|---|---|
| `per_person` | variant price × adults + child price × children (no child price = adult price; infants free) | Evening desert safari |
| `per_vehicle` | variant price × units; all guests (infants too) must fit in seats_per_unit × units | Polaris RZR 2-seater, 30 min |
| `per_group` | price per car up to `max_pax_per_unit`; extra cars added automatically | Private safari, up to 7 per car |
| `on_request` | no price; flow becomes an enquiry (6.4) | New Year safari, Ramadan iftar, corporate, photoshoot, buggy safari combo |

Also:

1. Duration ladders as variants (30 min, 1 h, 2 h, 3 h).
2. Child pricing and age bands per product (infant free, child, adult).
3. Date price changes: a row in the tour's “Closed dates and price changes” table with rule `adjust` changes prices by ±% for a date range (whole tour or one session). A weekend uplift % applies on the weekend days set in Settings › Currency & VAT (default Saturday and Sunday).
4. Add-ons with conditions (e.g. "Hotel pickup, AED 300 per booking" only when pickup is chosen and the zone is allowed).
5. **Deposits are optional.** A global switch plus a percentage set by the site owner; the switch also chooses which payment methods allow a deposit. Each product can use the site setting, turn deposits off, or set its own percentage. When deposits are off the guest never sees the choice. "Full amount" is always preselected.
6. VAT inclusive or exclusive, rate setting (UAE default 5%), shown in the breakdown.
7. Coupons.
8. "From AED X" = lowest active variant, never 0. No price → "Price on request".

## 5. Availability

1. No capacity limits. A session is bookable unless an admin closed it (whole date or a single session, per product) in the calendar.
2. Cut-off per product (e.g. same-day bookings close 3 h before the session), minimum notice, maximum days ahead.
3. Unpaid holds: card/PayPal bookings stay `pending_payment` for 15 minutes (setting); bank transfer bookings stay `awaiting_transfer` for 24 hours (setting). Expired holds are cancelled by Action Scheduler and both sides are emailed.
4. Pickup time comes from the **session**, not the product. Confirmation shows the window of the booked session.
5. Booking references are generated atomically (no duplicates under concurrency).

## 6. Front-end booking flow

### 6.1 Placement

1. Shortcode `[dgb_booking product="123"]`, a Gutenberg block and an Elementor widget, all rendering the same component.
2. Auto-insert option on single product templates.
3. Mobile sticky bar with price-from and Book; it opens the widget in a bottom sheet.

### 6.2 Steps (one screen on desktop, stepped on mobile)

| Step | Content |
|---|---|
| 1. Choose | variant, date (calendar with closed and past states), session with its start time |
| 2. Guests | adults, children, units; live validation against seats; live total |
| 3. Pickup | "Meet us at the meeting point" or "Hotel pickup"; zone, hotel/address, room; disallowed zones show the meeting point and directions; pickup window of the chosen session |
| 4. Extras | optional add-ons |
| 5. Details | name, email (confirmation is sent here), phone with country code, nationality (optional), special requests, marketing consent (optional), terms acceptance |
| 6. Pay | payment method (card via Stripe, PayPal, bank transfer, pay on arrival), then amount (full or deposit, only if deposits are on), breakdown, cancellation summary |
| 7. Result | card/PayPal/arrival → confirmation page; bank transfer → "booking received" page (6.3) |

### 6.3 Bank transfer flow

1. Guest picks bank transfer and books. Status `awaiting_transfer`, held 24 h.
2. "Booking received" page shows the amount, the bank details from settings (copy buttons), the reference to use, and an **upload box** for the transfer screenshot (JPG/PNG/PDF, max 5 MB, optional, no account). A small link offers WhatsApp as another way to send it (number from settings; hidden when empty).
3. The guest email repeats the bank details and contains a private upload link, so the screenshot can be sent later.
4. On upload the admin is emailed the file and a "Mark as paid" link. Dashboard and booking list show "screenshot uploaded" / "no screenshot yet".
5. Mark as paid → `confirmed` → confirmation emails. Not paid within the hold → cancelled, emails to both sides.

### 6.4 Enquiry mode (`on_request` products)

Steps 1–5, no price, no payment, button "Request a quote" → status `quote_requested`, emails to guest and admin. Staff send a quote from admin; the guest gets a payment link by email; paying makes it `confirmed`.

### 6.5 Analytics

Push GA4 ecommerce events to `dataLayer`: `view_item`, `select_item`, `add_to_cart`, `begin_checkout`, `add_payment_info`, `purchase` (transaction_id = reference; for bank transfer it fires on the "booking received" page with `payment_type: bank_transfer`, and GTM decides whether to count it), and `generate_lead` for enquiries. No tags fired directly. UTM + gclid in a first-party cookie (90 days) stored on the booking. Hashed email and phone on purchase for enhanced conversions.

## 7. Payments

1. `GatewayInterface` so new gateways plug in without core changes.
2. **Stripe** (Payment Element: cards, Apple Pay, Google Pay). Webhooks `payment_intent.succeeded`, `payment_intent.payment_failed`, `charge.refunded`; signature verified; idempotent handlers.
3. **PayPal** (PayPal Checkout / Orders v2). Webhooks for capture completed, denied and refunded; signature verified; idempotent.
4. **Bank transfer** (manual, 6.3). Settings: account name, bank, IBAN, account number, SWIFT, guest instructions, WhatsApp number (optional), hold hours, screenshot upload on/off.
5. **Pay on arrival** (cash or card at the meeting point).
6. Deposit applies to Stripe, PayPal and bank transfer as allowed in settings (4.5).
7. Stub adapters with TODOs for **Telr** and **Network International (N-Genius)**.
8. Refunds from admin, full or partial, through Stripe/PayPal APIs; manual refunds recorded for bank transfer and cash. All logged.
9. "Record manual payment" (cash or bank transfer) on the booking page.
10. Never store card data. PCI scope stays SAQ A.

## 8. Notifications (email only)

1. Every event has a "to guest" and "to admin" toggle and editable HTML + plain-text templates with merge tags (`{reference} {guest_name} {product} {date} {session} {pickup_window} {meeting_point} {total} {balance_due} {payment_link} {bank_details} {upload_link}`).

| Event | Guest | Admin |
|---|---|---|
| New booking confirmed (with .ics + receipt) | ✓ | ✓ |
| Bank transfer booking received (bank details + upload link) | ✓ | ✓ |
| Payment screenshot uploaded (file + Mark as paid link) | — | ✓ |
| Payment not completed / hold expired | ✓ | ✓ |
| Quote request received | ✓ | ✓ |
| Quote sent (payment link) | ✓ | — |
| Payment received | ✓ | ✓ |
| Booking changed | ✓ | ✓ |
| Reminder 24 h before session (Action Scheduler) | ✓ | — |
| Booking cancelled | ✓ | ✓ |
| Refund issued | ✓ | ✓ |
| Tomorrow's bookings summary (daily) | — | ✓ |

2. Settings: from name, from email, admin recipients (comma-separated), "Send test email". Admin emails use the guest as Reply-To. Sent through `wp_mail()`; the docs recommend an SMTP plugin.
3. Booking page shows an email log with Resend.
4. Webhook out on booking events (JSON POST, HMAC-signed) for Zapier, Make or GoHighLevel. `dgb_send_sms` filter for third-party SMS.
5. No WhatsApp messaging channel.

## 9. Admin

| Screen | Requirements |
|---|---|
| Dashboard | today's sessions (bookings, guests, pickups, unpaid), revenue 7 and 30 days, pending quotes, awaiting bank transfer (with screenshot status and Mark as paid) |
| Calendar | day, week, month by product; bookings and guests per session; close a date or session; drag to reschedule; click to open |
| Bookings | list with status tabs, filters (status, product, date, source, payment method), search, bulk actions (incl. erase guest data), CSV export |
| Booking detail | items, guest, pickup, payments (payment link, manual payment, refund), screenshots, internal notes, actions, attribution, email log, timeline, erase guest data |
| Manifest | per session: guests, pax, pickup zone/hotel/window, vehicle assignment, phone, paid/unpaid; print view and CSV |
| Check-in | mobile screen for guides: arrived / no-show |
| Products | tabbed "Booking settings" meta box: Pricing, Options, Sessions, Add-ons, Pickup & location, Rules, Schema & SEO |
| Pickup zones | zones and meeting points |
| Coupons, Reports | revenue by product and source, conversion by source, cancellation rate |
| Settings | General, Currency & VAT, Payments (gateways, deposit, bank transfer), Emails, Analytics, Schema & SEO, Privacy, Branding, Updates, Tools |

Roles: `dgb_manager` (everything except settings), `dgb_guide` (manifest and check-in only). Administrators get all capabilities.

## 10. Schema and SEO (single source of truth)

1. JSON-LD per product: `Product` or `TouristTrip` with `Offer` / `AggregateOffer`, `PriceSpecification` with `referenceQuantity` for per person / per vehicle.
2. `on_request`: no price in schema, "Price on request", enquiry `potentialAction`.
3. `AggregateRating`/`Review` only with a connected review source.
4. Merge into Rank Math and Yoast graphs (one graph, no duplicate Product nodes); standalone toggle otherwise.
5. `llms.txt` at `/llms.txt` listing products, price basis, from-price or on request, durations, sessions with pickup windows, pickup policy, age rules, meeting point, NAP.
6. Visible price and schema price come from the same `PriceCalculator` call; a test asserts they match.

## 11. REST API

Namespace `dgb/v1`. Public: products, availability, quote, create booking, create enquiry, upload payment proof (signed token). Authenticated: bookings CRUD, manifest, check-in. Nonces for logged-in requests, application passwords for external tools.

## 12. Branding, distribution and updates

1. Admin menu "Digitum Bookings" with the Digitum mark and an "About Digitum" panel linking to digitum.marketing.
2. Front-end credit "Booking by Digitum", off by default.
3. White-label setting to rename the admin menu.
4. **Releases live on GitHub** (`digitumarketing/digitum-bookings`). Pushing a tag `vX.Y.Z` runs a GitHub Action that builds `digitum-bookings.zip` (no dev files) and attaches it to the release. Sites download that zip.
5. An update checker reads the latest GitHub release so WordPress shows "update available" and installs it. For a private repository each site stores a read-only token in Settings › Updates.

## 13. Seed configuration for desertbookings.com

One-click "Import Desert Bookings demo data" tool (admin only). Prices in AED. [CONFIRM] lines stay editable.

### 13.1 Global settings

| Setting | Value |
|---|---|
| Business name | Desert Bookings |
| Phone | +971 55 526 0561 (also the WhatsApp number for payment screenshots) |
| Email | info@desertbookings.com |
| Currency | AED |
| VAT | 5%, prices inclusive [CONFIRM] |
| Deposit | off by default; client turns it on and sets the % |
| Payments | Stripe, PayPal, bank transfer (details entered by client), pay on arrival |
| Pickup zones | Dubai allowed, Sharjah allowed, Abu Dhabi not offered (meeting point instead) |
| Pickup on safaris | Included |
| Pickup on buggy, quad, KTM and stand-alone activities | Add-on, AED 300 per booking |
| Meeting point | Al Aweer desert (pin and arrival instructions [CONFIRM]) |
| Ride area | Lahbab desert |
| Camp | Al Aweer |
| Pickup time | from the session, not the product; session times [CONFIRM] |

### 13.2 Products

| Product | Existing URL path | Pricing mode | Variants (price) | Sessions |
|---|---|---|---|---|
| Evening Desert Safari with BBQ Dinner | /tour/evening-desert-safari-dubai/ | per_person | Standard from 99 [CONFIRM] | Evening |
| Evening Desert Safari + ATV | /tour/evening-desert-safari-dubai-atv/ | per_person | [CONFIRM] | Evening |
| VIP Evening Desert Safari + ATV | /tour/vip-evening-desert-safari-dubai-atv-bike/ | per_person | [CONFIRM] | Evening |
| VIP Desert Safari | /tour/vip-desert-safari/ | per_person | from 160 [CONFIRM] | Evening |
| Overnight Desert Safari | /tour/overnight-desert-safari/ | per_person | from 250 [CONFIRM] | Overnight |
| Morning Desert Safari | /tour/morning-desert-safari/ | per_group (max 7 per car) | from 650 per car [CONFIRM] | Morning |
| Private Desert Safari with BBQ Dinner | /tour/private-desert-safari-tour/ | per_group (max 7 per car) | from 800 per car [CONFIRM]; add-on "Private desert setup": on request | Evening |
| 1-Seater Polaris RZR 1000 | /tour/1-seater-polaris-buggy-rzr-1000-cc/ | per_vehicle (1 seat) | 30 min 599, 1 h 799, 2 h 999 | Morning, Evening |
| 2-Seater Polaris RZR 1000 | /tour/2-seater-polaris-rzr-1000-cc-buggy/ | per_vehicle (2 seats) | 30 min 699, 1 h 899, 2 h 1,299 | Morning, Evening |
| 4-Seater Polaris RZR 1000 | /tour/4-seater-polaris-rzr-1000-cc/ | per_vehicle (4 seats) | 30 min 799, 1 h 999, 2 h 1,399 | Morning, Evening |
| 2-Seater Can-Am Maverick X3 | /tour/2-seater-can-am-maverick-buggy/ | per_vehicle (2 seats) | 30 min 1,099, 1 h 1,399, 2 h 2,399 | Morning, Evening |
| 4-Seater Can-Am Maverick X3 | /tour/4-seater-can-am-maverick-buggy/ | per_vehicle (4 seats) | 30 min 1,499, 1 h 1,899, 2 h 2,699 | Morning, Evening |
| Yamaha Raptor 700 | /tour/yamaha-raptor-700-cc/ | per_vehicle | 1 h 729, 2 h 1,249, 3 h 1,899 [CONFIRM first tier is 1 h] | Morning, Evening |
| Polaris 570 quad | /tour/desert-polaris-quad-bike-570-cc/ | per_vehicle | 1 h 699, 2 h 1,149, 3 h 1,699 | Morning, Evening |
| Yamaha 400 quad | /tour/desert-yamaha-quad-bike-400-cc/ | per_vehicle | 30 min 249, 1 h 349, 2 h 549 | Morning, Evening |
| KTM dirt bike | /tour/dirt-bike-ktm-rental/ | per_vehicle | 1 h 499, 2 h 699, 3 h 849 | Morning, Evening |
| Dune Bashing | /tour/dune-bashing-dubai/ | per_group [CONFIRM] | [CONFIRM] | Morning, Evening |
| Camel Ride | /tour/camel-ride-dubai/ | per_person [CONFIRM] | [CONFIRM] | Morning, Evening |
| Sandboarding | /tour/sandboarding-dubai/ | per_person [CONFIRM] | [CONFIRM] | Morning, Evening |
| Dubai City Tour | /tour/dubai-city-tour/ | per_person | from 250 [CONFIRM] | Fixed slot [CONFIRM] |
| Abu Dhabi City Tour | /tour/abu-dhabi-city-tour/ | per_person | from 350 [CONFIRM] | Fixed slot [CONFIRM] |
| Hatta Wadi Tour | /tour/hatta-wadi-tour/ | per_person | from 250 [CONFIRM] | Fixed slot [CONFIRM] |
| New Year Desert Safari | /tour/new-year-desert-safari-dubai/ | on_request | none | 31 Dec |
| Ramadan Iftar Desert Safari | /tour/ramadan-iftar-desert-safari/ | on_request | none | Ramadan dates |
| Desert Photoshoot | /tour/desert-photoshoot-dubai/ | on_request | none | Morning, Evening |
| Dune Buggy Desert Safari combo | /tour/dune-buggy-desert-safari/ | on_request | none | Evening |
| Corporate and group events | (page, enquiry form only) | on_request | none | By arrangement |

### 13.3 Rules that must hold

1. Buggy, quad, KTM, dune bashing, camel ride and sandboarding default to "meet at Al Aweer"; choosing pickup adds AED 300.
2. Safaris include pickup within Dubai and Sharjah; Abu Dhabi shows "Pickup not available from Abu Dhabi" with meeting point directions.
3. Confirmation and reminder show the booked session's pickup window, or the meeting point time.
4. Price-on-request products never show a price, never output a schema price, always end in "Request a quote".
5. Guests on per-vehicle products cannot exceed seats per unit × units.

### 13.4 Do not reproduce

1. Permanent "old price" strike-throughs. `compare_at_price` only shows during a dated promotion.
2. Countdown timers, fake scarcity, "X people are viewing".
3. Ratings or review counts not backed by a connected review source.

## 14. Importer

1. **Traveler (st_tours) importer**: keep post ID and slug, copy title, content, featured image and gallery, parse "Duration | Old Price | New Price" tables into variants, log what fails.
2. CSV import and export for products, variants and sessions.
3. Never change a post slug.

## 15. Milestones and acceptance

| Milestone | Scope | Accepted when |
|---|---|---|
| M1 Foundation | skeleton, autoload, migrations, settings framework, attach/own CPT, booking settings meta box, roles, GitHub CI + release workflow | Activates on a clean site and on a site with an existing `tour` CPT with no URL change; PHPCS clean; tagging `v0.1.0` produces a release zip |
| M2 Pricing and availability | PriceCalculator, variants, sessions, add-ons, zones, deposits, closures, holds | 100% unit coverage on PriceCalculator; reference generation safe under concurrency |
| M3 Front-end flow | block, shortcode, Elementor widget, steps, enquiry mode, sticky mobile bar, dataLayer | Playwright e2e mobile + desktop; Lighthouse 90+; axe clean |
| M4 Payments | Stripe, PayPal, bank transfer + screenshot upload, pay on arrival, deposits, webhooks, refunds | Stripe and PayPal sandbox e2e: success, failure, 3DS, refund; bank transfer upload + mark as paid |
| M5 Admin operations | dashboard, calendar, bookings, manifest, check-in, emails, ICS, reminders, webhook out | Manifest prints for a session with 20 bookings; reminders fire through Action Scheduler; every email in section 8 sends |
| M6 Integrations | schema (Rank Math, Yoast, standalone), llms.txt, REST API, Traveler importer, CSV, privacy exporter/eraser | Rich Results valid; schema price = visible price; import keeps every slug |
| M7 Release | branding, white label, GitHub update checker, i18n (Arabic RTL), docs, demo data | Installs on clean WP 6.5; update from one GitHub release to the next works; readme.txt complete |

## 16. Repository layout

```
digitum-bookings/
  digitum-bookings.php        (bootstrap, headers, version)
  uninstall.php
  composer.json  package.json  phpcs.xml.dist  phpunit.xml.dist
  .github/workflows/ (ci.yml, release.yml)
  bin/build-zip.sh
  src/
    Plugin.php
    Admin/ (Menus, Screens, ListTables, MetaBoxes, Settings)
    Domain/ (Product, Variant, Session, Addon, Zone, Booking, Payment)
    Services/ (PriceCalculator, Availability, BookingService, QuoteService, Notifications, Schema, LlmsTxt, Analytics, Updater)
    Gateways/ (GatewayInterface, Stripe, PayPal, BankTransfer, PayOnArrival, Telr stub, NGenius stub)
    Rest/ (Controllers)
    Integrations/ (Elementor, Gutenberg, RankMath, Yoast, Traveler importer)
    Database/ (Migrations, Repositories)
  assets/ (src/, build/)
  templates/ (emails, front components; overridable from the theme under /digitum-bookings/)
  languages/
  tests/ (Unit, Integration, e2e)
  docs/ (ADMIN-GUIDE.md, DEVELOPER-HOOKS.md, CHANGELOG.md)
```

## 17. Developer hooks

Document every filter and action in `docs/DEVELOPER-HOOKS.md`. At minimum: `dgb_price_breakdown`, `dgb_available_sessions`, `dgb_booking_created`, `dgb_booking_status_changed`, `dgb_payment_completed`, `dgb_payment_methods`, `dgb_bank_details`, `dgb_email_template`, `dgb_email_recipients`, `dgb_schema_product`, `dgb_llms_txt_lines`, `dgb_send_sms`.

## 18. How to work

1. Before writing code for a milestone, print the planned file list (and for M1 the database schema) and wait for approval.
2. Commit after each logical step with clear messages.
3. Never use placeholder business facts in UI copy. Use section 13 or mark [CONFIRM].
4. At the end of every milestone report what was built, test results, known gaps and next steps.

## 19. Changes from the first draft (design review)

1. WhatsApp booking handoff and WhatsApp messaging removed. WhatsApp only appears as an optional link for sending a bank transfer screenshot.
2. Email to guest and admin on every booking event (section 8).
3. Deposit is optional with an owner-set percentage; full amount preselected.
4. Payments: PayPal and bank transfer (with screenshot upload) added.
5. Fleet sizes / resource capacity removed; admins close dates or sessions instead.
6. No automatic deletion of guest data; admins erase manually.
7. Releases and updates through GitHub instead of a Digitum update server.

## 20. Decisions made during development

1. M3 ships pay on arrival and bank transfer at checkout; Stripe and PayPal plug in through the `dgb_payment_methods` filter in M4.
2. Confirmation and "booking received" pages are private links on the tour URL (`?dgb_booking=REF&dgb_key=KEY`); the key is a 32-character secret stored on the booking (schema 1.2.0).
3. The widget stylesheet (under 3 KB gzipped) is printed inline, not linked, so the widget adds no render-blocking request.
4. Lighthouse is measured on the e2e test site (Twenty Twenty-Four, PHP built-in server with gzip). That theme's 470 KB of web fonts set the mobile LCP; with system fonts the tour page with the widget scores 99.
