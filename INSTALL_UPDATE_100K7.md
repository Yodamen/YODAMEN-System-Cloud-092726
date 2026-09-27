# YODAMEN Update 100K7
## Customer Payment Methods vs Business Funding Sources + Stock Receiving UX + Branded Confirmations

Install this update on top of 100K6.

### What this update changes

1. **Separates customer Payment Methods from internal Funding Sources**
   - Customer Payment Methods remain under Admin → Accounting Mapping and may be marked **Show to Customers**.
   - Booking, Queue, Checkout, Retail POS, Collections, and Refund validation only accept active mapped customer-facing methods.
   - Expense, Stock Received, and Accounts Payable now use **Funding Sources** from Chart of Accounts instead of the customer Payment Method list.
   - Chart of Accounts gets an **Expense / Supplier Funding Source** flag.
   - Cash/bank/e-wallet accounts remain valid funding sources. Owner's Capital is enabled as an internal funding source but is not a customer tender.

2. **Stock Received settlement redesign**
   - Desktop header row: Branch | Date Received | Supplier | Reference | Payment Status.
   - Payment Status shows only Paid / Unpaid.
   - Each item stays on one desktop row; Remove is directly after Total Cost.
   - Totals are below the item table, followed by payment/funding lines and Balance.
   - Partial funding is allowed when status is Unpaid. Any remaining Balance is posted to Accounts Payable.
   - Paid requires payment/funding total to equal Grand Total.

3. **Expense and AP funding workflow**
   - Expense and Accounts Payable screens select internal Funding Sources directly.
   - Owner-paid business costs can post against Owner's Capital without exposing Owner's Capital to customers.
   - Legacy payment-method API payloads remain supported for compatibility.

4. **Booking promotion helper cleanup**
   - Removes the stacked-promotion instructional sentence from the checkout/booking discount area.

5. **Branded confirmations**
   - Replaces the remaining native browser confirmation dialogs in Inventory Adjustments, Audit Count, and Transaction Action review with the system confirmation modal.

6. **Sales Transaction table spacing**
   - Rebalances Booking, Customer, Service, Status, Amount, Payment, Balance, Approved By, and Action widths across Pending, On Account, Paid, Service Credit, and Reversed tabs.
   - Right-side financial/action columns are given more usable desktop space and horizontal scrolling is retained for smaller screens.

### Migration

This update includes:

`2026_09_25_002000_separate_customer_payments_and_funding_sources.php`

It adds:
- `chart_of_accounts.is_funding_source`
- `accounting_payment_method_mappings.customer_facing`

Existing cash accounts are enabled as funding sources. Owner's Capital is enabled as an internal funding source. Obvious Owner/Capital/Petty Cash payment-method labels are hidden from customers by default.

### Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7-PAYMENT-SOURCE-UX-CONFIRMATIONS.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate

php artisan test tests/Feature/Accounting/FundingSourceSeparationTest.php
php artisan test tests/Feature/Accounting/ExpenseModuleTest.php
php artisan test tests/Feature/Accounting/AccountsPayableTest.php
php artisan test tests/Feature/Inventory/InventoryControlTest.php
php artisan test

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

### Recommended configuration check

After migration:
- Admin → Accounting Mapping: keep Cash, GCash, BDO, card/clearing methods enabled under **Show to Customers** as appropriate.
- Accounting → Chart of Accounts: use **Expense / Supplier Funding Source** for accounts that may legitimately fund expenses or supplier receipts, such as BDO, GCash, Petty Cash, or Owner's Capital.
- Do not create Owner's Capital as a customer Payment Method. It is an internal funding source.

### Accounting examples

**Customer pays via GCash**
- GCash is a customer Payment Method mapped to the GCash cash/clearing COA.

**Company pays supplier via BDO**
- BDO COA is selected as the Funding Source.

**Owner personally pays a business expense**
- DR Expense / Asset
- CR Owner's Capital

**Partial stock receipt payment**
- DR Inventory / Freight for full receipt
- CR selected Funding Source(s) for amount already paid
- CR Accounts Payable for the remaining Balance

### Validation performed in packaging environment

- PHP syntax validation passed for all changed PHP files and new tests.
- Source-wide native `window.confirm`, `window.alert`, and `window.prompt` scan is clean after the confirmation changes.
- Full Laravel tests and React build must run on the local project because the handoff workspace used for packaging does not contain `backend/vendor` or `frontend/node_modules`.
