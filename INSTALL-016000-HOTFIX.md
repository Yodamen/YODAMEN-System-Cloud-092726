# YODAMEN 016000 MySQL Identifier Hotfix

Run from the YODAMEN project root after unzipping:

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php scripts/repair_016000_mysql_identifiers.php
php artisan optimize:clear
php artisan migrate
```

The repair script:
- backs up the original migration,
- adds explicit short MySQL-safe foreign-key/index names,
- removes only partially created NEW tables declared by the failed 016000 migration when that migration is not yet recorded as complete,
- leaves already-completed migrations untouched.

After migration succeeds, run targeted inventory/expense tests before the full suite.
