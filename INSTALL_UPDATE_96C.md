# YODAMEN Update 96C - Inventory Masterlist Table + Filter Layout

Frontend-only update.

## Changes
- Inventory Masterlist table headers are now:
  - SKU / Barcode
  - Item
  - Category / Brand
  - UOM
  - Retail Price
  - Commission
  - Status
  - Action
- UOM uses Selling UOM when Sellable = Yes; otherwise Consumption UOM.
- Non-sellable Retail Price remains `-`.
- Filter widths rebalanced while keeping one desktop row:
  - Item Name and Category narrower
  - Branch, Stock Type, Commission, and Status wider
- Apply/Reset remain on the same desktop row.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-96C-INVENTORY-MASTERLIST-TABLE-FILTERS.zip -d .
cd frontend
npm run build
npm run dev
```

No migration or backend commands are required.
