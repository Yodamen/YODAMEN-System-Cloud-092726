Update 94A fixes the InventoryApprovalAndRecipeTest fixture.

Inventory items already create an InventoryStock row for active inventory branches. The Update 94 test incorrectly attempted to insert a second row for the same branch + retail product, violating the unique constraint.

The test now uses updateOrCreate for the existing stock balance and sets On Hand to 10 before testing the pending-adjustment approval flow. Production adjustment logic is unchanged.
