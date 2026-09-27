# YODAMEN Phase 5B Discount Regression Fix 39

This update restores two Phase 5B behaviors that were accidentally overwritten by the Discount Rules update while retaining all new discount fields and package gross/net snapshots.

## Restored behavior

1. Service Credit availability now subtracts active Manage/Visit claims that are not backed by a Booking Service Line. This restores the rule that an active claimed Service Credit session is reserved before completion.
2. Package payment history again exposes the Booking lineage for each payment application. The financials response can therefore show the original Booking ID/code for package payments.

## Discount behavior retained

- Package gross price snapshot remains preserved.
- Package net price snapshot remains the discounted receivable value.
- Sale Item discount fields remain unchanged.

No database migration is required.
