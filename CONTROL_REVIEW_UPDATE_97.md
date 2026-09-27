# YODAMEN Control Review — Update 97

This review covered the currently implemented Operations, Sales, Inventory, HR & Payroll, Administration/Permissions, DEVRESET, and the existing Accounting foundation. Expense and the broader Accounting engine are not yet complete transactional modules, so they are not treated as completed control environments here.

## Controls closed in Update 97

1. **Manual inventory adjustment bypass**
   - The legacy product adjustment endpoint previously allowed a privileged direct stock posting path.
   - It now always creates a Pending Adjustment Request and requires the approval workflow.

2. **Fractional inventory truncation**
   - Several inventory-facing APIs still cast quantity balances to integers.
   - They now preserve four-decimal quantities required by service consumption.

3. **Negative stock visibility**
   - Negative balances could be hidden by zero-clamping in operational views.
   - Dashboard now exposes actual negative balances, a NEGATIVE STOCK status, filter, and summary count.

4. **Negative-stock weighted-cost distortion**
   - Standard WAC math should not treat a negative quantity as a negative cost layer.
   - A receipt against zero/negative stock now establishes the current WAC basis using the incoming receipt Basic UOM cost.

5. **Deactivation with unresolved deficit**
   - An SKU cannot be deactivated while any branch has a non-zero On Hand/Reserved balance, including negative stock.

6. **Permission gap**
   - Customer service-credit lookup now requires Booking VIEW permission.

7. **Decimal-control consistency**
   - Audit counts, reorder points, manual adjustments, receipt serialization, and weighted unit costs now preserve fractional quantities/costs where required.

## High-priority controls recommended next

### 1. Enforce maker-checker separation
Current permission design allows a user who holds both create/edit and approve permissions to approve their own transaction in several workflows. Confirmed examples include Manual Stock Adjustments, Inventory Counts, Cash Advances, Leave approvals, and Payroll approval.

Recommended control: configurable `requester/creator cannot approve own transaction`, with any Super Admin override requiring a reason and a dedicated audit event.

### 2. Make Z-Reading a real financial close
Z-Reading currently records a Closed snapshot, but Sales/Payments do not enforce the branch/date as locked afterward.

Recommended control: once a branch/date is CLOSED, block new or back-dated financial mutations into that date until an authorized Reopen is performed.

### 3. Add Stock Receipt correction/reversal
Stock Received posts inventory immediately. There is no dedicated immutable reversal/correction workflow.

Recommended control: either Draft → Post approval or a `Reverse Receipt` action requiring reason, authority, and linked reversing movements. Original receipt should never be deleted.

### 4. Route “Save conversion as item default” through item-master control
Receipt-level conversion override is valid for the receipt, but saving that conversion directly to the Product Master can alter future operations without the Product Master approval process.

Recommended control: receipt override may be used immediately for that receipt; `Save as Item Default` should create an item-master pending change unless the actor has explicit item approval authority.

### 5. Version and approve Service Material Recipes
Recipes now create real inventory deductions, so immediate recipe edits have operational impact.

Recommended control: recipe versions with Effective From, Draft/Pending/Approved/Inactive status, approver, and a snapshot/version reference on every Service Consumption posting.

### 6. Add completed-service reversal/correction
A completed service now produces package/service-credit effects, completion events, commissions downstream, and material consumption. There is no general operational reversal flow for an incorrectly completed service.

Recommended control: authorized `Reverse Completion` before financial finalization, and an immutable correction/reversal flow after financial finalization. The reversal should create opposite inventory movements rather than deleting history.

### 7. Define negative-inventory cost true-up policy before full Accounting COGS integration
Update 97 preserves the WAC snapshot known when the service was completed. If stock was already negative and the actual replacement cost arrives later, historical consumption is not retroactively re-costed.

Recommended accounting decision: either keep immutable operational cost as posted, or create an explicit `Negative Inventory Cost True-Up` journal/COGS adjustment when the replenishment cost becomes known. Avoid silently rewriting historical consumption.

### 8. Add privilege-ceiling controls to Users & Roles
A user with user/permission management rights can currently select active roles and configure permissions without an explicit rule preventing privilege escalation beyond their own authority.

Recommended control: only Super Admin may assign a Super Admin role; non-Super Admin administrators may grant only roles/permissions within their own authority ceiling.

### 9. Add Sales refund/void/reversal workflow
Current Sales routes contain checkout/payment finalization but no dedicated immutable refund/void reversal workflow covering all downstream effects.

Recommended control: original Sale/Payment stays immutable; authorized reversal creates linked negative/reversal records and reverses payment applications, commissions, package/service-credit effects, and retail inventory as applicable.

### 10. Add Audit Count variance thresholds
Audit Count approval can post any submitted variance after the stock-snapshot concurrency check.

Recommended control: require a reason above a quantity/value threshold and optionally require second-level approval for material variances.

## Medium / low-effort improvements
- Add an AuditService event to Supplier activate/deactivate toggles.
- Add controlled Customer Merge for duplicate customer records while preserving all historical links.
- Add branch/date close checks to back-dated inventory and HR-financial postings where period locking becomes necessary.
- Add alerting/escalation for SKUs that remain negative beyond a configurable number of hours/days.
