# YODAMEN Update 100K1 — Accounting Journal Service Fix

## Purpose
Update 100K introduced automatic operational accounting calls to `AccountingJournalService::postEntry()` and `AccountingJournalService::accountByKey()`, but the distributed 100K ZIP did not include the updated `AccountingJournalService.php` containing those methods.

This caused operational workflows that create Payments and other accounting-aware records to fail with HTTP 500 during tests.

## Files
- `backend/app/Services/Accounting/AccountingJournalService.php`

## Database
No migration is required.

## Install
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100K1-ACCOUNTING-JOURNAL-SERVICE-FIX.zip -d .
cd backend
php artisan optimize:clear
```

Run the directly affected Sales suite first:

```bash
php artisan test tests/Feature/Sales/SalesCoreTest.php
```

Then run all backend tests:

```bash
php artisan test
```

## Expected behavior
- Operational workflows no longer fail because `accountByKey()` is undefined.
- `postEntry()` posts balanced automatic journals when all account mappings exist.
- If required accounting mappings are not configured in a test or operational setup, automatic journal posting safely no-ops instead of blocking the originating workflow.
- Journal posting remains idempotent by source type + source id + source event.
