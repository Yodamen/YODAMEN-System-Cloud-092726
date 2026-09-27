# YODAMEN Update 100C — Retail Commission Test Compatibility

Purpose: Keep Update 100B's production inventory hardening intact while updating the older retail commission regression test to use an Inventory Masterlist product instead of a legacy free-text retail line.

Changes:
- `phase4RetailSale()` now creates an active inventory-backed RetailProduct and branch stock.
- Walk-in retail test payload now sends `retail_product_id`.
- Existing retail commission expectations remain unchanged.
- The commission test additionally confirms exactly one `RETAIL_SALE` inventory movement is posted.

No migrations. No production service files are changed.
