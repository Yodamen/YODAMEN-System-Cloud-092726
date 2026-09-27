# YODAMEN Phase 6C — Cash Advances & Payroll Adjustments — Update 53

Adds employee Cash Advance master/ledger, Draft -> Active approval, repayments, deduction-per-payroll setup, and auditable Payroll Adjustment records.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6C-CASH-ADVANCES-ADJUSTMENTS-53.zip -d .
cd backend
php artisan migrate
php artisan test --filter=CashAdvanceTest
cd ../frontend
npm run build
```

Do not use `migrate:fresh`.
