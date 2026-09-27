# YODAMEN Update 100K5A — Accounting Local Date Fix

## Purpose
Fixes the accounting frontend date boundary bug where the browser used UTC (`toISOString`) while Laravel/YODAMEN posts using Asia/Manila.

Between 12:00 AM and 7:59 AM Philippine time, the frontend could send the previous calendar date, hiding same-day posted journals from:
- General Ledger
- Trial Balance
- Cash Management
- Cash Flow Statement
- Accounting operational pages that default to Today
- Petty Cash

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100K5A-ACCOUNTING-LOCAL-DATE-FIX.zip -d .
cd frontend
npm run build
```

If the Vite dev server is running, restart it after installation if it does not hot reload:
```bash
npm run dev
```

## Migration
No database migration is included.

## Verification
At a Philippine-local date boundary, the default To/As Of dates on Accounting pages should equal the local calendar date. Refresh General Ledger, Trial Balance, Cash Management, and Cash Flow and confirm the newest posted transaction is visible.
