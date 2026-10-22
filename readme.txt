=== Digitum Bookings ===
Contributors: digitummarketing
Tags: bookings, tours, activities, reservations, deposits
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.6.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Tour and activity bookings with live pricing, optional deposits, bank transfer and email notifications. By Digitum Marketing Ltd.

== Description ==

Digitum Bookings adds online booking to tour and activity sites.

* Works with your existing tour post type, so tour URLs never change, or brings its own “Tours” post type.
* Per person, per vehicle, per group and price-on-request tours.
* Durations and seat options, Morning / Evening / Overnight sessions with their own pickup windows.
* Hotel pickup zones and meeting points.
* Optional deposits with a percentage you choose.
* Booking widget with a mobile booking sheet, as a block, shortcode, Elementor widget or automatic on tour pages.
* Card payments (Stripe: cards, Apple Pay, Google Pay), PayPal, bank transfer with screenshot upload, pay on arrival; price-on-request tours become quote requests.
* Refunds, manual payments and "Mark as paid".
* Bookings list and booking page, calendar with drag and drop, printable manifest, phone check-in for guides.
* Emails to guests and staff for every booking event, reminders, daily summary, signed webhooks.
* GA4 dataLayer events and UTM / gclid attribution.
* Product / TouristTrip structured data with the same prices as the page, merged into Rank Math or Yoast SEO; /llms.txt for AI assistants.
* Importer for Traveler theme tours (URLs kept), CSV import and export, REST API for staff tools.
* WordPress personal data export and erase.
* Booking manager and tour guide roles.

This is an early release (milestones 1–6). The update checker, Arabic translation and demo data arrive in the next release.

== Installation ==

1. Download `digitum-bookings.zip` from the latest GitHub release.
2. In WordPress go to Plugins › Add New › Upload Plugin and upload the zip.
3. Activate. If your site already has tours under /tour/, the plugin attaches to them automatically.
4. Open Digitum Bookings › Settings and fill in your business details.

== Frequently Asked Questions ==

= How do I show the booking form? =

On tour pages it appears automatically (Settings › General › Booking widget: "Add the booking widget to single tour pages automatically"). Anywhere else, use the "Booking widget" block, the Elementor widget, or the shortcode [dgb_booking product="123"].

= Can I change the widget's markup? =

Copy `templates/front/booking-widget.php` to `your-theme/digitum-bookings/front/booking-widget.php` and edit the copy.

= Which payment settings do I need? =

Stripe: publishable and secret keys, plus the webhook signing secret (Settings › Payments shows the webhook URL). PayPal: client id and secret from a REST app, plus the webhook id. Start in test / sandbox mode.

= Emails do not arrive. What can I do? =

Booking emails go through wp_mail(). Install an SMTP plugin (for example one that sends through your email provider) and use Digitum Bookings › Emails › Send test email. Every email is logged on the booking page, with a Resend button.

= Does it work with Rank Math or Yoast SEO? =

Yes. With either plugin active, the tour's Product data joins that plugin's schema graph, and any other Product schema on the tour page is replaced so search engines see one product with the price shown on the page. Without an SEO plugin, Digitum Bookings prints the data itself (Settings › Schema & SEO).

= I use the Traveler theme. Will my tour URLs change? =

No. Digitum Bookings › Tools › Import tours from the Traveler theme keeps each tour's ID and slug, and reads the "Duration | Old Price | New Price" tables into bookable options.

= Where are payment screenshots stored? =

In wp-content/uploads/dgb-private, outside the Media Library, under random file names, with rules that block web access. Only staff who manage bookings can open them. On nginx, consider moving the folder outside the web root with the dgb_proof_dir filter.

== Changelog ==

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
