# YODAMEN Update 100K7D — General Ledger Date Serialization Fix

## Purpose
Fixes the remaining 100K7C regression test failure in `AccountingReportingCorrectionsTest`.

The General Ledger ordering was already correct (latest posting first). The failure was only an API serialization mismatch: MySQL/query-builder returned `posting_date` as `YYYY-MM-DD 00:00:00`, while the accounting report contract and regression test expect `YYYY-MM-DD`.

## Change
- General Ledger keeps `posting_date` as a date-only string (`YYYY-MM-DD`).
- Latest-first sorting from 100K7C remains unchanged.
- No migration.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7D-GL-DATE-SERIALIZATION-FIX.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
```

If focused tests pass:
```bash
php artisan test
```
