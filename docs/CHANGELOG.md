# Changelog

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
