# YODAMEN 100K7N12 — Payroll Draft Source-Change Stale Guard

This update is based on the fresh 2026-09-27 authoritative source ZIP and SQL database dump.
It does not install or activate the held 100K7O statutory contribution engine.

## Purpose

Closes a payroll-control loophole found during the fresh-source audit.

The existing payroll recovery engine already handled:
- pre-payroll commission/incentive adjustments,
- post-payroll recovery ledger deductions,
- automatic recovery pickup,
- carry-forward where available Net Pay is insufficient,
- Draft stale control when a new recovery/open payroll source appears.

This patch additionally makes a Draft payroll stale when an operational Booking transaction Correction/Reversal is POSTED after the Draft was calculated, even when that transaction does not create a commission/recovery record.

This matches the approved rule that payroll recalculation must be required based on the relevant booking correction/reversal event itself, rather than only on whether the affected service happens to have a commission rule.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N12-PAYROLL-DRAFT-STALE-GUARD.zip -d .

cd backend
php artisan optimize:clear
```

No new migration is included in this patch.

## Targeted tests

```bash
php artisan test tests/Feature/Hr/PayrollRecoveryTest.php
php artisan test tests/Feature/Hr/PayrollTest.php
```

## Full regression

```bash
php artisan test
```

Use the actual result as the new accepted baseline. Do not assume an expected pass count from older patches.

## Frontend build

No frontend files are changed by this patch. A frontend build is therefore not required for 100K7N12 itself.

## Fresh-source audit notes

The fresh source/database already contains the previously approved implementations for:
- Payroll Recovery Ledger and automatic carry-forward recovery controls.
- Manual Inventory Adjustment reversal.
- Stock Received full reversal request/approval.
- Stock Received item-level correction request/approval.
- Inventory > Stock Returns.
- Expense reversal request/approval and reversing journal.

Those existing implementations were preserved and were not blindly reinstalled.
