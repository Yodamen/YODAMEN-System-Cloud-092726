# YODAMEN Update 95B — DEVRESET Test Fixture Fix

## Scope
Fixes the DEVRESET regression test introduced in Update 95A. The test expected a personnel record to exist after the standard seeders, but those seeders do not create personnel.

## Changes
- Creates an explicit retained Personnel setup record inside `DevelopmentResetServiceTest`.
- Uses the Eloquent `Personnel` model so `full_name` is available.
- Keeps the FK regression scenario intact: `commission_earnings -> sale_items`.
- Production DEVRESET service logic is unchanged.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-95B-DEVRESET-TEST-FIX.zip -d .
cd backend
php artisan optimize:clear
php artisan test
```

No migration or frontend rebuild is required.
