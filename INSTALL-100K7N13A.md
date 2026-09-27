# 100K7N13A — Payroll Recovery Test Fixture Completion

Purpose:
- Complete the N12/N13 payroll stale-guard regression fixture so it satisfies the existing `transaction_action_requests` schema.
- Add the required `original_snapshot` and `sale_version_hash` fields to the synthetic POSTED correction created by the test.
- Production payroll and commission logic are unchanged by this patch.
- The N13 Commission Integrity Guard remains in force.
- 100K7O statutory contribution engine remains untouched/on hold.

No database migration is included.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N13A-PAYROLL-RECOVERY-TEST-FIX.zip -d .

cd backend
php artisan optimize:clear
php artisan test tests/Feature/Hr/PayrollRecoveryTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php --filter="does not duplicate commission"
php artisan test
```

Expected result:
- The prior SQLite NOT NULL failure for `transaction_action_requests.original_snapshot` is removed.
- The fixture also supplies the schema-required `sale_version_hash`, preventing the next NOT NULL failure from surfacing after `original_snapshot` is fixed.
