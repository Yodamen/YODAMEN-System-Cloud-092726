# YODAMEN Phase 6C — Package Session Commission + Split Checkout Payments 59

## Purpose

This update fixes package-session commission posting and adds split payment methods to Checkout.

## 1. First rendered package session commission

Previous behavior skipped `PACKAGE` Sale Items in the commission engine. A newly sold multi-session package is represented as a `PACKAGE` item even when its first session was already rendered by an assigned staff member. This caused that first rendered session to miss commission.

New behavior:

- A package purchase by itself still does **not** create commission.
- A `PACKAGE` Sale Item is commission-eligible only when it is linked to a completed Visit Service with an assigned, active, commission-eligible Personnel record.
- Commission is still posted only after financial finalization (`PAID` or `ON_ACCOUNT`).
- The first completed package session now creates one Commission Earning after checkout finalization.
- Later package sessions represented by `SERVICE_CREDIT_USE` continue to create commission.
- Package session commission basis is now the rendered session value:

  `Package Gross Value / Total Package Sessions`

- Fixed commission rules apply once per rendered session.
- Percentage commission rules apply to the rendered session value, not the full package selling price.
- Existing idempotency remains based on the Visit Service Line, so the same rendered session cannot create duplicate commission.

## 2. Split payment methods at Checkout

Checkout now supports multiple payment methods for the payment collected at final checkout.

Each split row contains:

- Payment Method
- Amount
- Reference No. (optional)

Controls:

- One row remains the default for normal single-method checkout.
- `+ Split Payment` adds another method.
- Every split row must have a valid active Payment Method and an amount greater than zero.
- The sum of split payment rows must equal the calculated Total Checkout Payment exactly (within the existing money tolerance).
- Finalize Checkout stays disabled in React while the split total is unbalanced.
- Laravel independently re-validates the split total before any Payment records are created.
- One `payments` record is created per split method. No schema change is required.
- Payment Applications are allocated across the split payment pool while preserving existing Sale Item allocations.
- Existing legacy single-method API payloads remain accepted for compatibility.

## Files

### REPLACE EXISTING

- `backend/app/Services/Commissions/CommissionEarningService.php`
- `backend/app/Services/Sales/CheckoutSettlementService.php`
- `backend/app/Http/Requests/Api/V1/Sales/FinalizeCheckoutRequest.php`
- `backend/tests/Feature/Sales/CheckoutSettlementTest.php`
- `frontend/src/components/sales/CheckoutModal.jsx`
- `frontend/src/index.css`

### ADD NEW

- `PHASE_6C_PACKAGE_COMMISSION_SPLIT_CHECKOUT_59.md`

## Database

No migration is required.

## Recommended validation

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan test --filter=CheckoutSettlementTest
php artisan test --filter=CommissionRuleTest
php artisan test --filter=PackageServiceCreditTest
php artisan test --filter=DailyCompensationTest
```

Then:

```bash
php artisan test
```

Frontend:

```bash
cd ../frontend
npm run build
```

## Manual test

1. Create/confirm an active commission rule for a multi-session package/service.
2. Book the package and assign a staff member.
3. Start servicing and complete the first session.
4. Finalize Checkout.
5. Verify Commission Summary contains one OPEN earning for that first rendered package session.
6. Verify the Commission basis displays the per-session value rather than the full package price.
7. At another Checkout, enter a checkout payment amount and click `+ Split Payment`.
8. Example: required Checkout Payment = PHP 1,000; Cash = PHP 400 and GCash = PHP 600.
9. Finalize must remain disabled if the split total is PHP 999 or PHP 1,001, and become available when the total is exactly PHP 1,000 and all methods are selected.
