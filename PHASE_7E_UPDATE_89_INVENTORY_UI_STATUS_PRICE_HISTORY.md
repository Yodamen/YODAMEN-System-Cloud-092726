# Update 89 — Inventory UI, Stock Status, Price History

## Inventory Dashboard
- Added Out of Stock status and dashboard card.
- Added Inactive SKUs dashboard card.
- Stock status precedence: Inactive -> Out of Stock -> Low Stock -> OK.
- Added Out of Stock and Inactive to the Status filter.
- Dashboard continues to expose weighted average cost and sellability.

## Inventory Masterlist
- Removed Unit Cost from the masterlist table because weighted average cost is already presented on Inventory Dashboard.
- Removed effectivity-date subline below Retail Price in the table.
- Added View action with item detail and historical selling-price log.
- Enlarged the item modal and tightened field layout.
- Available for Retail is now a Yes/No selector instead of a checkbox.
- Tightened filter widths so all filters plus Apply/Reset remain on one desktop row.

## Supplier Masterlist
- Store Link now normalizes links without http/https so Visit opens the actual external site.
- Enlarged supplier modal.
- Tightened supplier item picker typography and layout.

## Stock Received
- Receipt ID is plain text; received date is shown as its subheadline.
- Added Received By to the receipt log using the user who posted the receipt.
- Removed the Received Items heading/helper copy from the receive modal.
- Moved Add Item below the line-item grid.
- Reworked receipt lines into a compact single-row desktop layout.
- Aligned Total Amount with the other header fields.
- Tightened receipt-log columns for one-row display.

No database migration is required.
