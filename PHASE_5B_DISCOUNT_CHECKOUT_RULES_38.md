# YODAMEN Phase 5B Backjob 38 - Checkout Discounts + Discount Rules

## Purpose
Adds an auditable, server-authoritative discount engine to Sales Checkout and Administration without changing the approved payment, Service Credit, package, or commission trigger rules.

## Checkout behavior
- Discount Code is optional.
- Entering a code does not immediately mutate financial records.
- Apply performs a Laravel preview and recalculates Gross, Discount, Net Due, line balances, Advance requirement, and Checkout allocation capacity.
- Checkout cannot finalize while a typed code has not been successfully previewed.
- Finalize recalculates the same code server-side inside the Checkout transaction and snapshots the result.
- One discount code per Checkout. Discount stacking is not supported.
- Service Credit use lines remain zero-charge and are not discounted.
- Payment allocation and On Account balances use the net amount after discount.

## Flexible Discount Rules
Administration > Discount Rules supports:
- Branch: All Branches or specific Branch
- Customer scope: All Customers, Customer Type, Specific Customer
- Item scope: All Chargeable, Services, Packages, Retail
- Specific Service or Service Category
- Percentage or Fixed Amount
- Effective From / Until
- Priority
- Active / Inactive

Resolution is deterministic:
1. More specific customer scope
2. More specific service/category scope
3. Specific item scope
4. Specific branch
5. Higher Priority for equal specificity
6. Stable Rule ID tie-breaker

Multiple rules may share the same Discount Code. The best matching rule is resolved independently per eligible Sale Item.

## Financial snapshots
Sales preserve:
- Gross Amount
- Discount Amount
- Net Amount
- Discount Code snapshot

Sale Items preserve:
- Gross Line Total
- Discount Rule / Code / Name / Method / Rate snapshots
- Discount Amount
- Net Line Total

Discounted package purchases preserve both:
- package_gross_price_snapshot = original package selling price
- package_price_snapshot = discounted customer receivable

This allows future commission logic to remain based on the approved rendered-service/standard-price basis rather than silently inheriting customer discount amounts.

## Booking > Completed
Payment subheadline now displays the finalized Sale Net Amount after discount.

## Files to ADD
- backend/app/Http/Controllers/Api/V1/Admin/DiscountRuleController.php
- backend/app/Http/Requests/Api/V1/Admin/SaveDiscountRuleRequest.php
- backend/app/Http/Requests/Api/V1/Sales/PreviewDiscountRequest.php
- backend/app/Models/DiscountRule.php
- backend/app/Services/Discounts/DiscountRuleService.php
- backend/app/Services/Discounts/DiscountService.php
- backend/database/migrations/2026_09_18_000100_create_discount_rules_and_sale_discounts.php
- backend/tests/Feature/Discounts/DiscountRuleTest.php
- frontend/src/pages/admin/DiscountRulesPage.jsx

## Files to REPLACE
- backend/app/Http/Controllers/Api/V1/Operations/BookingController.php
- backend/app/Http/Controllers/Api/V1/Operations/CustomerController.php
- backend/app/Http/Controllers/Api/V1/Sales/TransactionsController.php
- backend/app/Http/Requests/Api/V1/Sales/FinalizeCheckoutRequest.php
- backend/app/Models/PackageEnrollment.php
- backend/app/Models/Sale.php
- backend/app/Models/SaleItem.php
- backend/app/Services/Packages/PackageServiceCreditService.php
- backend/app/Services/Packages/ServiceBalanceReadService.php
- backend/app/Services/Sales/CheckoutSettlementService.php
- backend/app/Services/Sales/SaleDraftService.php
- backend/database/seeders/FoundationSeeder.php
- backend/routes/api.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php
- frontend/src/components/navigation/navigationConfig.js
- frontend/src/components/operations/PaymentIndicator.jsx
- frontend/src/components/sales/CheckoutModal.jsx
- frontend/src/index.css
- frontend/src/pages/operations/BookingsPage.jsx
- frontend/src/router/index.jsx

## Files NOT changed
- Commission Rule resolver/business hierarchy
- Payment / Payment Application data model
- Service Credit entitlement lifecycle
- Booking / Queue / Visit lifecycle
- Commission posting (still not activated by this backjob)

## Migration
Required:
- 2026_09_18_000100_create_discount_rules_and_sale_discounts.php

Never run migrate:fresh.

## Recommended validation
Backend:
- php artisan optimize:clear
- php artisan migrate:status
- php artisan migrate
- php artisan test tests/Feature/Discounts/DiscountRuleTest.php
- php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
- php artisan test

Frontend:
- npm run lint
- npm run build
- npm run dev
