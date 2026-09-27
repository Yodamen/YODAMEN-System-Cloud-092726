# YODAMEN 100K7N10F — Customer Class Badge + Customer Type Colors

## Scope
- Customer badge text now displays **Customer Class**.
- Badge color now identifies **Customer Type**.
- Customer Mix > Type now has a configurable color picker and hex value.
- Removes old hard-coded New/Returnee/Regular/Loyal badge-color CSS logic.
- Customer List, Bookings, Queue, and Sales Top Clients use the same class/type badge treatment.
- Customer Type colors remain fully configurable per business.
- Customer Lifetime Value used for classification excludes electronic tips and reversed/unfinalized sales.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10F-CUSTOMER-CLASS-BADGE-TYPE-COLORS.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
```

## Targeted tests
```bash
php artisan test tests/Feature/Customers/CustomerClassificationTest.php
php artisan test tests/Feature/Operations/BookingTest.php
php artisan test tests/Feature/Operations/QueueTest.php
php artisan test tests/Feature/Sales/SalesCoreTest.php
```

## Full regression
```bash
php artisan test
```

Expected count if 100K7N10E baseline is clean: **264 passed**.

## Frontend build
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```
