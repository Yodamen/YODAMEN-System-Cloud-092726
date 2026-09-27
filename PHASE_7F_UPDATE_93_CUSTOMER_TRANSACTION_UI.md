# Update 93 - Inventory Filter, Sales Top Selector, Retail Payment & Customer Profile

## Inventory Masterlist
- Rebalanced desktop filter widths.
- Item Name is narrower; Branch, Stock Type, Category, Commission and Status receive more usable width.
- All filters plus Apply/Reset remain on one desktop row.

## Sales Dashboard
- Removed the separate "Show Top" label.
- Top selector is now a compact dropdown with Top 5, Top 10, Top 20 and Top 30.

## Customer Type
- Booking and Walk-In forms show Customer Type as read-only.
- Customer Type is automatically selected from completed visit history supplied by the booking/queue bootstrap: no prior completed visit = NEW, at least one completed visit = RETURNEE.
- Customer profile segmentation remains system-computed as New / Returnee / Regular / Loyal.

## Transactions / Retail POS
- Retail POS payment applications are now included in the Paid transaction Payment column even though Retail POS sales have no Booking record.
- Unidentified Retail POS customers display as "Walk-in Customer" in Transactions and Retail Sales History.

## Customer Profile Drilldown
- Clicking a customer name in Customers opens a profile drilldown.
- Includes customer type, lifetime value, outstanding balance, completed visits, active service balance count, transaction count, profile details, remaining service/package balances, and complete services/packages/products transaction history.
- View Profile was also added to the customer Action menu.

No database migration is required.
