# YODAMEN Phase 5B — Discount Customer Criteria Update 40

## Purpose
Adds Customer Lifetime Value and Last Visit eligibility criteria to the existing Phase 5B Discount Rules engine introduced in Update 38.

This package is built against the current source supplied after Fix 39.

## Added behavior

### Customer Lifetime Value
Each Discount Rule may optionally define:
- Minimum Lifetime Value
- Maximum Lifetime Value

Both may be blank. Existing rules therefore remain unrestricted by LTV.

For checkout eligibility, LTV uses historical received payments linked to prior Sales, capped by each Sale's net value. The current Sale being checked out is excluded so a current advance/checkout payment cannot make the customer qualify for the same discount.

### Last Visit
Each Discount Rule may optionally define:
- Any
- More Than X Days Ago
- Within X Days
- Between X and Y Days Ago
- Never Visited Before Current Transaction

For checkout eligibility, the current Visit is excluded. This is required so a reactivation rule such as "last visit more than 30 days ago" evaluates the customer's prior visit rather than the service currently being checked out.

### Rule resolution
Lifetime Value and Last Visit are eligibility conditions. They do not replace the existing resolution hierarchy.

Existing resolution remains:
- customer specificity
- service/category specificity
- item scope
- branch specificity
- Priority for ties

## ADD NEW
- backend/app/Services/Operations/CustomerMetricsService.php
- backend/database/migrations/2026_09_18_000200_add_customer_criteria_to_discount_rules.php

## REPLACE EXISTING
- backend/app/Models/DiscountRule.php
- backend/app/Http/Requests/Api/V1/Admin/SaveDiscountRuleRequest.php
- backend/app/Services/Discounts/DiscountRuleService.php
- backend/app/Services/Discounts/DiscountService.php
- frontend/src/pages/admin/DiscountRulesPage.jsx
- backend/tests/Feature/Discounts/DiscountRuleTest.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php

## DO NOT CHANGE
Especially do not replace or roll back the Fix 39 files:
- backend/app/Services/Packages/PackageServiceCreditService.php
- backend/app/Services/Packages/ServiceBalanceReadService.php

No route changes are required.
No controller changes are required.
No environment variable changes are required.

## Database change
The migration adds these nullable columns to `discount_rules`:
- min_lifetime_value
- max_lifetime_value
- last_visit_operator
- last_visit_days_min
- last_visit_days_max

Because all fields are nullable, existing Discount Rules continue to work exactly as before until criteria are configured.

## Installation
Place this ZIP in the YODAMEN project root:

`/Users/domalonzo/Documents/YODAMEN-System-Development`

Then run:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o YODAMEN-PHASE-5B-DISCOUNT-CUSTOMER-CRITERIA-40.zip -d .

cd backend
php artisan migrate
php artisan test --filter=DiscountRuleTest
php artisan test --filter=CheckoutSettlementTest
php artisan test

cd ../frontend
npm run build
```

## Expected tests
The new focused tests validate:
- criteria save/serialization
- minimum LTV eligibility
- Last Visit > X days
- Last Visit within X days
- Last Visit between X and Y days
- Never Visited
- invalid criteria ranges
- prior LTV excludes the current Sale
- prior Last Visit excludes the current Visit
- existing discount checkout flow remains intact

## Manual UI check
Open Admin → Discount Rules → Add Rule.

You should now see:
- Minimum Lifetime Value
- Maximum Lifetime Value
- Last Visit

When Last Visit is selected, the appropriate day input(s) appear.

Suggested manual tests:

### VIP rule
- Code: VIP10
- Minimum Lifetime Value: 10000
- Discount: 10%

Expected: only customers with at least ₱10,000 historical LTV qualify.

### Comeback rule
- Code: COMEBACK30
- Last Visit: More Than X Days Ago
- Days: 30
- Discount: 10%

Expected: a customer whose prior visit was more than 30 days ago qualifies even though the current service happened today.

### First-visit rule
- Code: FIRSTVISIT
- Last Visit: Never Visited Before Current Transaction

Expected: a customer with no prior valid Visit qualifies; the current Visit does not disqualify them.

## Rollback
If Update 40 itself needs to be rolled back before new rules rely on these fields:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan migrate:rollback --step=1
```

Then restore the Update 38/39 versions of the replaced files from your previous checkpoint.

Do not roll back Fix 39's Service Credit repair files as part of this update.
