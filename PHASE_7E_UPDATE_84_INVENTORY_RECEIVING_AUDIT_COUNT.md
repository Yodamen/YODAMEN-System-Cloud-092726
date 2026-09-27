# Update 84 - Inventory Receiving and Audit Count

This update replaces the placeholder/filter-only Stock Received and Inventory Count views with operational inventory controls.

## Stock Received
- New Receive Stock workflow.
- Records branch, date received, supplier, delivery/PO/invoice reference, notes, items, quantity, and acquisition unit cost.
- Posting a receipt immediately increases On Hand.
- Creates append-only STOCK_RECEIVED inventory movements per line.
- Keeps a permanent stock receipt header and line history.
- Updates the product's current unit cost using weighted-average cost based on existing On Hand plus the new receipt.
- Receipt records are clickable for line-level traceability.

## Audit Count
- New Start Audit Count workflow with Full Count, Selected Category, or Selected Items scope.
- Creates a count-session snapshot of the system quantity at start.
- Draft counts operate as blind counts: system quantity and variance remain hidden while physical quantities are entered.
- Save Draft preserves partial count progress.
- Submit reveals System Qty, Actual Count, and Variance for review.
- Approve & Post Variance changes On Hand to the approved actual quantity and creates INVENTORY_COUNT_ADJUSTMENT movements for differences.
- Approval is blocked if stock changed after the count session started, preventing valid sales/receipts from being overwritten by an old physical-count snapshot.
- Draft or Submitted sessions can be cancelled without posting stock changes.

## Permissions
- Stock Received: VIEW, CREATE.
- Audit Count: VIEW, CREATE, EDIT, APPROVE.
- Super Admin and Manager are granted the operational permissions during migration. Custom roles can be configured through Users & Roles.

## Service Consumption
Service Consumption remains a trace view in this update. The next inventory package should add service-consumption recipes/BOM and automatic deductions from completed service transactions. This is intentionally separated because service consumption requires decimal-quantity inventory support and integration with Services & Pricing plus Servicing completion.
