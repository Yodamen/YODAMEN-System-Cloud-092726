# Update 86 - Inventory Dashboard Consolidation + Movement Drilldown + Weighted Average Cost Control

## Inventory Dashboard
- Removes Reorder Point from the dashboard table.
- Adds a compact Sellable indicator using a checkmark or cross.
- Adds Current Weighted Average Cost per Basic UOM.
- All active quantity-tracked Inventory items are now listed even when they have no InventoryStock row yet; zero-stock items appear with zero balances.
- Inventory Cost Value uses On Hand quantity multiplied by current weighted average cost.
- Potential Retail Value remains limited to items marked Available for Retail and uses Available quantity multiplied by Selling Price.

## Item Movement Drilldown
- Product name/SKU is clickable from the Inventory Dashboard.
- Opens an item detail modal showing current On Hand, Available, weighted average cost, inventory cost, sellability, retail value, and the complete movement history for the selected branch.
- Movement history can be filtered by date and movement type.
- Each movement shows quantity before/after, quantity change, unit-cost snapshot, cost impact, reference, and reason.

## Inventory Movements module
- Removes the standalone Inventory Movements menu entry and frontend route.
- The underlying movement ledger/API remains available internally because Service Consumption, Adjustments, audit controls, and item drilldowns depend on the append-only movement ledger.

## Weighted Average Cost control
- Stock Received continues to recalculate RetailProduct.unit_cost using weighted-average costing.
- Retail POS finalization now refreshes the current weighted average cost at the actual stock-deduction event and writes that value into both the Sale Item cost snapshot and Inventory Movement cost snapshot.
- This prevents a draft sale from retaining a stale cost if a new receipt changes weighted average cost before checkout is finalized.

## Database
- No migration required.
