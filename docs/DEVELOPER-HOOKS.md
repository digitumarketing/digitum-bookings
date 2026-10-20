# Developer hooks

Every public action and filter of Digitum Bookings is listed here, with the release that added it.

Version 0.1.0 adds no public hooks yet. The hooks listed in section 17 of `CLAUDE.md` (`dgb_price_breakdown`, `dgb_booking_created`, `dgb_email_template` and the others) arrive with the milestones that introduce pricing, bookings and emails.

## Options and meta (for reference)

| Name | Type | Contents |
|---|---|---|
| `dgb_settings` | option | All settings, grouped by tab: `general`, `currency`, `payments`, `privacy`, `branding` |
| `dgb_db_version` | option | Schema version applied by the migrations runner |
| `dgb_version` | option | Plugin version that last ran its upgrade routine |
| `_dgb_*` | post meta | Booking settings of a tour (see `src/Domain/Product.php`) |
| `{prefix}dgb_*` | tables | See `src/Database/Schema.php` |
