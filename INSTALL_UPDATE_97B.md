# YODAMEN Update 97B — Regression Test Alignment

## Scope
Aligns the remaining 10 failing regression tests with the current Update 97 inventory behavior:
- manual stock adjustments are maker-checker requests (201 PENDING) and must be approved before stock changes;
- invalid negative manual deductions are rejected at approval;
- DECIMAL(…,4) inventory values are asserted numerically rather than as integers.

No production application logic is changed.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-97B-REGRESSION-TEST-ALIGNMENT.zip -d .
cd backend
php artisan optimize:clear
php artisan test
```

No migration and no frontend build are required.
