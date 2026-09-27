# YODAMEN Update 100K6 — Promotions, Automatic Refunds & Incentive Rules

## What this update changes

- Renames the Admin-facing **Discount Rules** module to **Promotion Rules** while preserving the existing API/module key for compatibility.
- Enables **stacked promotion codes** at Checkout and Retail POS.
  - Up to 8 unique promotion codes per checkout.
  - Codes are applied left-to-right against the remaining amount.
  - Each promotion can be marked **Stackable** or exclusive.
  - Existing single `discount_code` API payloads remain supported.
  - Full promotion application snapshots are stored per sale line so later corrections can recalculate the same stacked promotion logic.
- Repairs/refines refunds on discounted/promoted transactions.
  - Refunds use the line's final **effective net amount after promotions**, never the gross amount.
  - Refund request amount and quantity are derived server-side as a whole-line refund.
  - Manual refund amount/quantity editing is removed from the UI.
  - Existing Refund Due from a prior approved correction/reversal is settled automatically.
  - Refund review UI shows Gross, Promotion/Discount, stacked promotion breakdown when applicable, and Automatic Refund.
- Adds **Admin → Incentive Rules**.
  - Metrics: Sale Net, Service Net, Retail Net, Item Count.
  - Scope: all assigned personnel or a specific personnel record.
  - Reward: fixed amount or percentage.
  - Effective dates, threshold, priority, status, and stackable/exclusive behavior.
  - Incentives are reconciled automatically from financially finalized sales and counter-adjusted after corrections/reversals without rewriting approved/paid payroll history.
  - Incentive earnings feed the existing payroll earning pipeline.
- Adds system COA **6006 Staff Incentives** and posts automated incentive accruals there rather than mixing them into service/retail commission expense.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K6-PROMOTIONS-REFUNDS-INCENTIVES.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
```

## Focused tests

```bash
php artisan test tests/Feature/Discounts/DiscountRuleTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
```

Then run the full backend suite:

```bash
php artisan test
```

Build frontend:

```bash
cd ../frontend
npm run build
```

If using the Vite development server, restart it when needed:

```bash
npm run dev
```

## Suggested manual verification

1. Create two active Promotion Rules for the same service, e.g. `VIP20` = 20% and `BDAY10` = 10%, both Stackable.
2. Checkout a ₱500 service using `VIP20, BDAY10`.
3. Expected sequential result: ₱500 - ₱100 - ₱40 = **₱360 net**, total promotion = **₱140**.
4. Open Refund for that paid line. The amount must be automatic at **₱360**. Staff must not type a refund amount or quantity.
5. Approve/process the refund through the mapped payment method. Cash should reduce by ₱360, while the Income Statement preserves gross revenue, promotion contra-revenue, and sales return/refund so net revenue resolves correctly.
6. Create an Incentive Rule, finalize an eligible sale, then verify an `INCENTIVE` earning appears in Commission/Payroll earning data and its accounting accrual uses **6006 Staff Incentives / 2003 Accrued Commissions**.
