# YODAMEN Update 99C - Pending Migration Recovery

Purpose: repair the Update 98/99 migration-history mismatch where transaction action tables already exist in MySQL but Laravel still reports the migrations as Pending.

This patch replaces only these migration files:
- backend/database/migrations/2026_09_21_001000_create_transaction_action_controls.php
- backend/database/migrations/2026_09_21_002000_activate_transaction_action_posting_engine.php
- backend/database/migrations/2026_09_22_000100_repair_transaction_action_posting_schema.php

The 001000 migration is now safe when its tables already exist. It verifies required columns instead of attempting to recreate the table.
The 002000 migration is now safe after a partial earlier attempt and defers existing-table column repair to 000100.
The 000100 migration retains the 99B MySQL/SQLite-safe per-column repair logic.

## Install

From Terminal:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-99C-PENDING-MIGRATION-RECOVERY.zip -d .
cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status | grep -E "transaction_action|posting_schema"
php artisan test
```

Do not run migrate:fresh, migrate:rollback, db:wipe, or DEVRESET.

## Verify live MySQL schema

```bash
/usr/local/mysql-8.4.11-macos15-arm64/bin/mysql -u root -p -D yodamen_dev -e "SHOW COLUMNS FROM sale_items LIKE 'effective_net_line_total'; SHOW COLUMNS FROM sales LIKE 'effective_net_amount'; SHOW COLUMNS FROM service_consumptions LIKE 'effective_service_quantity';"
```

All three fields should appear.
