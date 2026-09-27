# Update 79C – Non-Inventory Message Alignment

- Keeps the existing non-inventory stock-adjustment block.
- Changes only the DomainException message to: `Non-inventory items do not use stock adjustments or quantity balances.`
- No database migration.
- Expected result: the remaining RetailProductTest assertion passes.
