# YODAMEN Phase 3A Parity Correction 04

This correction is applied on top of the current Phase 3A development baseline.

## Included

- Customer filter bar rebuilt to match the approved Apps Script layout: Search, Status, Birth Month, Last Visit, Lifetime Value, Reset.
- Customer table column widths normalized and Lifetime Value alignment corrected.
- Queuing visible references simplified to Booking ID; Queue ID removed from the table and Start Servicing confirmation.
- Start Servicing uses a branded YODAMEN modal and shows Booking ID, Customer, Services, and Source.
- Pending Transactions uses Booking ID as the single visible transaction reference.
- Pending Transactions replaces Sale/Items with Services and shows actual service names vertically.
- Pending Transactions renames Payments Received to Payment.
- Checkout is rendered as a compact gold boxed action button.
- Transaction table column widths normalized.
- Pending transaction API now includes service names in the parent Sale payload.

## No schema change

No database migration or seeder is required.
