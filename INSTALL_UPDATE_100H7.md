# YODAMEN Update 100H7

## Scope

### Booking / Walk-In retail stock guard
- Retail products with zero available selling stock remain visible in the selector as **Out of stock** but cannot be selected for a new retail line.
- The frontend blocks Save Booking / Check In Walk-In when the combined requested quantity for a product exceeds its available selling quantity.
- The Laravel backend performs the same validation inside the booking transaction, so API calls cannot bypass the stock guard.
- Availability is converted from Basic UOM stock into Selling UOM quantity using `selling_to_basic_factor`.
- Checkout keeps its existing final stock recheck. Stock can still become unavailable after booking if another finalized transaction consumes it first.

### Commission Summary reversal collapse
- Full commission reversals/removals now display as one historical Commission Summary row only.
- The original earning row displays a negative commission amount and `REVERSED` status.
- The generated negative transaction-action counter-entry is hidden from Commission Summary when it points to that fully reversed source earning.
- The counter-entry is **not deleted** from the ledger. It remains available for payroll recovery/audit when historical payroll was already approved or paid.
- Partial commission corrections that are not full reversals are not collapsed by this change.

## Files
- `backend/app/Services/Operations/BookingService.php`
- `backend/app/Services/Commissions/CommissionEarningService.php`
- `backend/app/Http/Controllers/Api/V1/Operations/BookingController.php`
- `backend/app/Http/Controllers/Api/V1/Operations/QueueController.php`
- `backend/tests/Feature/Operations/BookingRetailStockGuardTest.php`
- `frontend/src/pages/operations/BookingsPage.jsx`
- `frontend/src/pages/operations/QueuePage.jsx`

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100H7-BOOKING-STOCK-COMMISSION-COLLAPSE.zip -d .

cd backend
php artisan optimize:clear
php artisan test --filter="blocks a retail product from being saved to a booking when available stock is insufficient"
php artisan test
```

Then:

```bash
cd ../frontend
npm run build
```

If Vite is not running:

```bash
npm run dev
```

No database migration is required.
