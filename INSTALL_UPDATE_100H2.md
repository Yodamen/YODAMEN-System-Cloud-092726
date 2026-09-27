# YODAMEN Update 100H2
## System-Wide Status Badges + Commission Reversal Display

This update builds on the current 100H/100H1 codebase.

### Commission Summary
- Removes full-row red highlighting from reversed commission rows.
- Keeps the Reversed metric card visually neutral like the other metric cards.
- Uses red only on the `REVERSED` status badge.
- Displays every Commission Summary row presented as `REVERSED` with a negative commission amount.
- Negative transaction-action commission adjustments are displayed as `REVERSED` in Commission Summary while their underlying ledger status remains `OPEN` when required for future Payroll processing.
- Keeps the original source commission and the recovery/adjustment entry auditable instead of deleting either record.
- Labels transaction-action commission rows as `Commission Adjustment` for clearer audit history.

### System-wide status badge standard
All existing `.status-pill` badges now use a common outlined pill treatment.

Semantic color direction:
- Green: positive/finalized states such as Active, Approved, Paid, Completed, Closed, Fully Paid, Booked.
- Red: negative/reversal states such as Reversed, Cancelled, Rejected, Refunded, Written Off, Negative Stock, Out of Stock.
- Amber: pending/attention states such as Pending, Submitted, Scheduled, On Account, Reopened.
- Blue: informational/in-progress states such as Waiting, Servicing, In Progress, Corrected.
- Gray: neutral/inactive/draft states.

The update also aligns status mappings in Bookings, Queue, Servicing, Attendance, Payroll, Payslips, Cash Advances, Inventory Count, Retail POS, Commission Rules, Discount Rules, and Services Pricing.

### Database
No migration is required.

### Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-UPDATE-100H2-SYSTEM-WIDE-STATUS-BADGES.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
```

If the Vite dev server is not running:

```bash
npm run dev
```

If it is already running, hard-refresh the browser after the build:

`Command + Shift + R`

### Validation notes
The PHP service file was syntax-checked successfully. All JavaScript/JSX source files in the patched project were parsed successfully before packaging.
