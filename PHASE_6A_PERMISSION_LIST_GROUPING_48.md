# YODAMEN Phase 6A - Permission List Grouping 48

## Purpose
Refines Admin > Users & Roles > Manage Permissions for faster role setup.

## Changes
- Replaces permission cards with a compact list/table-style layout.
- Groups permission modules by the same functional sections used by the left sidebar:
  - Main
  - Operations
  - Sales
  - HR & Payroll
  - Accounting
  - Inventory
  - Reports
  - Administration
- Keeps hidden/future permission modules inside their correct functional section.
- Orders actions consistently: View, Create, Edit, Complete, Approve, Mark Paid, Toggle, Reset Code, Manage Branches, Change Price, Manage Permissions.
- Keeps all existing permission codes and backend behavior unchanged.
- Responsive layout stacks module/action rows on small screens.

## Files replaced
- frontend/src/pages/admin/UsersRolesPage.jsx
- frontend/src/pages/admin/users-roles.css

## Migration
None.

## Backend changes
None.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-6A-PERMISSION-LIST-GROUPING-48.zip -d .
cd frontend
npm run build
```

## Regression check
Open Admin > Users & Roles > Roles & Access > Manage Permissions.
Confirm that modules are grouped by system section and permission saving still works.
