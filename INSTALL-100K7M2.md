# YODAMEN 100K7M2 — Inventory Funding Source Assertion Alignment

This patch changes one regression-test expectation only.

The production InventoryControlService already correctly requires a paid stock receipt's mapped account to be an active Expense / Supplier Funding Source. The old test still expected the previous Cash / Payment Account wording.

No production behavior, accounting rule, database schema, or migration is changed.
