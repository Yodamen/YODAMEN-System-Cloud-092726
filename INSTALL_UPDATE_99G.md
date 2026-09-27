# YODAMEN Update 99G — Booking Classification + Completed Service Correction

## Changes

### Bookings
- Tabs are now **Active / Completed / Others**.
- A Booking whose linked Sale has been fully **REVERSED** no longer appears under Completed.
- Reversed Bookings appear under **Others** with effective status **REVERSED**.
- Cancelled Bookings also appear under Others.
- Refund-only and ordinary Correction actions do not move a Booking out of Completed.

### Completed Service Correction
- Transaction Correction now follows the Servicing-style service management pattern.
- Existing completed lines can be corrected for quantity, assigned staff, unit price, or removed.
- A regular omitted service can be added to the completed Visit through Correction.
- Added services are only proposed until approval.
- Approval creates the missing completed Visit service line, Service Completion Event, Sale Item, and Service Material Consumption when an active recipe exists.
- Pre-Checkout: approved values become the Checkout basis.
- Post-Checkout: increases become Balance Due; reductions continue to become Refund Due.
- All Correction requests remain subject to approval. Super Admin self-approval remains manual, never automatic.
- Package / multi-session services cannot be inserted as a regular Correction line; they must use the Package / Service Credit workflow.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-99G-BOOKING-SERVICE-CORRECTION.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
```

There is **no database migration** in Update 99G. Do not run `migrate:fresh`, `rollback`, `db:wipe`, or DEVRESET.

## Smoke Test

1. Reverse a completed paid transaction and approve it.
2. Open Operations > Bookings.
   - It must not appear in Completed.
   - It must appear in Others with status REVERSED.
3. Complete a new service and open Sales > Transactions > Correction.
4. Test changing quantity/staff and submit for approval.
5. Test Add Service for a regular single-session service and submit for approval.
6. Approve the request.
   - Pre-Checkout: Checkout must use the corrected total.
   - Post-Checkout increase: Balance Due must appear.
   - Active service recipe: inventory consumption must post for the added completed service.
7. Confirm requester/approver audit history remains intact.
