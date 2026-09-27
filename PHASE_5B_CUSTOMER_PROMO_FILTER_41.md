# YODAMEN Phase 5B - Customer Promo Filter 41

## Purpose
Adds a Promo Code field to Operations > Customers. Entering a promo code filters the customer list to customers who currently satisfy at least one active/effective Discount Rule under that code.

## Eligibility used in Customer module
The filter is intentionally a customer targeting view, not a checkout simulation. It evaluates:
- All Customers / Specific Customer scope
- Customer Type (NEW = no completed visits, RETURNEE = at least one completed visit)
- Minimum / Maximum Lifetime Value
- Last Visit conditions (More Than, Within, Between, Never Visited)
- Rule status and effective date

A promo code can have multiple active rules. A customer is shown when they qualify for at least one of those rules.

## Checkout-only restrictions
Branch, service, service category, package/product/item scope and the actual booking customer type are still authoritatively revalidated at Checkout. The Customer module therefore means "potentially eligible customer for this promo today," not "guaranteed discount for every transaction."

## Files changed
- backend/app/Services/Discounts/DiscountRuleService.php
- backend/app/Http/Controllers/Api/V1/Operations/CustomerController.php
- backend/tests/Feature/Operations/CustomerTest.php
- frontend/src/pages/operations/CustomersPage.jsx
- frontend/src/index.css

## Database
No new migration is required. This update uses the Discount Rule customer criteria added in Update 40.

## Install
Extract this ZIP over the YODAMEN project root.

Run:
```bash
cd backend
php artisan test --filter=CustomerTest
php artisan test --filter=DiscountRuleTest
php artisan test

cd ../frontend
npm run build
```

## Expected UI
Operations > Customers now includes a Promo Code filter. Typing a code automatically checks it after a short debounce and shows the count of qualified customers. Reset clears the promo filter together with the other Customer filters.
