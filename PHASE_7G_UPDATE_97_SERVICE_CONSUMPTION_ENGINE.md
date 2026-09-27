# Phase 7G — Update 97: Service Consumption Engine

## Operational trigger
- Service completion is the posting event.
- An ACTIVE Service Material Recipe is read for the completed service.
- Services without an active recipe complete normally without a consumption posting.
- One service completion line can create only one service-consumption posting.

## UOM conversion
The inventory ledger remains in Basic UOM.

Consumption mapping follows the current rule:
- `1 Basic UOM = N Consumption UOM`
- Basic quantity consumed = Consumption quantity / Conversion factor

Example:
- Basic UOM = Vial
- Consumption UOM = ml
- `1 Vial = 8 ml`
- Recipe consumption = `4 ml`
- Inventory deduction = `0.5 Vial`

## Negative stock policy
Operational service completion is never blocked solely because material stock is insufficient.
- `On Hand` may become negative.
- The exact before/after quantity is preserved in Inventory Movements and Service Consumption History.
- Inventory Dashboard exposes a `NEGATIVE STOCK` status and negative-stock SKU count.
- Later Stock Received transactions naturally offset the deficit.
- Positive approved manual adjustments may offset a deficit in stages.
- Manual negative adjustments cannot create or deepen negative stock.
- Audit Count can replace the system balance with the approved physical count.

## Costing
- Service consumption snapshots the Weighted Average Cost in Basic UOM at the time of completion.
- Quantity and unit-cost precision support fractional Basic UOM usage.
- Weighted Average Cost and relevant cost snapshots now support four decimal places.
- When a receipt arrives while stock is zero/negative, the incoming Basic UOM receipt cost becomes the current WAC basis instead of blending a negative quantity as a negative cost layer.
- Historical service-consumption cost remains immutable; no retroactive cost true-up is performed in this update.

## Reliability / controls included
- Service consumption is inside the same database transaction as service completion.
- Stock rows are locked before deduction.
- Recipe materials are locked/processed in deterministic product-ID order to reduce deadlock risk.
- Repeat completion calls cannot double-deduct a service line.
- Inactive/non-inventory recipe materials cause a clear recipe configuration error.
- Legacy direct manual-stock-adjustment endpoint can no longer bypass the Pending → Approve/Reject workflow, including for Super Admin.
- Items with unresolved positive, reserved, or negative stock cannot be deactivated.
- Decimal inventory quantities are preserved by Dashboard/POS/Booking/Queue APIs instead of being truncated to integers.
- Reorder Point, Audit Count, and Manual Adjustment inputs support fractional Basic UOM quantities.
- DEVRESET clears generated service-consumption transactions but preserves Service Material Recipes.
