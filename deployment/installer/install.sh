#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
umask 077
fail() { echo "ERROR: $*" >&2; exit 1; }
setting() { awk -v key="$2" 'index($0,key "=")==1 {sub(/^[^=]*=/, ""); sub(/\r$/, ""); print; exit}' "$1"; }
key_from() {
  [ -f "$1" ] || fail 'Original backend .env file does not exist.'
  local value
  value="$(setting "$1" APP_KEY)"; value="${value#\"}"; value="${value%\"}"
  value="${value#\'}"; value="${value%\'}"
  [[ "$value" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]] || fail 'Expected a 32-byte base64 APP_KEY in the original backend .env.'
  printf '%s' "$value"
}
setup_mode=''; mode=''; setup_file=''; dump=''; uploads=''; key_file=''; project='yodamen'; port=8080; custom_project=0; custom_port=0
while [ "$#" -gt 0 ]; do
  case "$1" in
    --cli-setup) setup_mode=cli; shift;;
    --clean) [ "$mode" != restore ] || fail "Choose clean OR restore."; mode=clean; shift;;
    --restore) [ "$mode" != clean ] || fail "Choose clean OR restore."; mode=restore; shift;;
    --dump|--uploads|--key-file|--project|--port|--setup-file)
      [ "$#" -ge 2 ] || fail "Missing value for $1"
      case "$1" in
        --setup-file) setup_file="$2";; --dump) dump="$2";; --uploads) uploads="$2";; --key-file) key_file="$2";;
        --project) project="$2"; custom_project=1;; --port) port="$2"; custom_port=1;;
      esac
      shift 2;;
    --help|-h)
      echo 'Usage: bash install.sh --clean [--setup-file /private/setup.json] [--project NAME] [--port PORT]'
      echo '   or: bash install.sh --restore --dump /path/to/sql-folder [--uploads /original/storage/app]'
      echo '                       [--key-file /original/backend/.env] [--project NAME] [--port PORT]'
      echo 'Clean setup opens browser onboarding by default. --cli-setup uses terminal prompts; --setup-file uses private JSON.'
      echo 'For an existing older installation, use the separate patch package.'
      exit 0;;
    *) fail "Unknown argument: $1";;
  esac
