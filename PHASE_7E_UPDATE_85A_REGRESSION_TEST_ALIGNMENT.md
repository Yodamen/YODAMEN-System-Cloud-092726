Update 85A aligns legacy regression fixtures with the Update 85 inventory contract.

Fixes:
- InventoryControlTest now creates a valid supplier assigned to the product before posting Stock Received.
- Legacy receipt payloads in InventoryControlTest now use purchase_quantity, purchase_uom, conversion_factor, and purchase_unit_cost.
- RetailProductLookupTest now explicitly marks its directly-created test product as INVENTORY and retail_available=true, with matching UOM defaults.

This patch does not relax the production controls introduced by Update 85. Supplier selection and Purchase UOM conversion remain required for Stock Received, and only retail-enabled inventory items remain selectable for retail sale flows.

No migration is included.
