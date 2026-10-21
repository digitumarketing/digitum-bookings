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

# Production autoloader only (the plugin has no runtime dependencies).
cp composer.json "$STAGE/"
composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"

for required in digitum-bookings.php uninstall.php readme.txt vendor/autoload.php assets/build/admin/admin.css assets/build/admin/meta-box.js assets/build/front/booking.js assets/build/front/payment.js assets/build/front/booking.css assets/build/blocks/booking-block.js blocks/booking/block.json templates/front/booking-widget.php; do
	if [ ! -e "$STAGE/$required" ]; then
		echo "Build is missing $required" >&2
		exit 1
	fi
done

(cd "$BUILD" && zip -rq "$SLUG.zip" "$SLUG")
echo "Built $BUILD/$SLUG.zip"
