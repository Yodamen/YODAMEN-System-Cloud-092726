# YODAMEN Update 100H
## Commission Reversal Audit + Booking/Transaction History Routing

### Scope

1. **Commission Summary reversed color coding**
   - Reversed commission rows use the red danger treatment across the full row.
   - The Reversed metric card is also red-coded.
   - Historical source commissions tied to Sale Items removed/reversed by an approved transaction action are presented as `REVERSED` without destructively changing their stored payroll status.
   - Transaction-action commission adjustment rows remain active according to their stored status so future payroll counter-entries are preserved.

2. **Bookings > Others**
   - Cancelled bookings remain in Others.
   - Whole-transaction reversals remain in Others.
   - A completed booking with any Sale Item whose `correction_status` is `REMOVED` or `REVERSED` now moves from Completed to Others.
   - These partially corrected bookings display parent status `CORRECTED`.
   - Original booking service lines remain visible for audit. Service lines tied to removed/reversed Sale Items display `Removed by Correction` / `Reversed` in red.

3. **Sales > Transactions > Reversed**
   - Whole reversed transactions remain in Reversed.
   - Individual Sale Items removed or reversed by an approved Correction now also appear in Reversed.
   - Removed/reversed items are excluded from Paid, On Account, and Service Credit tabs so there is one audit destination for non-effective lines.
   - Existing correction history, original amount/quantity, Refund Due, and request reference remain available through the existing transaction row resource.

### Payroll behavior

This update does not rewrite historical payroll.

If a commission was already included in an Approved/Paid payroll and a later approved transaction action reduces or eliminates that commission, the existing transaction-action posting logic:

- preserves the original Approved/Paid payroll and payslip,
- calculates the commission difference,
- creates a linked commission `ADJUSTMENT` entry for the delta,
- dates the adjustment on the transaction-action posting date,
- leaves the adjustment available for a later payroll run.

Example: an already-paid commission of PHP 100 is fully invalidated later. The old payroll remains PHP 100 historically, while a new PHP -100 commission adjustment is created for a later payroll.

### Installation

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100H-COMMISSION-REVERSAL-HISTORY.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
```

If the frontend dev server is not running:

```bash
npm run dev
```

If it is already running, hard-refresh Chrome after the build:

`Command + Shift + R`

### Migration

No database migration is included.

### Smoke checks

#### A. Released commission then reversal/removal
1. Use a transaction whose commission already belongs to an Approved/Paid payroll.
2. Approve a Correction that removes the commissioned line, or reverse the whole transaction.
3. Confirm the historical payroll/payslip is unchanged.
4. Confirm the source commission is shown as Reversed in Commission Summary and is red-coded.
5. Confirm the transaction action created the commission delta/counter-entry for future payroll processing.

#### B. Booking history
1. Complete a booking with two or more service lines.
2. Approve a Correction that removes one line.
3. Refresh Operations > Bookings.
4. The booking should no longer be under Completed.
5. It should appear under Others with status `CORRECTED`.
6. The removed service should remain visible and show `Removed by Correction`.

#### C. Transaction history
1. Open Sales > Transactions > Reversed.
2. The removed line should appear there with its original amount/history.
3. Confirm it no longer appears under Paid / On Account / Service Credit.
4. Whole reversed transactions must continue to appear in Reversed.
