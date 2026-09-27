# YODAMEN 100K7N10 — Customer Module, Classification & Sales KPI

## Scope
- Separates Customers from Operations in the main navigation.
- Moves Service Balances under Customers.
- Adds Customer Type Criteria using completed visits: New, Returnee, Regular, Loyal.
- Preserves the existing default visit logic: New 0–1, Returnee 2, Regular 3–10, Loyal 11+; thresholds are configurable.
- Adds Customer Class Criteria based on Lifetime Value; classes are user-configurable and customers remain Unclassified until classes are created.
- Auto-detects Customer Type and Booking Source instead of allowing manual selection in Booking / Walk-In forms.
- Adds Ave Booking Value and Ave Lifetime Value to Sales Dashboard.
- Adds Customer Class to Customer List and Customer Profile.
- Adds disabled Points Settings placeholder under Customers for the future points/loyalty criteria module.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10-CUSTOMER-MODULE-CLASSIFICATION-SALES-KPI.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/backend"
php artisan optimize:clear
php artisan migrate
```

## Targeted tests
```bash
php artisan test tests/Feature/Customers/CustomerClassificationTest.php
php artisan test tests/Feature/Operations/BookingTest.php
php artisan test tests/Feature/Operations/QueueTest.php
```

## Full regression
```bash
php artisan test
```

100K7N10 adds 4 test cases. If the accepted non-payroll baseline before this patch is 260 passed, the expected full declaration count is 264 passed.

## Frontend build
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

## Notes
- 100K7O statutory contribution engine remains ON HOLD and is not included.
- Old browser routes `/operations/customers` and `/operations/service-balances` redirect to the new Customer module routes.
- Existing customer APIs remain backward-compatible while the UI is reorganized.
