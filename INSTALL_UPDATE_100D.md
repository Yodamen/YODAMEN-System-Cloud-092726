# YODAMEN Update 100D — Reversal Reporting, Commission Status, Service Consumption Status & UI

## Scope

1. Reversed transactions are excluded from effective `Payments Received` in Sales Dashboard and live Z-Reading reporting while the original payment records remain preserved for audit.
2. Commission Summary presents every commission entry tied to a reversed Sale as `REVERSED` without destructively rewriting historical payroll state. Reversed commission amounts are shown in red.
3. Reversal preview shows the actual commission amount affected instead of only a commission-entry count.
4. Reversal preview visual hierarchy is cleaned up: labels remain emphasized while values, amounts, destinations, IDs, and status values use normal font weight.
5. Service Consumption history now shows `CONSUMED`, `PENDING`, or `REVERSED`.
   - `PENDING`: linked Sale has an unposted Correction/Reversal request.
   - `REVERSED`: whole Sale reversal or the specific service consumption was removed/reversed by an approved correction.
   - `CONSUMED`: effective consumption remains active and no action request is pending.

## Installation

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100D-REVERSAL-REPORTING-STATUS-UI.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
npm run dev
```

No database migration is included in this update.

## Required smoke checks

### A. Reversal reporting
1. Note a paid Booking's payment amount.
2. Note Sales Dashboard `Payments Received` and Z-Reading `Payments Received`.
3. Submit and approve the whole Booking reversal.
4. Refresh both reports.
5. The reversed transaction/payment must no longer contribute to effective `Payments Received`.
6. The original Payment record must still exist in transaction/payment history for audit.

### B. Commission Summary
1. Open HR > Commission Summary after approving a reversal.
2. Every commission row belonging to that reversed Sale must display `REVERSED`.
3. Commission amounts on those rows must display in red.
4. Historical payroll source status remains preserved internally; the displayed status follows the reversed source transaction.

### C. Reversal preview
1. Open Sales > Transactions > Reversal.
2. Select a Booking.
3. Operational Waterfall must show `Commission amount reversed` in peso value, not an entry count.
4. Values and destination paths should be normal font weight while labels remain emphasized.

### D. Service Consumption status
1. For a normally completed service, Consumption History shows `CONSUMED`.
2. Submit a Correction or Reversal request affecting its Sale but do not approve it yet: status shows `PENDING` with the action request reference.
3. Reject the request: status returns to `CONSUMED`.
4. Approve a correction that removes that service, or approve whole transaction Reversal: status shows `REVERSED`.
