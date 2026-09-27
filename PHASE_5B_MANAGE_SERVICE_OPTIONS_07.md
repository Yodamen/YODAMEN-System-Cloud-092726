# YODAMEN Phase 5B - Manage Service Options 07

## Purpose
When the user clicks Manage in Servicing, the Manage window now directly contains the active-visit transaction options instead of requiring separate entry points.

## Manage window
- Service card
  - Add Service inline
  - Quantity
  - Assigned Staff
  - Existing regular services remain editable for staff and retain Complete / Cancel actions
- Service Credit card
  - Claim Service Credit inline
  - Available Credit
  - Session quantity fixed to one per claimed visit service line
  - Assigned Staff is selectable during claim
  - Claimed Service Credit lines retain editable staff plus Complete / Cancel actions
- Product Purchase card
  - Add Product Purchase
  - Edit Product, Qty, Unit Price
  - Remove Product Purchase
  - Save Product Purchases before service completion

## Backend behavior
- Existing active-visit Add Service endpoint now also accepts service_credit_id.
- Service Credit claims created during Servicing preserve Package Enrollment / Service Credit lineage.
- Claimed Service Credit is reserved while its active Visit service line is unfinished and consumed only on Service Completion.
- New endpoint saves Visit retail purchases while the Visit is still IN_SERVICE.
- No database migration is required.

## Validation
- PHP syntax was checked for all changed backend files.
- Two feature tests were added for Manage-time Service Credit claim and Manage-time Product Purchase save.
- Full Laravel tests and frontend lint/build must be run locally because vendor and node_modules are intentionally absent from the shared snapshot.
