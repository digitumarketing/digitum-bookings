=== Digitum Bookings ===
Contributors: digitummarketing
Tags: bookings, tours, activities, reservations, deposits
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.3.0
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
* Pay on arrival and bank transfer; price-on-request tours become quote requests.
* GA4 dataLayer events and UTM / gclid attribution.
* Booking manager and tour guide roles.

This is an early release (milestones 1–3: foundation, pricing, availability and the booking widget). Card and PayPal payments, the bookings screens and emails arrive in the next releases.

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

== Changelog ==

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
