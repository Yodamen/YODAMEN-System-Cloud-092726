# Update 87 - Inventory Control Refinements

Scope:
- Inventory Dashboard: added Sellable filter and tightened the filter row so all fields/actions remain in one desktop row.
- Inventory Masterlist: added Retail and Commission filters, moved Stock Type under Item Name, Basic UOM-only display, removed stock/reorder columns, removed Adjust Stock from item actions, and refined modal field alignment.
- Retail selling price now uses dated price records similar to Services pricing. Current and future prices resolve by effectivity date in Retail POS, Booking, Queue, and inventory valuation.
- Inventory Adjustments is now the only UI for manual stock correction. Manager/Super Admin receive ADJUST permission; the endpoint is also protected by inventory-adjustments.ADJUST.
- Supplier form: Supplier Name label, Channel required, scrollable checkbox dropdown for supplied items, Add Item button beside the dropdown.
- Regression fixtures updated for required Supplier Channel.

Migration:
2026_09_20_010000_inventory_pricing_and_adjustment_control

Notes:
- Existing retail product prices are backfilled into retail_product_prices using the product creation date where available.
- Legacy retail_products.selling_price remains as a compatibility/current-price cache, while operational selling flows resolve the dated price history.
