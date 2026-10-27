# Demo packs

A demo pack is optional sample content for an empty site: tours with their booking settings, options, sessions and add-ons, pickup zones, meeting points, coupons, offers and a few settings. Nothing from a pack exists on a site until an administrator imports it on **Digitum Bookings › Tools › Demo data**, and **Remove demo data** deletes it again.

Packs are JSON files. The plugin ships its packs in `demo/`; a theme or another plugin can add its own:

```php
add_filter( 'dgb_demo_packs', function ( array $packs ) {
	$packs['my-sample'] = __DIR__ . '/demo/my-sample.json'; // or the decoded array
	return $packs;
} );
```

The array key must equal the pack's `id`. A pack that does not follow the format below is listed with the reason and cannot be imported.

## What import and removal do

- Tours are created as **drafts with bookings switched off**. A post of the tour post type with the same slug is never changed: the tour is skipped and the report says so.
- Every record created is tagged with the pack id: post meta `_dgb_demo` on tours, the `demo_pack` column on rows of `dgb_variants`, `dgb_sessions`, `dgb_addons`, `dgb_zones`, `dgb_locations`, `dgb_coupons` and `dgb_offers`.
- A zone or meeting point with the same name as an existing one is not created; demo tours use the existing meeting point. A coupon whose code exists is skipped. A coupon or offer limited to tours that were all skipped is left out (it never becomes a rule for every tour).
- Settings are only changed where they are still empty or at their default. The previous value is stored in the `dgb_demo_backup` option first.
- Importing the same pack again does nothing. Only one pack can be active (`dgb_demo_active`); another pack can be imported after the current one is removed.
- Removal deletes every tagged record and puts back each backed-up setting whose value is still the one the pack set. Tours that were edited after the import (title, text, status, image, booking settings, options, sessions or add-ons) or that have bookings are listed first: the administrator keeps each one (tags removed) or deletes it. A booked tour that is deleted goes to the trash. Bookings are never deleted. Meeting points still used by a remaining tour and zones used by a booking are kept.

## Format (schema 1)

```json
{
	"schema": 1,
	"id": "sample-tours",
	"name": "Sample tours",
	"description": "One line shown on the Tools screen.",
	"settings": { "general": { "business_name": "Sample Tours" }, "currency": { "currency": "EUR", "vat_rate": 7 } },
	"session_templates": {
		"am": { "name": "Morning", "type": "morning", "start_time": "09:00", "pickup_window_start": "08:00", "pickup_window_end": "08:30" }
	},
	"locations": [ { "key": "pier", "name": "Sample Meeting Point", "address": "1 Sample Street", "lat": 0, "lng": 0, "map_url": "", "instructions": "" } ],
	"zones": [ { "name": "North Zone", "allowed": true }, { "name": "Far Zone", "allowed": false, "notes": "No pickup from here." } ],
	"tours": [
		{
			"key": "boat",
			"title": "Sample Boat",
			"slug": "sample-boat",
			"content": "<p>Optional post text.</p>",
			"settings": { "pricing_mode": "per_vehicle", "seats_per_unit": 4, "pickup_policy": "addon", "meeting_point": "pier" },
			"variants": [ { "label": "1 hour", "duration_minutes": 60, "price": "100" } ],
			"sessions": [ "am", { "template": "am", "name": "Late", "start_time": "16:00", "season_start": "{year}-12-31", "season_end": "{year}-12-31" } ],
			"addons": [ { "label": "Hotel pickup", "price": "30", "price_type": "per_booking", "condition_key": "pickup" } ],
			"confirm": [ "Things to check on this tour, listed in the import report" ]
		}
	],
	"coupons": [ { "code": "SAMPLE10", "type": "percent", "amount": 10, "active": true, "tours": [ "boat" ] } ],
	"offers": [
		{
			"name": "Free pickup on 2 boats", "label": "Free pickup on 2 or more boats", "active": false, "priority": 5,
			"starts_on": "2026-11-01", "ends_on": "2027-03-31", "date_basis": "travel",
			"conditions": { "tours": [ "boat" ], "min_units": 2 },
			"effect": "waive_addon", "addon_key": "pickup", "show_hint": true
		}
	],
	"notes": [ "Shown under “Still to do” after the import." ]
}
```

| Key | Required | Contents |
|---|---|---|
| `schema` | yes | `1` |
| `id` | yes | Lower-case letters, numbers, `-` and `_`, 50 characters at most |
| `name` | yes | Shown on the Tools screen and in the admin reminder |
| `description` | no | One line on the Tools screen |
| `settings` | no | Tab => key => value, using the keys of `src/Settings/Settings.php` (secrets and internal keys are refused) |
| `session_templates` | no | Key => session fields, reused by tours |
| `locations` | no | Meeting points: `key` (referenced by tours), `name`, `address`, `lat`, `lng`, `map_url`, `instructions` |
| `zones` | no | Pickup zones: `name`, `allowed`, `surcharge`, `notes` |
| `tours` | no | See below |
| `coupons` | no | Fields of the Coupons screen: `code`, `type` (percent, fixed), `amount`, `usage_limit`, `starts_at`, `ends_at`, `active`; `tours` lists tour keys |
| `offers` | no | Fields of the Offers screen (`name`, `label`, `active`, `priority`, `starts_on`, `ends_on`, `date_basis`, `effect`, `amount`, `addon_key`, `show_hint`) and `conditions` (`tours` as tour keys, `pricing_modes`, `min_units`, `max_units`, `min_guests`, `weekdays`, `min_subtotal`) |
| `notes` | no | Lines shown under “Still to do” after the import |

Tour keys:

| Key | Required | Contents |
|---|---|---|
| `key` | yes | Unique within the pack; coupons and offers refer to it |
| `title`, `slug` | yes | Post title and URL slug |
| `content` | no | Post text (HTML allowed in posts) |
| `settings` | no | Booking settings by the keys of `src/Domain/Product.php` (`pricing_mode`, `seats_per_unit`, `max_pax_per_unit`, `pickup_policy`, `pickup_default`, `min_age`, …) plus `meeting_point` (a location key). `enabled` is always off. |
| `variants` | no | Options: `label`, `duration_minutes`, `price`, `child_price` |
| `sessions` | no | A template key, or session fields (`template` to start from a template, `name`, `type`, `start_time`, `end_time`, `pickup_window_start`, `pickup_window_end`, `days_of_week`, `season_start`, `season_end`). `{year}` in a value becomes the current year. |
| `addons` | no | `label`, `price`, `price_type` (per_booking, per_person, per_vehicle, on_request), `condition_key` (`pickup` or empty), `required` |
| `confirm` | no | What to check on this tour, listed in the import report |

Values are cleaned with the same rules as the admin screens, so an invalid value falls back to the default.
