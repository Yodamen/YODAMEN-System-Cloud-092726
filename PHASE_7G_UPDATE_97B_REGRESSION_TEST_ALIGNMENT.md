# Phase 7G — Update 97B Regression Test Alignment

The 10 remaining failures were stale expectations after the approved inventory-control changes. This patch preserves production behavior and updates tests to exercise the current rules:

1. Opening-stock fixtures now submit and approve manual adjustment requests before expecting stock/movements.
2. The audit-count conflict test now approves the post-count adjustment so a real stock movement exists before audit approval.
3. The below-zero manual adjustment test now verifies request creation succeeds but approval is blocked.
4. Reorder point and movement quantity assertions account for four-decimal inventory precision.
5. Retail POS movement quantity assertion accounts for four-decimal inventory precision.
