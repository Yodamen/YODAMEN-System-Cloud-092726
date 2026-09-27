# Update 90 - Inventory Approval and UI Refinements

## Inventory Dashboard
- Item-name-only SKU Name filter labels.
- Sellable display uses Yes/No instead of checkmark/cross.
- Movement-history modal widened and movement table forced to single-line cells.
- Pending inventory items are excluded from Inventory Dashboard until approved.

## Inventory Masterlist
- Desktop filters compressed into one horizontal row.
- Item-name-only filter options.
- Adds Pending Approval status/filter.
- Adds inventory-master.APPROVE permission.
- New items created by users without APPROVE are stored as PENDING_APPROVAL and do not become operational until approved.
- Changes submitted by non-approving users are held as pending proposals; current live values remain unchanged until approval.
- Approvers see Approve in the item action dropdown.
- Deactivation is disabled while any stock remains on hand/reserved, with backend enforcement.

## Supplier Masterlist
- Search replaced by Supplier Name dropdown.
- Added Channel dropdown filter.
- Selected-item count typography reduced.
- Embedded Add Item uses the same Inventory Masterlist item form structure and participates in the same approval workflow.

## Stock Received
- Receipt dates display in `Sept. 20, 2026` style.
- Total Amount label aligned with regular field labels; explanatory subtext removed.
- Receipt line layout forced into one row on desktop, including Remove.
- Basic Qty and Total Cost use field-style headers and aligned read-only boxes.

## Approval permissions
- Super Admin and Manager receive `inventory-master.APPROVE` by migration.
- Other roles can be granted the permission from Users & Roles.
