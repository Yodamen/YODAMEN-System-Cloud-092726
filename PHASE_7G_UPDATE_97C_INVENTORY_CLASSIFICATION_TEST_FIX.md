# Phase 7G — Update 97C

The last failing regression test was asserting the legacy Asset-specific validation message from `RetailProductService::adjustStock()`.

Update 97 routes manual adjustments through `InventoryAdjustmentService`, which correctly blocks all non-quantity-tracked stock types with one canonical validation message. The test expectation is updated to that current contract.

Production behavior remains unchanged.
