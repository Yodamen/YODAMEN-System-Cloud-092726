# Update 94 - Adjustment Approval, Permissions UX, Navigation, Service Recipes

## Inventory Adjustments
- Manual stock adjustments are submitted as Pending requests first.
- Stock is not changed until an authorized approver approves the request.
- Approval posts the actual inventory movement and before/after balance.
- Rejection requires a reason.
- New permissions: inventory-adjustments.CREATE and inventory-adjustments.APPROVE.
- Super Admin retains the legacy direct-adjust endpoint only as an emergency override; the normal UI uses the approval workflow.

## Users & Roles
- Permission groups are collapsible.
- Added search + clear controls inside the permissions window.
- Search matches section, module, and permission/action labels.

## Navigation
- Accounting is positioned before HR & Payroll.
- New Expense section created.
- Expense Log and Petty Cash moved out of Accounting and into Expense.

## Service Consumption
- Service Consumption is now a functional Service Material Recipe builder instead of a movement-log placeholder.
- One recipe per service.
- Recipe lines select tracked Inventory items and define quantity per completed service in Basic UOM.
- Shows current weighted average cost and estimated material cost.
- New permissions: service-consumption.CREATE and service-consumption.EDIT.

## Important next step
This update builds the recipe master data foundation only. Automatic deduction from inventory when a service is completed is intentionally not activated yet. That should be the next step after confirming recipe behavior and decimal/basic-UOM rules.
