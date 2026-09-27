# Update 96 - UOM Mapping and Cost Foundation

This update formalizes Basic UOM as the stock-ledger unit and maps Purchase, Selling, and Consumption UOMs back to it.

## Inventory Item Master
- Purchase UOM Cost added.
- Basic UOM Cost is automatically derived as Purchase UOM Cost / Purchase Conversion.
- Purchase UOM, Selling UOM, and Consumption UOM each have their own conversion-to-Basic factor.
- Existing Weighted Average Cost remains operational and separate from the default Basic UOM Cost.
- Existing products are backfilled safely to 1:1 Selling/Consumption mappings.
- Supplier embedded Add Item uses the same fields.

## Stock Received
- Purchase Unit Cost now defaults from the configured Purchase UOM Cost rather than reconstructing it from weighted cost.

## Retail POS
- Selling UOM conversion is active for stock availability and stock deduction.
- Example: Basic UOM g, Selling UOM jar, Selling Conversion 500 means sale quantity 1 deducts 500 g.
- Sale items snapshot the Selling UOM, conversion factor, and converted Basic quantity for transaction history stability.

## Service Consumption Recipe
- Recipe quantity is now entered in the item's Consumption UOM.
- The recipe automatically calculates Basic quantity and estimated material cost from the Consumption-to-Basic factor.
- Recipe lines snapshot the Consumption UOM and factor so historical recipes do not silently change if the item master is remapped later.

## Boundary
Automatic inventory deduction on service completion is not enabled in this update. Update 96 prepares the UOM/cost foundation needed for that next workflow.
