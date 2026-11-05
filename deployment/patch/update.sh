#!/bin/bash
set -euo pipefail
umask 077
payload="$(cd "$(dirname "$0")" && pwd)"
fail() { echo "ERROR: $*" >&2; exit 1; }
setting() { awk -v key="$2" 'index($0,key "=")==1 {sub(/^[^=]*=/, ""); gsub(/\r$/, ""); print; exit}' "$1"; }
install=''; check=0
while [ "$#" -gt 0 ]; do
  case "$1" in
    --installation) [ "$#" -ge 2 ] || fail 'Missing installation folder.'; install="$2"; shift 2 ;;
    --check) check=1; shift ;;
    --help|-h) echo 'Usage: bash update.sh --installation /existing/Yodamen [--check]'; exit 0 ;;
    *) fail "Unknown argument: $1" ;;
  esac
done
[ -n "$install" ] && [ -d "$install" ] || fail 'Specify the existing installed folder with --installation.'
install="$(cd "$install" && pwd)"
[ "$install" != "$payload" ] || fail 'Extract the patch separately from the installed app.'
for file in .env runtime.env compose.yaml; do
  [ -f "$install/$file" ] || fail "Not a supported installation: missing $file. No changes made."
done
# Compose interpolation must use this installation, not a different shell session.
unset APP_VERSION COMPOSE_FILE COMPOSE_PROJECT_NAME APP_PORT DB_PASSWORD DB_ROOT_PASSWORD
project="$(setting "$install/.env" COMPOSE_PROJECT_NAME)"; project="${project:-yodamen-trial}"
case "$project" in ''|*[!a-z0-9_-]*|[-_]*) fail 'Invalid installed Compose project name.' ;; esac
version="$(cat "$payload/RELEASE_VERSION")"
case "$version" in ''|*[!a-zA-Z0-9_.-]*|[.-]*) fail 'Invalid release version.' ;; esac
[ "$(setting "$install/runtime.env" SESSION_DRIVER)" = database ] || fail 'This patch expects database sessions. Review custom installations manually.'
# Avoid replacing a custom Compose override or selecting an unexpected project.
[ ! -f "$install/compose.yml" ] || fail 'Conflicting compose.yml: review installation manually.'
[ ! -f "$install/compose.override.yml" ] || fail 'Custom compose.override.yml: review installation manually.'
if [ -f "$install/compose.override.yaml" ]; then
  head -n 1 "$install/compose.override.yaml" | grep -qx '# Managed by the Yodamen patch updater. Keep this file for future restarts.' || fail 'Custom compose.override.yaml: review it before updating.'
