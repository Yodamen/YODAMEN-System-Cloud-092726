# YODAMEN Phase 7A – Booking Product Dropdown Cleanup 66

## Change
Booking → Retail Purchases → Product dropdown now displays only the Product Name.

Removed from the visible option text:
- SKU
- Selling Price
- Stock Count

The selected Product ID, SKU, selling price lookup, stock information, and commission/inventory logic remain unchanged in the background.

## Files
REPLACE EXISTING:
- `frontend/src/pages/operations/BookingsPage.jsx`

## Migration
None.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-7A-BOOKING-PRODUCT-DROPDOWN-CLEANUP-66.zip -d .
cd frontend
npm run build
```
