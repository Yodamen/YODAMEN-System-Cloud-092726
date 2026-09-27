# YODAMEN 100K7N10I — Sales Dashboard KPI Order + Customer Class Analytics

## Changes
- Sales Dashboard row 2 order is now:
  1. Ave Booking Value
  2. Ave Lifetime Value
  3. Client Visits
  4. Unique Customers
- Replaces the Payment Method Mix dashboard analytics card with Customer Class.
- Customer Class analytics groups customers by their configured Lifetime Value class.
- Class is evaluated using cumulative customer Lifetime Value through the selected report end date.
- Customer Class analytics remains clickable and supports yearly trend/chart view.
- Payment-method reporting data remains available in the backend for other workflows; only the dashboard card is replaced.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10I-SALES-DASHBOARD-CLASS-ANALYTICS.zip -d .

cd backend
php artisan optimize:clear
php artisan test tests/Feature/Sales/CheckoutSettlementTest.php
php artisan test

cd ../frontend
npm run build
```

No migration is required.
