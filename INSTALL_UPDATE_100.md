# YODAMEN Update 100 — Final Transaction Flow

## Locked flow implemented

### Transaction actions
- Row-level transaction action is now **Correction only**.
- Correction is available both before and after Checkout.
- New Refund requests are disabled.
- Reversal is removed from row action menus and moved to a dedicated **Reversal** button at the upper-right of Sales > Transactions.

### Pending approvals
- The separate **For Approval** tab is removed.
- Pending Correction/Reversal requests appear in **Pending**.
- Authorized approvers see only **Approve** and **Reject** in the approval row action menu.
- Super Admin self-approval remains manual and audited.

### Correction waterfall
- Pre-Checkout approval updates the operational waterfall and then makes the corrected transaction the Checkout basis.
- Post-Checkout approval updates both operational and financial waterfalls.
- Quantity/removal corrections reconcile service material consumption and retail inventory where applicable.
- Service consumption effective quantity and effective total cost are updated while historical movement records remain traceable.
- Post-Checkout reduction creates Refund Due.
- Post-Checkout increase creates Balance Due.
- Commission, package/service-credit and operational source effects continue through the existing atomic posting engine.

### Booking retail inventory
- Retail products attached to a Booking/Visit are now deducted from inventory at Checkout finalization.
- The posting is idempotent: an existing RETAIL_SALE inventory movement prevents a second deduction.
- Insufficient retail stock blocks Checkout finalization rather than silently creating an invalid retail sale.

### Reversal workflow
- Dedicated Reversal button opens a Booking ID selector.
- User selects Booking ID, reason and mandatory remarks.
- The modal shows operational and financial waterfall preview before submission.
- A second confirmation appears before the request is submitted.
- Submission does not reverse anything immediately. It returns to Pending for approval.
- Once approved, the existing atomic posting engine reverses/counter-posts downstream effects.
- Reversed transaction goes to Transactions > Reversed.
- Linked Booking goes to Operations > Bookings > Others.
- Received payment history is preserved; active applications are counter-posted and collected value becomes Refund Due as applicable.

## Install

From Terminal:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100-TRANSACTION-FLOW-FINALIZATION.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
npm run dev
```

There is **no database migration** in Update 100. Do not run migrate, migrate:fresh, rollback, db:wipe, or DEVRESET for this update.

## Required smoke tests

1. Complete a Booking with a retail product and Checkout it. Confirm Inventory Movement gets RETAIL_SALE and stock decreases once.
2. Pre-Checkout Correction: change service quantity/staff or add/remove a service, submit, approve, then verify Checkout uses corrected values and operational effects are reconciled.
3. Post-Checkout Correction decrease: approve and verify service consumption/inventory operational effects adjust and Refund Due is created.
4. Post-Checkout Correction increase: approve and verify operational effects adjust and Balance Due is created.
5. Confirm there is no Refund or row-level Reversal action and no For Approval tab.
6. Submit a Correction or Reversal and verify it appears in Pending. Approver action menu should show Approve / Reject only.
7. Use the upper-right Reversal workflow. Confirm impact preview, confirmation prompt, approval flow, Transactions > Reversed destination, and Bookings > Others destination.
