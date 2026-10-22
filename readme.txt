=== Digitum Bookings ===
Contributors: digitummarketing
Tags: bookings, tours, activities, reservations, deposits
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tour and activity bookings: live prices, sessions with pickup times, deposits, card, PayPal and bank transfer, emails, manifests. By Digitum.

== Description ==

Digitum Bookings adds online booking to tour and activity sites: desert safaris, buggy and quad rentals, city tours, boat trips, anything sold by the person, the vehicle or the group. It is built and supported by [Digitum Marketing Ltd](https://digitum.marketing/).

= Tours and prices =

* Works with the tour post type your site already has (for example the Traveler theme's tours), so tour URLs never change, or brings its own “Tours” post type.
* Per person (with child prices and free infants), per vehicle (seats per vehicle are checked), per car or group (extra cars added automatically) and price on request.
* Options such as 30 minutes, 1 hour or 2 hours; Morning, Evening, Overnight or fixed sessions, each with its own start time and hotel pickup window.
* Pickup zones (with or without pickup, with a surcharge) and meeting points with map and directions.
* Add-ons (per booking, per person, per vehicle or on request), hotel pickup as an included service or a paid extra.
* Closed dates and sessions, date price changes, weekend uplift, same-day cut-off, minimum notice and booking window.
* VAT included or added, optional deposits with your own percentage, coupons.
* Dated promotions only: no permanent “old price” strike-throughs, no countdowns or fake scarcity.

= Booking =

* Booking widget as a block, shortcode `[dgb_booking product="123"]`, Elementor widget or automatically on tour pages; a sticky “From … / Book” bar opens a stepped booking sheet on phones.
* Card payments with Stripe (cards, Apple Pay, Google Pay; 3-D Secure), PayPal, bank transfer with screenshot upload, pay on arrival. Price-on-request tours end in “Request a quote”; staff send the quote with a payment link.
* Private confirmation page with “Add to calendar”; payment link for balances.
* Accessible (WCAG 2.2 AA), fast (no jQuery, about 7 KB of script, no layout shift), translated into Arabic with a right-to-left layout.

= Running the business =

* Dashboard, bookings list with filters and CSV export, booking page with payments, refunds, notes, email log and timeline.
* Calendar with drag and drop, printable manifest per session, phone check-in for guides.
* Emails to guests and staff for every booking event, with editable templates, reminders and a daily summary.
* Coupons and reports: revenue by tour and source, conversion by source, cancellation rate.
* Booking manager and tour guide roles.

= Search, analytics and tools =

* Product / TouristTrip structured data with the same prices as the page, merged into Rank Math or Yoast SEO; `/llms.txt` for AI assistants.
* GA4 ecommerce events in the dataLayer (Google Tag Manager), UTM and gclid attribution, enhanced conversions.
* Signed webhooks for Zapier, Make and GoHighLevel; REST API for staff tools; SMS through the `dgb_send_sms` filter.
* Importer for Traveler theme tours (IDs and URLs kept), CSV import and export, one-click demo data.
* Updates straight from the plugin's GitHub releases.

= Privacy =

Guest data stays on your site and is never deleted automatically. WordPress's Export and Erase Personal Data tools include bookings; erasing keeps the booking and its amounts for your accounts. Card details go straight from the guest's browser to Stripe or PayPal (PCI SAQ A). Payment screenshots are stored outside the Media Library and only staff can open them. Booking emails and webhooks are only sent to the addresses you set.

“Booking by Digitum”, a small link to digitum.marketing, is shown under every booking widget.

== Installation ==

1. Download `digitum-bookings.zip` from the latest release on GitHub (github.com/digitumarketing/digitum-bookings/releases).
2. In WordPress go to Plugins › Add New › Upload Plugin, upload the zip and activate it.
3. If your site already has tours under /tour/ (for example from the Traveler theme), the plugin attaches to them on activation; check Settings › General › “Tours come from”. Otherwise it uses its own Tours post type.
4. Fill in Digitum Bookings › Settings: business details, currency and VAT, payments, emails.
5. Add pickup zones and meeting points, then open a tour, fill in its Booking settings and tick “Take bookings for this tour”.
6. Optional: Digitum Bookings › Tools imports Traveler theme tours, CSV files or the Desert Bookings demo data.

New versions appear under Dashboard › Updates like any other plugin.

== Frequently Asked Questions ==

= How do I show the booking form? =

On tour pages it appears automatically (Settings › General › Booking widget). Anywhere else, use the "Booking widget" block, the Elementor widget, or the shortcode [dgb_booking product="123"].

= Can I change the widget's markup? =

Copy `templates/front/booking-widget.php` to `your-theme/digitum-bookings/front/booking-widget.php` and edit the copy.

= Which payment settings do I need? =

Stripe: publishable and secret keys, plus the webhook signing secret (Settings › Payments shows the webhook URL). PayPal: client id and secret from a REST app, plus the webhook id. Start in test / sandbox mode. Bank transfer: your account details. Pay on arrival needs nothing.

= Emails do not arrive. What can I do? =

Booking emails go through wp_mail(). Install an SMTP plugin (for example one that sends through your email provider) and use Digitum Bookings › Emails › Send test email. Every email is logged on the booking page, with a Resend button.

= Does it work with Rank Math or Yoast SEO? =

Yes. With either plugin active, the tour's Product data joins that plugin's schema graph, and any other Product schema on the tour page is replaced so search engines see one product with the price shown on the page. Without an SEO plugin, Digitum Bookings prints the data itself (Settings › Schema & SEO).

= I use the Traveler theme. Will my tour URLs change? =

No. Digitum Bookings › Tools › Import tours from the Traveler theme keeps each tour's ID and slug, and reads the "Duration | Old Price | New Price" tables into bookable options.

= Is it available in Arabic? =

Yes. The plugin ships with an Arabic translation and a right-to-left layout; it is used when the site (or the user's profile) language is Arabic.

= How do updates work? =

The plugin checks the latest release of its GitHub repository and WordPress offers the update (automatic updates can be switched on on the Plugins screen). If the repository is private, add a read-only token in Settings › Updates.

= Can I remove “Booking by Digitum”? =

No. Digitum Bookings is a Digitum product and the small credit link is part of it.

= Where are payment screenshots stored? =

In wp-content/uploads/dgb-private, outside the Media Library, under random file names, with rules that block web access. Only staff who manage bookings can open them. On nginx, consider moving the folder outside the web root with the dgb_proof_dir filter.

== Screenshots ==

1. Booking widget on a tour page: option, date and session.
2. Phone booking sheet in Arabic, right to left.
3. Bookings list and booking page.
4. Manifest print view for a session.
5. Reports by tour and source.

== Changelog ==

= 0.7.0 =
* Updates from GitHub releases (Dashboard › Updates, automatic updates, private repository token).
* Arabic translation and right-to-left fixes.
* Coupons screen and coupon field in the booking widget; Reports by tour and source with conversion and cancellation rate.
* Import Desert Bookings demo data (Tools).
* Digitum branding everywhere: “Booking by Digitum” under every widget, no white label.

= 0.6.0 =
* Structured data for tours (Product / TouristTrip, offers per option), merged into Rank Math and Yoast SEO graphs.
* /llms.txt listing tours, prices, sessions, pickup and contact details.
* Traveler theme importer and CSV import / export (Digitum Bookings › Tools).
* REST API: tour list, staff bookings (list, create, update, cancel), check-in.
* Personal data exporter and eraser.

= 0.5.0 =
* Bookings list, booking page, calendar (month / week / day, drag to move, close dates), manifest with print view and CSV, check-in for guides.
* Emails for every booking event with editable templates, reminders and a daily summary (Action Scheduler).
* Signed webhooks for Zapier, Make and GoHighLevel.

= 0.4.0 =
* Stripe card payments (Payment Element, 3-D Secure) and PayPal Checkout, with verified webhooks.
* Bank transfer screenshot upload; Mark as paid from the dashboard.
* Payments screen: manual payments, refunds (through Stripe and PayPal), payment link.
* Deposits charged online; the balance can be paid with a payment link.

= 0.3.0 =
* Booking widget: option, date, session, guests, pickup, extras, details and payment, with a stepped bottom sheet on phones.
* Gutenberg block, shortcode [dgb_booking product="123"], Elementor widget and automatic placement on tour pages.
* Pay on arrival and bank transfer; quote requests for price-on-request tours.
* Confirmation page with "Add to calendar".
* GA4 dataLayer events, UTM and gclid attribution, optional Cloudflare Turnstile.

= 0.2.0 =
* Pricing engine for per person, per vehicle, per group and price-on-request tours, with child prices, free infants, add-ons, pickup zones, coupons, VAT and optional deposits.
* Closed dates and date price changes per tour; weekend uplift.
* Session availability with same-day cut-off, minimum notice and booking window.
* Pickup zones and meeting points screen.
* Unpaid bookings are released automatically when their hold expires.

= 0.1.0 =
* Foundation: settings, tour post type (attach or own), booking settings box, database tables, roles.

== Upgrade Notice ==

= 0.7.0 =
Adds GitHub updates, Arabic, coupons and reports. The database is updated automatically (schema 1.5.0).
