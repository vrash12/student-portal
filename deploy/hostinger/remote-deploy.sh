#!/usr/bin/env bash
# Installs one release on the Hostinger trial site (testwebsitetrial.site).
# Run by receive.sh with the unpacked release folder and its commit id, after
# GitHub Actions built the frontend (public/build is in the release).
#
#   1. back up the database (gzip; the newest 15 are kept)
#   2. install the PHP packages inside the release, before anything live changes
#   3. maintenance mode; copy the release over the app, keeping .env, storage/
#      and bootstrap/cache/; clear the old caches; run migrations and sync roles
#      and permissions
#   4. publish public/ into public_html, with the Hostinger .htaccess prefix
#      (it selects PHP 8.4) followed by the app's own .htaccess
#   5. rebuild the caches and leave maintenance mode
#
# A failure before step 3 changes nothing live. A failure from step 3 on
# leaves the site in maintenance mode and fails the GitHub run (GitHub emails
# the person who pushed); the database backup of step 1 is kept.
set -euo pipefail

RELEASE="$1"
COMMIT="$2"
SHORT="${COMMIT:0:7}"
PHP=/opt/alt/php84/usr/bin/php
SITE="$HOME/domains/testwebsitetrial.site"
APP="$SITE/academic-app"
WEB="$SITE/public_html"
BACKUPS="$HOME/backups/testwebsitetrial.site"
HTACCESS_PREFIX="$SITE/htaccess-hostinger.conf"
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP_FILE="$BACKUPS/db-before-deploy-$STAMP-$SHORT.sql.gz"
COMPOSER="$(command -v composer || echo "$HOME/composer.phar")"
LIVE_CHANGED=0

say() { echo "[deploy $SHORT] $*"; }
on_error() {
    if [ "$LIVE_CHANGED" = 1 ]; then
        say "FAILED after the live site was changed. It stays in maintenance mode. Database backup: $BACKUP_FILE"
    else
        say "FAILED before the live site was changed. Nothing live was changed."
    fi
    echo "$STAMP $COMMIT failed" >> "$SITE/deploy-history.log"
}
trap on_error ERR

env_value() { grep -E "^$1=" "$APP/.env" | head -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

[ -f "$APP/.env" ] || { say "Missing $APP/.env"; exit 1; }
[ -f "$HTACCESS_PREFIX" ] || { say "Missing $HTACCESS_PREFIX (it selects PHP 8.4); nothing was changed."; exit 1; }
[ -d "$RELEASE/public/build" ] || { say "The release has no built frontend (public/build)."; exit 1; }

say "1/5 Backing up the database"
mkdir -p "$BACKUPS"
MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump --single-transaction --routines \
    -h "$(env_value DB_HOST)" -P "$(env_value DB_PORT)" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" \
    | gzip > "$BACKUP_FILE"
(ls -1t "$BACKUPS"/db-before-deploy-*.sql.gz 2>/dev/null | tail -n +16 | xargs -r rm -f) || true

say "2/5 Installing PHP packages"
cp -a "$APP/vendor" "$RELEASE/vendor"
cp "$APP/.env" "$RELEASE/.env"
(cd "$RELEASE" && "$PHP" "$COMPOSER" install --no-dev --optimize-autoloader --no-interaction --no-progress)

say "3/5 Updating the application"
cd "$APP"
"$PHP" artisan down --retry=30 || true
LIVE_CHANGED=1
rsync -a --delete --exclude=/.env --exclude=/storage/ --exclude=/bootstrap/cache/ "$RELEASE/" "$APP/"
# Drop the previous release's cached configuration, routes and package list first.
"$PHP" artisan optimize:clear
"$PHP" artisan package:discover
"$PHP" artisan migrate --force
"$PHP" artisan db:seed --class=AccessControlSeeder --force

say "4/5 Publishing public files"
rsync -a --delete --exclude=/index.php --exclude=/.htaccess --exclude=/storage "$APP/public/" "$WEB/"
cat "$HTACCESS_PREFIX" "$APP/public/.htaccess" > "$WEB/.htaccess.new"
mv "$WEB/.htaccess.new" "$WEB/.htaccess"

say "5/5 Rebuilding caches"
"$PHP" artisan config:cache
"$PHP" artisan route:cache
"$PHP" artisan view:cache
"$PHP" artisan up
LIVE_CHANGED=0
trap - ERR

echo "$STAMP $COMMIT ok" >> "$SITE/deploy-history.log"
say "Done: $COMMIT is live."
