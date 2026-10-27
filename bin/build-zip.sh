#!/usr/bin/env bash
# Build build/digitum-bookings.zip: production autoloader, built assets, no dev files.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG="digitum-bookings"
BUILD="$ROOT/build"
STAGE="$BUILD/$SLUG"

cd "$ROOT"

if [ -f package-lock.json ]; then
	npm ci --no-audit --no-fund
else
	npm install --no-audit --no-fund
fi
npm run build

rm -rf "$BUILD"
mkdir -p "$STAGE"

# Copy the plugin without dev files (.distignore) or the dev vendor folder.
tar -c --exclude-vcs --exclude="./vendor" --exclude="./build" --exclude-from=.distignore -f - . | tar -x -C "$STAGE"

# Production dependencies only (Action Scheduler), at the locked versions.
cp composer.json composer.lock "$STAGE/"
composer install --working-dir="$STAGE" --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
# A source install (no dist available) leaves git checkouts behind; keep them out of the zip.
find "$STAGE/vendor" -name .git -prune -exec rm -rf {} +
AS="$STAGE/vendor/woocommerce/action-scheduler"
rm -rf "$AS/docs" "$AS/tests" "$AS/Gruntfile.js" "$AS/codecov.yml" "$AS/package.json" "$AS/package-lock.json" "$AS/phpcs.xml" "$AS/composer.lock"

for required in digitum-bookings.php uninstall.php readme.txt vendor/autoload.php vendor/woocommerce/action-scheduler/action-scheduler.php assets/build/admin/admin.css assets/build/admin/meta-box.js assets/build/admin/calendar.js assets/build/admin/checkin.js assets/build/front/booking.js assets/build/front/payment.js assets/build/front/booking.css assets/build/blocks/booking-block.js assets/build/blocks/tour-blocks.js blocks/booking/block.json blocks/from-price/block.json blocks/price-table/block.json blocks/tour-list/block.json blocks/tour-facts/block.json demo/desert-tours.json templates/front/booking-widget.php assets/icon.svg languages/digitum-bookings-ar.mo languages/digitum-bookings-ar.l10n.php; do
	if [ ! -e "$STAGE/$required" ]; then
		echo "Build is missing $required" >&2
		exit 1
	fi
done

(cd "$BUILD" && zip -rq "$SLUG.zip" "$SLUG")
echo "Built $BUILD/$SLUG.zip"
