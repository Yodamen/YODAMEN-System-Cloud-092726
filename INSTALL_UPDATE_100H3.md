# YODAMEN Update 100H3
## Transaction + Inventory UI Cleanup

Prerequisite: install through Update 100H2 first.

### Included changes

1. Sales > Transactions > Reversed
   - Type now uses the system-wide status badge style.
   - Correction = blue informational badge.
   - Reversal = red danger badge.
   - Adds Approved By using the transaction action reviewer, with posting/reversal actor fallback for older records.
   - Renames Original Amount to Amount.
   - Removes the redundant Original amount subline.

2. Inventory Dashboard > Movement History
   - Reason column wraps instead of truncating long text.

3. Inventory Masterlist
   - Renames UOM column to Basic UOM.
   - Displays the item's Basic UOM instead of Selling/Consumption UOM.

4. Stock Received > Receive Stock
   - Removes the Override / Save Default controls from receipt entry.
   - Purchase UOM and Conversion come from Inventory Masterlist and remain read-only during receiving.
   - Remove button stays on the same desktop row as all receipt line fields.
   - Desktop receipt rows remain one row and scroll horizontally only when necessary.

5. Service Consumption
   - Date/time is displayed in full format, e.g. September 24, 2026, 11:03 PM.

### Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100H3-TRANSACTION-INVENTORY-UI-CLEANUP.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
```

If the Vite development server is not running:

```bash
npm run dev
```

If it is already running, hard-refresh Chrome with Command + Shift + R.

### Database

No migration is required.
