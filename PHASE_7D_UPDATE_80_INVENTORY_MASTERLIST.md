# Update 80 — Inventory Masterlist Structure & Classification

## Approved scope
- Rename Product Masterlist to Inventory Masterlist.
- Keep the Inventory Masterlist filter row horizontal on desktop, including Apply and Reset.
- Put Stock Type first in item classification.
- Support Inventory, Non-Inventory, and Asset stock types.
- Make Category options depend on Stock Type.
- Expose Inventory Category, Non-Inventory Category, and Asset Category under Administration → Reference Lists.
- Preserve the existing product categories as Inventory categories.
- Hide Selling Price, Reorder Point, and Commission Eligibility for Non-Inventory and Asset items.
- Force non-tracked items to zero selling price/reorder point and false commission eligibility in the backend.
- Exclude Non-Inventory and Asset records from tracked inventory dashboard/reorder calculations and Retail POS controls.
- Preserve Inventory Movements as the full stock ledger; Adjustments remain the manual-correction subset.

## Default reference categories added
### Non-Inventory
- Office Supplies
- Cleaning Supplies
- Pantry Supplies
- Marketing Materials
- General Consumables
- Other Non-Inventory

### Asset
- Barber Equipment
- Furniture & Fixtures
- Computer & IT Equipment
- Tools & Equipment
- Appliances
- Other Asset
