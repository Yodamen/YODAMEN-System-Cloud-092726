# YODAMEN Phase 5B - Service Line Transaction Split 33

## Purpose
Finalized Transactions tabs now classify each Sale Item / service line independently instead of forcing the entire Booking into one finalized tab.

## Rules
- Pending remains Booking-level because Checkout is still being finalized.
- Paid finalized lines appear in Paid.
- On Account finalized lines appear in On Account.
- Pure Service Credit lines appear in Service Credit.
- A Service Credit line that has a Booking-level payment applied to its source package appears in On Account while the source package has a balance, then Paid when that package balance reaches zero.
- The same Booking ID may therefore appear in more than one finalized tab when its service lines have different financial outcomes.
- Each finalized row represents one service/item line and retains the Booking ID as the universal operational reference.
- Payment amount on a finalized row includes only payment applications from that Booking that belong to that specific line (or, for Service Credit, its source package line).

## Files
- backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php

## Migration
None.
