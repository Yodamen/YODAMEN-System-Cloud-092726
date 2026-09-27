# YODAMEN Update 81 — Cash Advance UX, History & Completion Status

No database migration is required.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-81-CASH-ADVANCE-UX-HISTORY.zip -d .
cd backend
php artisan optimize:clear
php artisan test
```

Then rebuild/restart the frontend:

```bash
cd ../frontend
npm run build
npm run dev
```

If the backend server needs restarting, use a separate terminal:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan serve --port=8001
```

## Expected behavior

- Repayment modal uses two columns: summary cards on row 1, amount/date on row 2, Notes full width, actions right-aligned.
- Write-off modal uses two summary cards, full-width mandatory reason, actions right-aligned.
- Cash Advance transaction code is clickable and opens a complete ledger/payment history.
- Manual repayments and payroll deductions are counted as payments.
- Write-off is shown separately and is not counted as a payment.
- User-facing statuses are Draft, Active, Fully Paid, and Written Off.
- Status filters support Fully Paid and Written Off separately.
