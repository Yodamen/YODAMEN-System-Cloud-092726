# YODAMEN 100K7N10K — Sidebar Exact Highlight Consolidation

Frontend-only safety patch.

This patch consolidates the current sidebar order with the exact active-item matcher so only one submodule is highlighted at a time.

It fixes specifically:
- Customers: Customer List no longer remains highlighted on Service Balances or Customer Mix.
- Expense: Expense Log no longer remains highlighted on Petty Cash.
- Any future nested sidebar path uses the most-specific matching route.

It preserves the current Sales order:
Transaction → Retail POS → Cash Handling → Z-Reading → Dashboard.

No migration. No backend changes.
