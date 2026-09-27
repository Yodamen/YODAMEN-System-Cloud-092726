# YODAMEN Update 100K — Automatic Accounting & Remaining Accounting Modules

## Prerequisite
Update 100J must already be installed, including the Chart of Accounts seed and Petty Cash / Accounting Core migration.

## Included

### Service Consumption UI
- Consumption History now shows Booking ID as the headline and Consumption ID as the subheadline.
- Service Material Recipe modal expanded to 1480px maximum desktop width.
- Recipe columns remain in one desktop row; smaller screens can scroll horizontally.

### Automatic Operational Accounting
Operational events now feed the same accounting journal automatically. The operational module remains the source transaction; the journal is the derived accounting record.

Posting examples:

1. Finalized service or retail sale
   - DR Accounts Receivable — Customers
   - CR Service Revenue / Retail Product Sales

2. Package sale
   - DR Accounts Receivable — Customers
   - CR Deferred Package Revenue

3. Payment received
   - DR Cash on Hand / Cash in Bank / E-Wallets-POS Clearing
   - CR Customer Advances / Unapplied Receipts

4. Payment applied to a sale
   - DR Customer Advances / Unapplied Receipts
   - CR Accounts Receivable — Customers

5. Retail inventory sold
   - DR Retail Product Cost of Sales
   - CR Retail Products Inventory

6. Service material consumption
   - DR Chemicals Used — Services / Consumables Used — Services
   - CR corresponding Inventory account

7. Stock received
   - DR Inventory
   - CR Accounts Payable — Suppliers

8. Package session redeemed
   - DR Deferred Package Revenue
   - CR Package Revenue

9. Commission recognized
   - DR Commission Cost / Commission Expense
   - CR Accrued Commissions

10. Payroll approval/payment
   - Payroll approval accrues payroll and related obligations.
   - Payroll payment clears the payable to the selected cash/bank account.

11. Transaction corrections, reversals and refunds
   - Accounting history is preserved.
   - Counter-postings / adjustments are generated instead of deleting finalized journals.

12. Inventory audit adjustments
   - Shortages and surpluses post to the configured inventory variance / gain accounts.

Expenses and Petty Cash continue using the automatic journals introduced in Update 100J.

## Remaining modules activated
- Accounting → Accounts Receivable
- Accounting → Collections
- Accounting → Cash Flow Statement
- Accounting → Bank Reconciliation
- Accounting → Profit Sharing
- HR & Payroll → My Profit
- Admin → Accounting Mapping

Existing active accounting pages remain:
- Chart of Accounts
- General Ledger
- Trial Balance
- Cash Management
- Daily Closing
- Financial Statements

## Accounting Mapping
The Accounting Mapping page controls operational-to-account mappings, including:
- inventory product → inventory account
- inventory product → service COGS account
- retail product → retail COGS account
- payment method → cash/bank/POS clearing account

Default mappings are seeded from the YODAMEN COA and can be maintained from Admin → Accounting Mapping.

## Existing data: current-state accounting sync
After migration, run:

```bash
php artisan accounting:sync-current-state
```

This backfills accounting journals for existing current operational records such as finalized sales, received payments, active payment applications, inventory movements, commissions, payroll, cash advances, service-credit movements, and posted refunds.

The command is idempotent and can be rerun safely because accounting journal source keys prevent duplicate journals.

For existing development records, this produces the corrected current accounting state. It does not attempt to reconstruct every prior correction/reversal event in exact historical sequence. Future events are posted automatically as they occur.

## Installation

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K-AUTOMATIC-ACCOUNTING-REMAINING-MODULES.zip -d .

cd backend

php artisan optimize:clear
php artisan migrate
php artisan accounting:sync-current-state
php artisan test
```

Then build the frontend:

```bash
cd ../frontend
npm run build
```

If the Vite development server is not running:

```bash
npm run dev
```

If it is already running, hard-refresh Chrome with `Command + Shift + R`.

## Database
This update includes one migration for the operational accounting mappings, Bank Reconciliation, Profit Sharing, and required accounting control accounts.

## Dependencies
No new npm package is required.
