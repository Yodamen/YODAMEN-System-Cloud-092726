# YODAMEN 100K7N14K — Excel / PDF Report Export Framework

## Scope
Adds a reusable no-dependency Excel/PDF download framework to reportable YODAMEN pages.

### Download behavior
- Excel produces a real `.xlsx` workbook.
- PDF produces a downloadable `.pdf` report.
- Exports the currently rendered/filtered report view.
- Visible summary cards are included as a Summary section/sheet.
- Visible data tables are included as report sections/sheets.
- Action/Actions columns are excluded automatically.
- Multiple visible report tables become separate Excel sheets and separate PDF sections.
- No database migration.
- No Composer or NPM package dependency added.

### Activated pages
- Customer List
- Customer Service Balances
- Sales Transactions
- Z-Reading
- Cash Handling
- Sales Dashboard
- Collections
- Expense Log
- Petty Cash Ledger
- Attendance
- Daily Compensation
- Commission Summary
- Cash Advances
- Payroll Register
- Payslips
- Inventory Masterlist
- Stock Received
- Stock Returns
- Service Consumption
- Inventory Audit Count
- Inventory Adjustments
- Chart of Accounts
- General Ledger
- Trial Balance
- Cash & Bank Balances
- Cash Flow Statement
- Financial Statements
- Accounts Receivable Aging
- Accounts Payable Aging

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14K-EXCEL-PDF-REPORT-EXPORT.zip -d .
cd frontend
npm run build
```

## Notes
This is frontend-only. It does not change accounting calculations, source data, posting logic, permissions, or database schema.

The exporter intentionally exports the current on-screen filtered report state. If a future module introduces server-side pagination, its export should be upgraded to a backend full-result export endpoint rather than exporting only the current page.
