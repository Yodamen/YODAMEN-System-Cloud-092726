#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
umask 077
unset APP_VERSION COMPOSE_FILE COMPOSE_PROJECT_NAME APP_PORT DB_PASSWORD DB_ROOT_PASSWORD
[ -f .env ] && [ -f runtime.env ] || { echo 'Run from the installed folder.' >&2; exit 1; }
backup="backups/$(date +%Y%m%d-%H%M%S)-$$"
mkdir -p "$backup"
docker compose exec -T app php -r 'if(is_file("storage/framework/down")){fwrite(STDERR,"App already in maintenance. Complete its update first.\n");exit(1);}'
docker compose exec -T app php artisan down --retry=60
# Restore the previous open state after backup, including after a backup error.
trap 'docker compose exec -T app php artisan up' EXIT
docker compose exec -T db sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u "$MYSQL_USER" --single-transaction --no-tablespaces "$MYSQL_DATABASE"' > "$backup/database.sql"
[ -s "$backup/database.sql" ]
docker compose exec -T app tar czf - -C /var/www/html/storage app > "$backup/uploads.tar.gz"
tar -tzf "$backup/uploads.tar.gz" >/dev/null
cp .env runtime.env compose.yaml "$backup/"
if [ -f compose.override.yaml ]; then cp compose.override.yaml "$backup/"; fi
docker compose config --images > "$backup/images.txt"
touch "$backup/BACKUP-COMPLETE.txt"
echo "Backup saved: $backup. Contains private credentials and business data; keep an off-machine copy."
