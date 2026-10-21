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
| M2 Pricing and availability | price calculator, deposits, closures, holds, pickup zones | done (v0.2.0) |
| M3 Front-end flow | booking widget, block, shortcode, Elementor widget | done (v0.3.0) |
| M4 Payments | Stripe, PayPal, bank transfer with screenshot upload, pay on arrival | done (v0.4.0) |
| M5 Admin operations | dashboard, calendar, bookings, manifest, check-in, emails | next |
| M6 Integrations | schema, llms.txt, REST API, Traveler importer, CSV | |
| M7 Release | update checker, Arabic RTL, docs, demo data | |

The full specification is in [CLAUDE.md](CLAUDE.md). The approved layout is at https://claude.ai/artifact/2bkeTdhGqaG8qRdftzd49h.

## Publishing a new release

1. Set the same version in three places: the `Version:` header and `DGB_VERSION` in `digitum-bookings.php`, and `Stable tag:` in `readme.txt`.
2. Add the changes to `docs/CHANGELOG.md` and the changelog in `readme.txt`.
3. Commit, then tag and push:
   ```bash
   git tag v0.4.0
   git push origin v0.4.0
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
composer test:coverage    # unit tests + 100% coverage gate for the pricing engine (needs pcov or Xdebug)
composer build            # build/digitum-bookings.zip
```

Activation tests run against a real WordPress with an SQLite database, so no MySQL is needed:

```bash
git clone --depth 1 --branch 6.5.5 https://github.com/WordPress/WordPress.git .wp-test/wordpress
git clone --depth 1 --branch v2.1.16 https://github.com/WordPress/sqlite-database-integration.git .wp-test/sqlite
DGB_WP_DIR=.wp-test/wordpress DGB_SQLITE_DIR=.wp-test/sqlite composer test:integration
```

Set `DGB_PLUGIN_DIR` to an unzipped `digitum-bookings` folder to test a release build instead of the checkout, and `DGB_PREVIOUS_DIR` to a checkout of the previous release (`git worktree add ../previous v0.3.0`) to test upgrading from it.

Browser tests (Playwright, desktop and phone, with axe accessibility checks) build a test site from the same checkouts and serve it with PHP's built-in server:

```bash
npx playwright install chromium   # once
DGB_WP_DIR=.wp-test/wordpress DGB_SQLITE_DIR=.wp-test/sqlite npm run test:e2e
```

The browser tests use fake Stripe and PayPal SDKs and APIs (`tests/e2e/fakes`, `tests/Integration/fake-gateways.php`). To run `tests/e2e/sandbox.spec.ts` against the real Stripe test mode and PayPal sandbox, prepare the site with `DGB_SANDBOX_STRIPE_PK`, `DGB_SANDBOX_STRIPE_SK`, `DGB_SANDBOX_PAYPAL_CLIENT`, `DGB_SANDBOX_PAYPAL_SECRET` (and `DGB_SANDBOX_PAYPAL_BUYER` / `DGB_SANDBOX_PAYPAL_BUYER_PASSWORD` for the PayPal buyer login).

Set `DGB_STRIPE_MOCK=http://localhost:12111` with [stripe-mock](https://github.com/stripe/stripe-mock) running to check the plugin's Stripe requests against Stripe's API schema (CI does this).

Lighthouse can be run against that site while it is up: `npx lighthouse http://127.0.0.1:8889/tour/rzr/`.

Regenerate the translation template with WP-CLI: `wp i18n make-pot . languages/digitum-bookings.pot --exclude=vendor,node_modules,tests,build,.wp-test,assets/build`.

## Licence

GPL-2.0-or-later.
