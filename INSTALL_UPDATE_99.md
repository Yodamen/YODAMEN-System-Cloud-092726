# YODAMEN Update 99 — Transaction Correction / Refund / Reversal Posting Engine

This package is cumulative for Update 98 + Update 99. It is safe to install whether Update 98 was already installed or not. Laravel will skip migration `2026_09_21_001000` if it is already recorded and will run only the remaining migration.

## Locked operating rules

- Transactions actions remain: **Checkout, Correction, Reversal, Refund**.
- **Correction** works per service/product line. It can edit quantity, assigned personnel, price, or remove an existing line.
- **Refund** works per service/product line and can optionally return a retail product to stock.
- **Reversal** applies to the whole transaction only.
- Correction, Refund, and Reversal require a structured reason, mandatory remarks, and **Manager approval**.
- Maker-checker is enforced: the requester cannot approve their own request.
- Only one active transaction-action request is allowed for a transaction at a time.
- Approval and all downstream postings are atomic. If one downstream posting fails, the approval and every posting roll back.
- Before Checkout, an approved Correction becomes the effective transaction basis. Checkout and all later waterfall effects use the corrected values.
- Before Checkout, Correction does **not** create commission early. Commission remains a Checkout event.
- After Checkout, a Correction decrease creates **Refund Due** only. It does not automatically return money and does not create Customer Credit.
- After Checkout, a Correction increase creates **Balance Due**. The Transactions screen exposes Checkout to collect the additional balance.
- Refund first reduces an unpaid balance when applicable. Only the paid excess becomes an actual cash/e-wallet/card refund.
- Reversal invalidates the whole transaction, reverses/counter-posts downstream operational effects, preserves received-payment history, and creates Refund Due for money that must actually be returned.
- Reversed transactions remain visible under the **Reversed** tab until any Refund Due is settled; historical reversed transactions remain traceable afterward.
- Original records are preserved. Corrections/refunds/reversals use compensating records rather than destructive deletion.
- Product stock is restored only when Refund explicitly says **Returned to Stock**.
- Service refunds do not restore consumed service materials automatically. Operational service correction/reversal is handled by Correction/Reversal, not Refund.
- Commission adjustments after financial finalization are forward-dated adjustments and do not rewrite approved/paid historical payroll.

## Important integrity fixes included

- New sale/service/product lines initialize their effective transaction values correctly, preventing new transactions from becoming zero-value after the migration.
- A removed line's Refund Due is preserved even if another corrected line later receives an On Account collection.
- Service completion events and visit retail lines now have the required correction/reversal tracking columns in the actual `up()` migration.
- Reversal payment applications are counter-posted without falsely deleting the original received payment.
- Reversal Refund Due remains accessible through the new Reversed transaction view.
- Repeated downstream effects use source/effect keys where applicable to reduce duplicate posting risk.

## Install

### 1. Back up the current database first

```bash
/usr/local/mysql-8.4.11-macos15-arm64/bin/mysqldump -u root -p --no-tablespaces --routines --triggers --events yodamen_dev > ~/Downloads/YODAMEN-PRE-UPDATE-99-DATABASE.sql
```

### 2. Extract Update 99 into the project

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-PHASE-7H-TRANSACTION-POSTING-ENGINE-99.zip -d .

cd backend
```

### 3. Apply migration and clear caches

```bash
php artisan migrate
php artisan optimize:clear
```

### 4. Run the complete Laravel test suite

```bash
php artisan test
```

**Do not continue to production-style testing if any Laravel test fails.** Send the complete failing output for repair first.

### 5. Build the React frontend

```bash
cd ../frontend
npm run build
```

If the build succeeds, restart the Laravel/frontend development servers.

## Required smoke tests after installation

1. **Pre-checkout Correction:** complete two service quantities, correct the quantity downward, approve with another Manager account, then verify Checkout shows only the corrected amount and no Refund Due.
2. **Post-checkout Correction decrease:** pay a transaction, reduce/remove one line through Correction, approve it, and verify the line/sale shows Refund Due but no automatic refund/payment reversal occurs.
3. **Refund Due settlement:** refund the exact Refund Due and verify it becomes zero, cash-refunded amount increases, and the original sale/payment history remains visible.
4. **Post-checkout Correction increase:** increase a finalized line, approve it, verify Balance Due appears, then use Checkout to collect only the additional amount.
5. **Whole Reversal:** reverse a paid transaction, approve it, verify it appears under Reversed, operational effects are counter-posted, and the previously paid amount appears as Refund Due until separately refunded.
6. **Retail Refund:** refund a product once with Returned to Stock = Yes and verify stock increases only once; a repeated refund must not return the same physical quantity twice.
7. **Maker-checker:** requester attempts to approve their own Correction/Refund/Reversal and must be blocked.

## Static validation already completed before packaging

- PHP syntax lint passed for all Update 99 backend files and both migrations.
- JSX/TSX parse validation passed for the modified Transactions page and Transaction Action modal.
- Integrity assertions confirmed maker-checker, single-active-request, stale-snapshot protection, pre-checkout commission deferral, Reversal Refund Due, payment-application counter-posting, effective-value initialization, Reversed-tab exposure, and absence of automatic Customer Credit logic.

Full Laravel runtime tests and the Vite production build still need to run on the local YODAMEN environment because the handoff source intentionally excludes `vendor/` and `node_modules/`.
