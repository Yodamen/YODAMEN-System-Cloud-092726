# YODAMEN 100K7N10A — Customer Classification Migration Fix

Fixes MySQL migration failure caused by generated composite index names exceeding MySQL's 64-character identifier limit.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10A-CUSTOMER-CLASSIFICATION-MIGRATION-FIX.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
```

The migration is idempotent at the table-creation level, so rerunning is safe after the failed first attempt.
