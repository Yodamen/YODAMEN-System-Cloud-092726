# YODAMEN Phase 7A — Booking / Walk-In Product Lookup 65

## Purpose

Connect the existing Booking and Walk-In **Retail Purchases** editor to the Product Masterlist introduced in Phase 7A.

## User-facing changes

- Booking → Retail Purchases now uses a **Product dropdown** instead of free-text product entry.
- Queue → New Walk-In → Retail Purchases uses the same Product dropdown.
- Dropdown options come from **active Product Masterlist** records.
- Each option shows SKU, Product Name, Selling Price, and current available branch stock.
- Selecting a product automatically fills **Unit Price** from Product Masterlist Selling Price.
- Unit Price is read-only in Booking / Walk-In.
- Quantity and Assigned Staff remain editable.
- Existing legacy retail lines without Product Masterlist IDs remain readable/editable without deleting historical snapshots.

## Backend controls

The browser price is not authoritative. When a `retail_product_id` is submitted, Laravel:

1. validates that the Product Masterlist record belongs to the same business and is Active;
2. ignores any altered submitted Unit Price;
3. snapshots the product's current Product Name, SKU, and Selling Price into the operational retail line.

This prevents browser-side price tampering and preserves historical sale pricing if Product Masterlist prices change later.

## Product identity propagation

A nullable `retail_product_id` and `sku_snapshot` are added to:

- `booking_retail_lines`
- `queue_retail_lines`
- `visit_retail_lines`

The Product ID/SKU is carried:

`Booking / Walk-In → Queue → Visit → Sale Item`

This lets product-specific Retail Commission Rules identify retail items created from Booking/Walk-In, while legacy free-text rows remain supported.

## Files

### ADD NEW

- `backend/database/migrations/2026_09_18_008000_link_operational_retail_lines_to_product_master.php`
- `backend/tests/Feature/Operations/RetailProductLookupTest.php`

### REPLACE EXISTING

- `backend/app/Http/Controllers/Api/V1/Operations/BookingController.php`
- `backend/app/Http/Controllers/Api/V1/Operations/QueueController.php`
- `backend/app/Http/Requests/Api/V1/Operations/SaveBookingRequest.php`
- `backend/app/Http/Requests/Api/V1/Operations/SaveWalkInRequest.php`
- `backend/app/Models/BookingRetailLine.php`
- `backend/app/Models/QueueRetailLine.php`
- `backend/app/Models/VisitRetailLine.php`
- `backend/app/Services/Operations/BookingService.php`
- `backend/app/Services/Operations/QueueService.php`
- `backend/app/Services/Operations/VisitService.php`
- `backend/app/Services/Sales/SaleDraftService.php`
- `frontend/src/pages/operations/BookingsPage.jsx`
- `frontend/src/pages/operations/QueuePage.jsx`

## Install

From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-7A-BOOKING-WALKIN-PRODUCT-LOOKUP-65.zip -d .
cd backend
php artisan migrate
```

Do **not** run `migrate:fresh`.

## Tests

```bash
php artisan test --filter=RetailProductLookupTest
php artisan test --filter=BookingTest
php artisan test --filter=QueueTest
php artisan test --filter=ServicingTest
php artisan test --filter=CheckoutSettlementTest
php artisan test --filter=CommissionRuleTest
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

## Expected behavior

If Product Masterlist contains:

- SKU: `SKU-000015`
- Product: `Scalp Treatment Shampoo`
- Selling Price: `650.00`

Selecting it in Booking or Walk-In immediately shows Unit Price `650.00`.

If the browser submits `1.00` or another altered value, Laravel still stores `650.00` from Product Masterlist.
