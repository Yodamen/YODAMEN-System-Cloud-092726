# YODAMEN Update 99D — MySQL Identifier Recovery

## Purpose
Repairs Update 98/99 migrations for a live MySQL database where the transaction action tables were partially created but Laravel never recorded the migration as completed.

The confirmed failure was MySQL error 1059: an auto-generated foreign-key identifier exceeded MySQL's 64-character identifier limit.

## What 99D changes
- Gives transaction-action and refund foreign keys explicit short names.
- Gives long/critical indexes explicit short names.
- Makes the pending migrations safe against the already-created partial tables.
- Repairs missing foreign keys/indexes on the partial MySQL tables instead of recreating them.
- Keeps the SQLite test path free of MySQL `information_schema` repair logic.
- Keeps Update 99 posting-schema repair foreign-key names under MySQL's 64-character limit.
- Does not wipe, truncate, rollback, or reset application data.

## Install
From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-99D-MYSQL-IDENTIFIER-RECOVERY.zip -d .
cd backend
php artisan optimize:clear
php artisan migrate
```

Then verify:

```bash
php artisan migrate:status | grep -E "transaction_action|posting_schema"
php artisan test
```

Finally verify the live Update 99 columns:

```bash
/usr/local/mysql-8.4.11-macos15-arm64/bin/mysql -u root -p -D yodamen_dev -e "
SHOW COLUMNS FROM sale_items LIKE 'effective_net_line_total';
SHOW COLUMNS FROM sales LIKE 'effective_net_amount';
SHOW COLUMNS FROM service_consumptions LIKE 'effective_service_quantity';
"
```

Do not use `migrate:fresh`, `migrate:rollback`, `db:wipe`, or DEVRESET for this recovery.
