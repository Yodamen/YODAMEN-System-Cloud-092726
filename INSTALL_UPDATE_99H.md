# YODAMEN Update 99H — Transaction Request Code / DEVRESET Integrity Fix

## Fixes

1. Transaction action request codes now skip already-used `TAR-*` values when the sequence counter is behind the historical table.
2. DEVRESET now clears `transaction_action_request_lines` and `transaction_action_requests` before resetting transactional code sequences.
3. Test-only reset now clears transaction action requests linked to test-customer sales.
4. Regression coverage added for stale sequence recovery and transaction-action reset cleanup.

## Why this was needed

A transaction action row could survive DEVRESET while the transactional code sequence was reset. The next Correction/Refund/Reversal request could then try to reuse `TAR-...-000001`, causing a MySQL unique-key error.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-99H-TRANSACTION-SEQUENCE-RESET-FIX.zip -d .

cd backend
php artisan optimize:clear
php artisan test
```

No migration is included. Do not run `migrate:fresh`, `rollback`, `db:wipe`, or DEVRESET just to install this patch.

After tests pass, retry the same Correction request. The allocator should skip any retained TAR code and create the next available code.

## Frontend startup

This patch does not change the frontend. To run the frontend:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run dev
```

`npm run build` is a production build check. `npm run lint` reports lint debt and is not the command that starts Vite.
