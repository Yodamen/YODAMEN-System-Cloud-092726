# YODAMEN Update 100K3D — Correction / Refund / Financial Reporting Repair

## What this fixes

1. **Sales Returns & Refunds no longer increase income.**
   - Income statement lines are now signed by financial-statement direction.
   - `4003 Sales Returns & Refunds` and other debit-normal contra-revenue accounts reduce Net Revenue.
   - The original Service Revenue journal remains preserved for audit history; the contra-revenue line is shown as a negative amount and Net Revenue is the resulting net figure.

2. **Refund workflow is re-enabled end to end.**
   - REFUND is accepted by request validation.
   - REFUND reasons and `sales.REFUND` permission handling are restored in the transaction-action service.
   - Reversed/corrected lines with Refund Due can be opened for Refund directly from the Refund Due amount in Transactions → Reversed.

3. **Refund payment methods now come from Accounting Mapping.**
   - Refund Method options use active Payment Methods that have an active Cash / Payment Account mapping.
   - A mapped BDO, GCash, bank, cash, or clearing account is therefore used consistently for refunds.

4. **Refunds post to the exact mapped cash account.**
   - Text refund-method snapshots are resolved through `accounting_payment_method_mappings` before generic fallback accounts are used.
   - Actual approved Refunds now reduce the selected mapped cash/bank/e-wallet account and therefore flow into Cash Management and Cash Flow Statement.

5. **Cash semantics clarified in the UI.**
   - Correction/Reversal can create Refund Due without moving actual cash.
   - Cash changes only when the separate approved Refund is posted.

## Accounting behavior

Example: ₱1,000 service was fully collected, then ₱300 is removed by Correction.

Correction / reversal accounting:

```text
DR Sales Returns & Refunds        300
    CR Accounts Receivable            300

DR Accounts Receivable            300
    CR Customer Advances / Unapplied  300
```

Cash remains unchanged at this stage. The ₱300 is now Refund Due / unapplied customer money.

When the approved Refund is actually paid through BDO:

```text
DR Customer Advances / Unapplied  300
    CR Cash in Bank - BDO             300
```

Cash Management and Cash Flow Statement then decrease by ₱300.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K3D-CORRECTION-REFUND-FINANCIAL-REPORTING.zip -d .

cd backend
php artisan optimize:clear

php artisan test tests/Feature/Accounting/AccountingReportingCorrectionsTest.php
php artisan test tests/Feature/Accounting/OperationalAccountingFoundationTest.php
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php

php artisan test

cd ../frontend
npm run build
```

No database migration is included in this update.

## Test scenario after install

1. Complete and financially finalize a service sale.
2. Collect payment using a mapped BDO/GCash/Cash method.
3. Approve a Correction that removes part of the service, or approve a full Reversal.
4. Check Financial Statements:
   - original Service Revenue remains visible,
   - `4003 Sales Returns & Refunds` appears as a **negative** amount,
   - Net Revenue is reduced correctly.
5. Check Cash Management / Cash Flow before Refund:
   - cash should still be unchanged because no money has left yet.
6. In Transactions → Reversed, click the Refund Due amount and submit/approve Refund using the mapped payment method.
7. Check Cash Management / Cash Flow again:
   - the mapped BDO/GCash/Cash account should now decrease by the refund amount,
   - Cash Flow should show the negative operating cash movement.
