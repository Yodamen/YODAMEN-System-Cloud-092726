# Update 82 — Personnel, Accounting Posting, and Sales Dashboard Refinements

## Included changes

### Personnel / HR
- Field values inside input/select/textarea controls use regular font weight system-wide.
- Personnel modal fields remain aligned when one field contains helper text.
- Profit Sharing % accepts whole numbers and decimals from >0 through 100.
- Compensation Effective From can be future-dated. Approved/Paid payroll periods remain locked; the effective date must still be after the latest locked payroll period that includes the personnel.
- OT calculation now honors any positive configured multiplier, including values below 1.00.

### Cash advance write-off accounting
- Adds source-linked, idempotent accounting journal foundation.
- A cash advance write-off automatically creates a balanced journal entry:
  - Debit: Cash Advance Write-Off Expense (`CASH_ADVANCE_WRITE_OFF_EXPENSE` mapping key)
  - Credit: 1102 Staff Cash Advances
- The debit account intentionally uses a semantic mapping key without inventing a final numeric COA code. The Accounting Mapping module can assign the approved expense code when the full accounting module is activated.
- Write-off journal source key prevents duplicate posting for the same cash advance write-off.

### Sales Dashboard
Adds the legacy dashboard information missing from the React dashboard while keeping current reporting:
- Gross Sales
- Total Collected
- Outstanding
- Transactions
- Customer Breakdown (New / Returnee)
- Total Client Visits and unique customers
- Average Booking Value
- Average Customer Lifetime Value
- Previous-period comparisons for the main metrics
- Sales Mix (Services / Packages / Products)
- Payment Type amounts and percentages
- Top 10 Services
- Top 20 Customers
- Existing Sales by Channel and Top Items remain available

## Reporting definitions
- Average Booking Value = Gross Sales / finalized transactions in the selected period.
- New Customer = selected-period customer whose first completed visit falls inside the selected period.
- Returnee = selected-period customer with a completed visit before the selected period.
- Average Customer Lifetime Value = collected lifetime value of customers appearing in the selected period, averaged across those customers.
- Comparison period = immediately preceding period with the same number of calendar days.
