# Update 92 - Sales Analytics, Customer Segments, HR and UI Refinements

## System-wide UI
- Action dropdown controls now use a fixed width so selecting a longer or shorter action does not resize table action buttons.
- Added subtle customer-segment tags where customer names are shown in core Operations and Sales tables.
- Customer segment logic:
  - New: 0-1 completed visit
  - Returnee: exactly 2 completed visits
  - Regular: 3-10 completed visits
  - Loyal: more than 10 completed visits

## Sales Dashboard
- Comparison notes now display percentage change with up/down/flat arrows and color coding.
- Added Client Visits metric with unique-customer subheadline.
- Replaced Sales by Channel with Sales Type: Services and Retail.
- Added Sales Mix: Services, Packages, Products.
- Added Customer Mix: New, Returnee, Regular, Loyal.
- Added Top Services, Top Retail Items, and Top Clients.
- Added dynamic Show Top selector: 5 / 10 / 20.
- All breakdowns show Count, Amount, and % Amount.
- Top clients show customer-segment tags.

## Cash Advances
- Transaction ID is now plain text; View Transaction remains in Action.
- Removed Purpose from the home table; Purpose remains inside transaction detail.
- Activity ledger now includes Processed / Approved By for issuance, repayments, payroll deductions, and write-offs.

## Leave Management
- Leave Balance employee selector now only lists personnel entitled to paid leave.
- Non-entitled personnel no longer expose leave-balance tables.
- Leave-balance table layout tightened.
- View button resized to text-appropriate compact width.

## Inventory Masterlist
- Strengthened one-row desktop filter layout so every filter plus Apply/Reset stays inside the same filter panel row.
- Reduced filter widths, especially Item Name.

No database migration is required for Update 92.
