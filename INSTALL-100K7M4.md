# YODAMEN 100K7M4 — System UI Consistency Fix

Frontend-only update. No database migration.

## Included
- Removes the Remaining Service Value explanatory helper from Opening Balances > Active Packages.
- Moves Cash Deposits navigation under Sales, immediately after Z-Reading.
- Adds `/sales/cash-deposits`; the old `/accounting/cash-deposits` URL redirects safely.
- Restores Action menus as normal non-typeable dropdowns.
- Keeps true selection dropdowns searchable/typeable.
- Prevents awkward dropdown-option wrapping and gives long menus more room.
- Raises branded error/success/info alerts above active modals.
- Normalizes field borders, radius and focus styling system-wide.
- Updates Checkout Promotion Code, Amount, Reference No. and allocation inputs to match the current Payment Method field treatment.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7M4-SYSTEM-UI-CONSISTENCY-FIX.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No `php artisan migrate` is required.

Because this is frontend-only, the Laravel test count is not expected to change. If the previous backend regression has not yet been rerun after 100K7M3, run it separately from `backend` with `php artisan test`.
