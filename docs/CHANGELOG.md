# Changelog

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
