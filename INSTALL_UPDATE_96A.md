# YODAMEN Update 96A — Final Sidebar Order

Frontend-only update.

Final sidebar order:
1. Dashboard
2. Operations
3. Sales
4. Expense
5. HR & Payroll
6. Inventory
7. Accounting
8. Reports
9. Admin

The old "Main" label is hidden so Dashboard is the first visible navigation entry.
"Administration" is renamed to "Admin" in the sidebar.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-96A-FINAL-SIDEBAR-ORDER.zip -d .

cd frontend
npm run build
npm run dev
```

No backend migration is required.
