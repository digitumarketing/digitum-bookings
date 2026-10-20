# Digitum Bookings

Tour and activity booking plugin for WordPress, by [Digitum Marketing Ltd](https://digitum.marketing/).

Works with any theme, with or without Elementor. It can attach to a tour post type the site already has, so tour URLs never change.

## Download and install

1. Open the [latest release](https://github.com/digitumarketing/digitum-bookings/releases/latest) and download **digitum-bookings.zip**.
2. In WordPress: **Plugins › Add New › Upload Plugin**, choose the zip, install and activate.
3. Go to **Digitum Bookings › Settings** and fill in the business details.

If the site already serves tours under `/tour/` (for example the Traveler theme's `st_tours`), the plugin attaches to that post type on first activation and leaves every URL as it is.

## Status

| Milestone | Scope | State |
|---|---|---|
| M1 Foundation | settings, attach / own tour post type, booking settings box, tables, roles, release workflow | done (v0.1.0) |
| M2 Pricing and availability | price calculator, deposits, closures, holds | next |
| M3 Front-end flow | booking widget, block, shortcode, Elementor widget | |
| M4 Payments | Stripe, PayPal, bank transfer with screenshot upload, pay on arrival | |
| M5 Admin operations | dashboard, calendar, bookings, manifest, check-in, emails | |
| M6 Integrations | schema, llms.txt, REST API, Traveler importer, CSV | |
| M7 Release | update checker, Arabic RTL, docs, demo data | |

The full specification is in [CLAUDE.md](CLAUDE.md). The approved layout is at https://claude.ai/artifact/2bkeTdhGqaG8qRdftzd49h.

## Publishing a new release

1. Set the same version in three places: the `Version:` header and `DGB_VERSION` in `digitum-bookings.php`, and `Stable tag:` in `readme.txt`.
2. Add the changes to `docs/CHANGELOG.md` and the changelog in `readme.txt`.
3. Commit, then tag and push:
   ```bash
   git tag v0.2.0
   git push origin v0.2.0
   ```
4. The **Release** GitHub Action checks that the tag matches the version, builds `digitum-bookings.zip` and attaches it to a new GitHub release.

## Development

Requirements: PHP 8.1+, Composer 2, Node 20+.

```bash
composer install          # dev tools: PHPCS + WordPress Coding Standards, PHPUnit, Brain Monkey
npm install
npm run build             # assets/src → assets/build
composer lint             # coding standards
composer test             # unit tests
composer build            # build/digitum-bookings.zip
```

Activation tests run against a real WordPress with an SQLite database, so no MySQL is needed:

```bash
git clone --depth 1 --branch 6.5.5 https://github.com/WordPress/WordPress.git .wp-test/wordpress
git clone --depth 1 --branch v2.1.16 https://github.com/WordPress/sqlite-database-integration.git .wp-test/sqlite
DGB_WP_DIR=.wp-test/wordpress DGB_SQLITE_DIR=.wp-test/sqlite composer test:integration
```

Set `DGB_PLUGIN_DIR` to an unzipped `digitum-bookings` folder to test a release build instead of the checkout.

Regenerate the translation template with WP-CLI: `wp i18n make-pot . languages/digitum-bookings.pot --exclude=vendor,node_modules,tests,build,.wp-test,assets/build`.

## Licence

GPL-2.0-or-later.