fi
command -v docker >/dev/null || fail 'Start Docker Desktop and enable WSL Ubuntu integration on Windows.'
command -v openssl >/dev/null || fail 'OpenSSL is needed to verify release files.'
# SHA256SUMS contains the exact publisher-generated payload, with safe relative paths.
while IFS= read -r line; do
  digest="${line%%  *}"; file="${line#*  }"
  case "$file" in ''|/*|*..*|*\\*) fail 'Unsafe release manifest path.' ;; esac
  [ -f "$payload/$file" ] || fail "Missing release file: $file"
  actual="$(openssl dgst -sha256 "$payload/$file")"; actual="${actual##* }"
  [ "$actual" = "$digest" ] || fail "Release checksum mismatch: $file. Re-extract the patch."
done < "$payload/SHA256SUMS"
cd "$install"
compose=(docker compose --project-directory "$install" --env-file "$install/.env" --project-name "$project" -f "$install/compose.yaml")
if [ -f compose.override.yaml ]; then compose+=(-f "$install/compose.override.yaml"); fi
"${compose[@]}" config --quiet
"${compose[@]}" exec -T db true
"${compose[@]}" exec -T app php -r 'if (is_file("storage/framework/down")) {fwrite(STDERR,"Application is already in maintenance. Resolve that update first.\n");exit(1);}'
# Fail before backup or shutdown if the selected app is not the expected running instance.
app_id="$("${compose[@]}" ps -q app)"
[ -n "$app_id" ] || fail 'The existing app must be running before applying a patch.'
previous_image="$(docker inspect --format '{{.Image}}' "$app_id")"
if [ "$check" = 1 ]; then
  echo "Preflight passed for $project. Target release: $version. No update was applied."
  exit 0
fi
lock="$install/.yodamen-update-lock"
mkdir "$lock" 2>/dev/null || fail 'Another update is running, or a previous failure needs review. See PATCH.md.'
finished=0; downtime=0; backup=''
cleanup() {
  result=$?
  if [ "$finished" = 0 ]; then
    if [ "$downtime" = 1 ]; then
      "${compose[@]}" stop app >&2 || true
      echo 'Update stopped. The app remains closed. Do not rerun the installer or delete volumes.' >&2
      echo "Backup/recovery details: ${backup:-backup not completed}. See PATCH.md." >&2
    fi
  fi
  rmdir "$lock" 2>/dev/null || true
  exit "$result"
}
trap cleanup EXIT
release="$install/.yodamen-releases/$version"
[ ! -e "$release" ] || fail 'Release folder already exists. See PATCH.md before retrying.'
mkdir -p "$release"
# Copy only the already verified files; no source from the old installation is reused.
while IFS= read -r line; do
  file="${line#*  }"; mkdir -p "$release/$(dirname "$file")"; cp "$payload/$file" "$release/$file"
done < "$payload/SHA256SUMS"
cp "$payload/SHA256SUMS" "$release/SHA256SUMS"
candidate="$release/compose.override.yaml"
sed "s/RELEASE_VERSION/$version/g" "$payload/compose.patch.yaml" > "$candidate"
next=(docker compose --project-directory "$install" --env-file "$install/.env" --project-name "$project" -f "$install/compose.yaml" -f "$candidate")
"${next[@]}" config --quiet
# Give the previous image a durable local tag even when its old tag was replaced.
docker image tag "$previous_image" "yodamen-local:rollback-$version"
echo 'Building the patch while the current app stays available...'
"${next[@]}" build app
"${next[@]}" run --rm --no-deps -T app php -r 'if (!extension_loaded("sodium")) exit(1);'
backup="$install/backups/pre-$version-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup"
cp .env runtime.env compose.yaml "$backup/"
if [ -f compose.override.yaml ]; then cp compose.override.yaml "$backup/"; fi
printf '%s\n' "$previous_image" > "$backup/previous-image-id.txt"
printf '%s\n' "yodamen-local:rollback-$version" > "$backup/previous-image-tag.txt"
"${compose[@]}" config --images > "$backup/images.txt"
# No separate backup.sh call: it would reopen the app between backup and migration.
downtime=1
"${compose[@]}" exec -T app php artisan down --retry=60
"${compose[@]}" stop app
# Read only state from the stopped application environment using the previous image.
"${compose[@]}" run --rm --no-deps -T --entrypoint php app < "$payload/capture-state.php" > "$backup/business-state.json"
"${compose[@]}" exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces "$MYSQL_DATABASE"' > "$backup/database.sql"
[ -s "$backup/database.sql" ] || fail 'Empty database backup; migrations were not run.'
# A stopped app still has its uploads volume attached. Read it through the old image.
docker run --rm --volumes-from "$app_id" --entrypoint tar "$previous_image" czf - -C /var/www/html/storage app > "$backup/uploads.tar.gz"
tar -tzf "$backup/uploads.tar.gz" >/dev/null
printf '%s\n' 'Database, uploads, configuration and previous image saved before migrations.' > "$backup/BACKUP-COMPLETE.txt"
cp "$candidate" "$install/compose.override.yaml"
# File maintenance state is shared by the migration and web containers from now on.
"${next[@]}" run --rm --no-deps -T app php artisan down --retry=60
"${next[@]}" run --rm --no-deps -T app php artisan migrate --force
"${next[@]}" run --rm --no-deps -T app php artisan optimize:clear
"${next[@]}" run --rm --no-deps -T app php artisan config:cache
"${next[@]}" run --rm --no-deps -T app php /var/www/html/verify-patch.php < "$backup/business-state.json"
"${next[@]}" up -d --no-deps --no-build app
# The maintenance file remains in the shared volume during startup.
"${next[@]}" exec -T app php artisan up
if ! "${next[@]}" exec -T app php /var/www/html/check-health.php; then
  "${next[@]}" exec -T app php artisan down --retry=60 || true
  "${next[@]}" stop app || true
  fail 'Health check failed. The app was closed again; use the backup for recovery.'
fi
# Save version atomically without rewriting any credentials or runtime.env.
awk -v version="$version" 'BEGIN{found=0} /^APP_VERSION=/{print "APP_VERSION=" version;found=1;next} {print} END{if(!found)print "APP_VERSION=" version}' .env > .env.patch-tmp
chmod 600 .env.patch-tmp
mv .env.patch-tmp .env
printf '%s\n' "$version" > .yodamen-installed-release
finished=1
echo "Patch installed: $version"
echo "Backup: $backup"
echo 'Verify login, images, records and Admin > Subscription. Paid plans are permanent; the unactivated Premium trial allows 50 activities.'
