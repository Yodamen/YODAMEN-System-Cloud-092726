Update 88 refines Inventory Masterlist, Supplier Masterlist, and Stock Received.

Inventory Masterlist
- Brand is now free-text instead of a dropdown.

Supplier Masterlist
- Adds required Code Name, intended as the compact supplier label used by other operational tables/dropdowns.
- Supplier Name remains the full legal/display name.
- Channel remains required.
- Store Link displays as "Visit" when present and "-" when empty.
- Embedded Add Item flow also uses free-text Brand.
- Supplier item selector remains the scrollable checkbox dropdown introduced in Update 87.

Stock Received
- "Delivery / PO / Invoice Ref" renamed to "Reference".
- Receive Stock modal widened and reorganized to prevent clipping.
- Supplier dropdown and stock-receipt table use supplier Code Name for compact display.
- Item dropdown shows Item Name only, not SKU code.
- Header adds Delivery Fee and live Total Amount (product cost only).
- Delivery Fee is persisted separately from product cost so Accounting can read it as its own source amount.
- Receipt API exposes total_cost, delivery_fee, and grand_total separately.
- Each received line shows Purchase Qty, Purchase UOM, Conversion Factor, Purchase Unit Cost on the main row.
- Basic Qty, Total Cost, Override Conversion, Save as Item Default, and Remove are arranged in one compact secondary row.

Migration
- Adds suppliers.code_name.
- Adds inventory_receipts.delivery_fee.
- Existing suppliers are backfilled with their current Supplier Name as initial Code Name so migration is non-destructive.
