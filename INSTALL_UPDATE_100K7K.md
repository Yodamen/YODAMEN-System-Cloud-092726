# YODAMEN Update 100K7K — General Ledger Date Contract Regression Fix

## Purpose
Restores the date-only `posting_date` response contract for the General Ledger endpoint after later update overlays reintroduced MySQL datetime serialization (`YYYY-MM-DD 00:00:00`).

The latest-first ordering remains unchanged.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7K-GL-DATE-CONTRACT-REGRESSION-FIX.zip -d .
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
```

If focused tests pass:

```bash
php artisan test
```

## Migration
No migration is required.