done
unset APP_VERSION COMPOSE_FILE COMPOSE_PROJECT_NAME APP_PORT DB_PASSWORD DB_ROOT_PASSWORD
command -v docker >/dev/null || fail 'Install and start Docker Desktop first.'
command -v openssl >/dev/null || fail 'OpenSSL is required.'
docker compose version >/dev/null 2>&1 || fail 'Docker Compose is unavailable.'
[ "$(docker info --format '{{.OSType}}')" = linux ] || fail 'Docker must be running Linux containers.'
version="$(cat RELEASE_VERSION)"
case "$version" in ''|*[!a-zA-Z0-9_.-]*|[.-]*) fail 'Invalid release version.';; esac
# Verify the trusted package before creating credentials or importing anything.
while IFS= read -r line; do
  digest="${line%%  *}"; file="${line#*  }"
  case "$file" in ''|/*|*..*|*\\*) fail 'Unsafe manifest path.';; esac
  [ -f "$file" ] || fail "Missing installer file: $file"
  actual="$(openssl dgst -sha256 "$file")"; actual="${actual##* }"
  [ "$actual" = "$digest" ] || fail "Installer checksum mismatch: $file"
done < SHA256SUMS
fresh_config=0
if [ -f .env ] || [ -f runtime.env ]; then
  [ -f .env ] && [ -f runtime.env ] || fail 'Only one configuration file exists. Restore the matching file; credentials were not replaced.'
  saved_project="$(setting .env COMPOSE_PROJECT_NAME)"; saved_port="$(setting .env APP_PORT)"
  [ "$custom_project" = 0 ] || [ "$project" = "$saved_project" ] || fail 'Project differs from this installation.'
  [ "$custom_port" = 0 ] || [ "$port" = "$saved_port" ] || fail 'Port differs from this installation.'
  project="$saved_project"; port="$saved_port"
  [ "$(setting .env APP_VERSION)" = "$version" ] || fail 'This folder contains a different installed version. Use a patch, not this installer.'
  if [ -n "$key_file" ]; then
    [ "$(key_from "$key_file")" = "$(setting runtime.env APP_KEY)" ] || fail 'Supplied key differs from the installed key. No key was replaced.'
  fi
  echo 'Preserving installed credentials and application key.'
else
  fresh_config=1
fi
if [ -f .install-state/mode ]; then
  saved_mode="$(cat .install-state/mode)"
  [ -z "$mode" ] || [ "$mode" = "$saved_mode" ] || fail 'Cannot switch an installed database between clean and restore modes.'
  mode="$saved_mode"
fi
if [ -z "$mode" ]; then
  if [ -n "$dump" ]; then mode=restore
  elif [ "$fresh_config" = 0 ]; then mode=restore
  elif [ -t 0 ]; then
    read -r -p 'New client with no data, or restore a dump? [clean/restore] (clean): ' mode
    mode="${mode:-clean}"
  else fail 'Choose --clean for a new client or --restore --dump /path/to/dump.'
  fi
fi
case "$mode" in clean|restore) ;; *) fail 'Mode must be clean or restore.';; esac
[ -z "$setup_file" ] || setup_mode=cli
if [ -f .install-state/setup-mode ]; then
  saved_setup_mode="$(cat .install-state/setup-mode)"
  [ -z "$setup_mode" ] || [ "$setup_mode" = "$saved_setup_mode" ] || fail 'Keep the first-install setup method while completing this installation.'
  setup_mode="$saved_setup_mode"
fi
setup_mode="${setup_mode:-web}"

if [ "$mode" = clean ]; then
  [ -z "$dump$uploads$key_file" ] || fail 'Clean setup cannot import a dump, uploads or an original key. Use restore mode for existing client data.'
else
  [ -z "$setup_file" ] || fail '--setup-file is only for a new clean installation.'
fi
case "$project" in ''|*[!a-z0-9_-]*|[-_]*) fail 'Project must start with a lowercase letter/digit; use lowercase letters, digits, underscores or hyphens.';; esac
case "$port" in ''|*[!0-9]*) fail 'Port must be numeric.';; esac
[ "$port" -ge 1024 ] && [ "$port" -le 65535 ] || fail 'Use a port between 1024 and 65535.'
if [ "$fresh_config" = 1 ]; then
  [ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" ] || fail 'That project already exists. Choose another --project name or use its original installed folder.'
  [ -z "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ] || fail 'Volumes already exist for this project. Use the original installed folder; do not delete volumes.'
  [ -z "$(docker ps -q --filter "publish=$port")" ] || fail 'Port is already in use. Choose another --port value.'
fi
needs_import=0
if [ "$mode" = restore ] && [ ! -f .install-state/ready ] && [ ! -f .install-state/import-complete ]; then needs_import=1; fi
if [ "$needs_import" = 1 ]; then
  if [ -z "$dump" ] && [ -t 0 ]; then read -r -p 'Full path to the folder containing the SQL dump files: ' dump; fi
  [ -n "$dump" ] && [ -d "$dump" ] || fail 'Supply --dump /path/to/the/SQL-folder for the first installation.'
  dump="$(cd "$dump" && pwd)"; shopt -s nullglob; sql_files=("$dump"/*.sql)
  [ "${#sql_files[@]}" -gt 0 ] || fail 'No .sql files found in the dump folder.'
fi
if [ "$mode" = restore ] && [ "$fresh_config" = 1 ] && [ -t 0 ]; then
  if [ -z "$uploads" ]; then read -r -p 'Original storage/app folder, or Enter to skip missing uploads: ' uploads; fi
  if [ -z "$key_file" ]; then read -r -p 'Original backend .env file to preserve its key, or Enter for a new key: ' key_file; fi
fi
if [ -n "$uploads" ] && [ ! -f .install-state/uploads-complete ] && [ ! -f .install-state/ready ]; then
  [ -d "$uploads" ] || fail 'Uploads folder does not exist.'
  uploads="$(cd "$uploads" && pwd)"
fi
mkdir .installer-lock 2>/dev/null || fail 'Installer already running or stale .installer-lock. Check before removing an empty lock.'
started=0; finished=0
cleanup() {
  result=$?
  if [ "$finished" = 0 ] && [ "$started" = 1 ]; then
    docker compose stop app >&2 || true
    echo 'Installation stopped safely. Existing configuration and imported data were retained. Read README.md before retrying.' >&2
  fi
  rmdir .installer-lock 2>/dev/null || true
  exit "$result"
}
trap cleanup EXIT
if [ "$fresh_config" = 1 ]; then
  if [ -n "$key_file" ]; then app_key="$(key_from "$key_file")"; else
    app_key="base64:$(openssl rand -base64 32)"
    echo 'Generating a new application key. Existing encrypted data may require its original key.'
  fi
  db_password="$(openssl rand -hex 24)"; root_password="$(openssl rand -hex 24)"
  cat > .env <<EOF
COMPOSE_PROJECT_NAME=$project
APP_PORT=$port
APP_VERSION=$version
DB_PASSWORD=$db_password
DB_ROOT_PASSWORD=$root_password
EOF
  cat > runtime.env <<EOF
APP_NAME=YODAMEN
APP_ENV=production
APP_DEBUG=false
APP_KEY=$app_key
APP_URL=http://localhost:$port
APP_TIMEZONE=Asia/Manila
YODAMEN_TIMEZONE=Asia/Manila
DB_CONNECTION=mysql
DB_HOST=db
DB_PORT=3306
DB_DATABASE=yodamen_trial
DB_USERNAME=yodamen
DB_PASSWORD=$db_password
SESSION_DRIVER=database
SESSION_LIFETIME=360
SESSION_COOKIE=${project//-/_}_session
SESSION_DOMAIN=null
SESSION_SECURE_COOKIE=false
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=localhost:$port,127.0.0.1:$port
FRONTEND_URLS=http://localhost:$port,http://127.0.0.1:$port
FILESYSTEM_DISK=local
FILESYSTEM_STATE_DISK=local
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
LOG_LEVEL=warning
MAIL_MAILER=log
EOF
  mkdir -p .install-state
  printf '%s\n' "$mode" > .install-state/mode
  printf '%s\n' "$setup_mode" > .install-state/setup-mode
fi
chmod 600 .env runtime.env
docker compose config --quiet
if ! docker image inspect "yodamen-local:$version" >/dev/null 2>&1; then
  echo 'Building application image. Docker needs internet access for dependencies.'
  docker compose build app
fi
docker compose up -d --wait db
started=1
tables="$(docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" -N -s "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()"')"
if [ "$mode" = clean ]; then
  if [ "$tables" = 0 ]; then
    [ ! -f .install-state/ready ] || fail 'Completed installation has an empty database. Restore its backup.'
    touch .install-state/clean-started
  elif [ ! -f .install-state/clean-started ] && [ ! -f .install-state/ready ]; then
    fail 'Clean mode requires an empty database. Existing records were not changed.'
  fi
else
  if [ "$tables" = 0 ]; then
    [ -f .install-state/ready ] && fail 'A previously completed installation has an empty database. Restore its backup; do not reimport.'
    [ "$needs_import" = 1 ] || fail 'Imported database is unexpectedly empty. Investigate before retrying.'
    [ ! -f .install-state/import-started ] || fail 'Previous import did not finish. Review it manually; automatic retries are blocked.'
    touch .install-state/import-started
    for file in "${sql_files[@]}"; do
      echo "Importing $(basename "$file")"
      docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -u "$MYSQL_USER" "$MYSQL_DATABASE"' < "$file"
    done
    touch .install-state/import-complete
  elif [ ! -f .install-state/import-complete ] && [ ! -f .install-state/ready ]; then
    fail 'Database is not empty and no completed first import is recorded. Use the patch workflow or investigate a partial import.'
  fi
fi
if [ ! -f .install-state/ready ]; then
  if [ "$mode" = restore ] && [ ! -f .install-state/uploads-complete ]; then
    if [ -n "$uploads" ]; then
      # A producer-side tar failure must stop the install as well.
      upload_archive="$(mktemp "${TMPDIR:-/tmp}/yodamen-uploads.XXXXXX")"
      if ! tar cf "$upload_archive" -C "$uploads" .; then rm -f "$upload_archive"; fail 'Could not archive all uploads.'; fi
      if ! docker compose run --rm --no-deps -T --entrypoint sh app -c 'mkdir -p storage/app; tar xf - -C storage/app; chown -R www-data:www-data storage/app' < "$upload_archive"; then
        rm -f "$upload_archive"; fail 'Uploads restoration failed. Retry with the same uploads folder.'
      fi
      rm -f "$upload_archive"
    else
      echo 'No uploads folder supplied. Missing uploaded images will need to be restored separately.'
    fi
    touch .install-state/uploads-complete
  fi
  docker compose run --rm --no-deps -T app php artisan down --retry=60
  if [ "$mode" = restore ]; then docker compose run --rm --no-deps -T app php /var/www/html/verify-install.php --before-migrations; fi
  docker compose run --rm --no-deps -T app php artisan migrate --force
  if [ "$mode" = clean ]; then
    if ! docker compose run --rm --no-deps -T app php artisan yodamen:initialize --check; then
      if [ "$setup_mode" = web ]; then
        if [ ! -s .install-state/setup-token.txt ] || ! docker compose run --rm --no-deps -T app php artisan yodamen:setup-token --check; then
          openssl rand -hex 32 > .install-state/setup-token.txt
          chmod 600 .install-state/setup-token.txt
          docker compose run --rm --no-deps -T app php artisan yodamen:setup-token --from-stdin < .install-state/setup-token.txt
        fi
      elif [ -n "$setup_file" ]; then
        [ -f "$setup_file" ] || fail 'Private setup JSON file not found.'
        docker compose run --rm --no-deps -T app php artisan yodamen:initialize --input-json < "$setup_file"
      elif [ -t 0 ]; then
        docker compose run --rm --no-deps app php artisan yodamen:initialize
      else
        fail 'Run again in an interactive terminal to choose your business/admin details, or supply --setup-file /private/setup.json.'
      fi
    fi
    if [ "$setup_mode" = cli ]; then touch .install-state/clean-complete; fi
  fi
  docker compose run --rm --no-deps -T app php artisan optimize:clear
fi
if [ -f .install-state/ready ] && [ "$mode" = clean ] && [ "$setup_mode" = web ]; then
  if ! docker compose run --rm --no-deps -T app php artisan yodamen:initialize --check; then
    if [ ! -s .install-state/setup-token.txt ] || ! docker compose run --rm --no-deps -T app php artisan yodamen:setup-token --check; then
      openssl rand -hex 32 > .install-state/setup-token.txt
      chmod 600 .install-state/setup-token.txt
      docker compose run --rm --no-deps -T app php artisan yodamen:setup-token --from-stdin < .install-state/setup-token.txt
    fi
  fi
fi
if [ "$mode" = clean ] && [ "$setup_mode" = web ]; then
  docker compose run --rm --no-deps -T app php /var/www/html/verify-install.php --allow-pending-setup
else
  docker compose run --rm --no-deps -T app php /var/www/html/verify-install.php
fi
docker compose up -d --no-build app
if [ ! -f .install-state/ready ]; then docker compose exec -T app php artisan up; fi
if ! docker compose exec -T app php /var/www/html/check-health.php; then
  docker compose exec -T app php artisan down --retry=60 || true
  fail 'Health check failed. App closed; inspect docker compose logs app.'
fi
touch .install-state/ready
printf '%s\n' "$version" > .yodamen-installed-release
finished=1
if [ "$mode" = clean ] && [ "$setup_mode" = web ]; then
  if docker compose exec -T app php artisan yodamen:setup-token --check; then
    echo "Open http://localhost:$port/setup to create your business and Super Admin."
    echo "One-time setup code (private; valid for 24 hours): $(cat .install-state/setup-token.txt)"
  else
    # Clear the local plaintext token after the completed setup is observed.
    if [ -f .install-state/setup-token.txt ]; then : > .install-state/setup-token.txt; fi
  fi
fi
echo "YODAMEN is running at http://localhost:$port"
echo 'Complete first-time setup in your browser, or sign in with your chosen/imported account. Admin > Subscription displays activation details.'
echo 'Keep the setup code private. It is not a paid-plan activation code.'
echo 'Basic, Standard and Premium are permanent after activation. Unactivated Premium allows 50 activities.'
echo 'Backup: bash backup.sh. Use the separate patch package for later application updates.'
echo 'Other devices cannot connect yet; LAN access and Tailscale are separate configurations.'
