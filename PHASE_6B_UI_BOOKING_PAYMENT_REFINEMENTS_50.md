# YODAMEN Update 50
## Booking Payment + Admin UI Refinements

### Purpose
This update applies five requested refinements without changing database schema.

### 1. Bookings > Completed payment amount
The Payment column now uses `collected_received_total` for Completed bookings.

`collected_received_total` is sourced from the finalized Sale `applied_amount` rather than Sale gross/net value. This means the table shows only money actually applied to the specific booking after discount calculations, including partial payment scenarios such as package sales.

Examples:
- Gross package: PHP 10,000
- Discount: PHP 1,000
- Net charge: PHP 9,000
- Actually collected/applied: PHP 3,000
- Completed Booking Payment column: PHP 3,000

Existing gross/net fields remain available for other workflows and are not destructively changed.

### 2. Booking search
Across Active, Completed, and Cancelled, the search filter is now Booking ID only.
The backend no longer expands this search to customer name/mobile. Customer Name remains a separate filter.

### 3. Reference Lists
Removed the visible `Reference Category` label and the selected-category/count summary such as `Attendance Status / 4 options`.
The category dropdown itself remains so administrators can still switch reference lists.

### 4. Services & Pricing filters
Added client-side filters for:
- Service ID or name
- Category
- Service Count
- Status
- Reset Filters

No API or database change is required.

### 5. Commission Rules
Removed the long Resolution Order / Priority / Split Commission explanatory warning from the page.
Commission calculation logic itself is unchanged.

## Files
### REPLACE EXISTING
- `backend/app/Http/Controllers/Api/V1/Operations/BookingController.php`
- `backend/tests/Feature/Operations/BookingTest.php`
- `frontend/src/pages/operations/BookingsPage.jsx`
- `frontend/src/components/operations/PaymentIndicator.jsx`
- `frontend/src/pages/admin/ReferenceListsPage.jsx`
- `frontend/src/pages/admin/ServicesPricingPage.jsx`
- `frontend/src/pages/admin/CommissionRulesPage.jsx`

### ADD NEW
- None

### DATABASE MIGRATION
- None

## Installation
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6B-UI-BOOKING-PAYMENT-REFINEMENTS-50.zip -d .
```

Backend targeted test:

```bash
cd backend
php artisan test --filter=BookingTest
```

Recommended related regression tests:

```bash
php artisan test --filter=CheckoutSettlementTest
php artisan test --filter=PackageServiceCreditTest
php artisan test --filter=ServicesPricingTest
php artisan test --filter=ReferenceDataTest
php artisan test --filter=CommissionRuleTest
```

Then full backend regression:

```bash
php artisan test
```

Frontend build:

```bash
cd ../frontend
npm run build
```

## Expected Result
- Completed booking Payment amount reflects actual applied collections, not gross/net booking value.
- Booking Search uses Booking ID only.
- Reference Lists toolbar is cleaner.
- Services & Pricing has filter controls.
- Commission Rules no longer shows the requested explanatory warning.
