# YODAMEN Update 100H8

## Sales Dashboard analytics expansion

### Adds
- Service Category analytics card using active Service Category reference values only.
- Booking Type analytics card for Walk-In vs Reservation finalized sales.
- Payment Method Mix analytics card using effective received collections.
- Existing Sales Type, Sales Mix, and Customer Mix cards become clickable as well.
- Six-card analytics grid: 3 cards per row on desktop.
- Clicking an analytics card opens a January-to-December monthly trend table.
- Small Year dropdown inside the trend modal.
- Download PNG button creates a full table image in the browser with title, year, branch, monthly rows, category columns, and year totals.
- Trend respects the Sales Dashboard branch selection.
- Trend years are derived from finalized sales history and include the current year.

### Reporting rules
- Service Category excludes inactive service categories.
- Booking Type contains Walk-In and Reservation only. Direct Retail POS sales are excluded from this booking-only mix.
- Payment Method Mix follows the same effective-collections logic as Payments Received, including correction Refund Due handling.
- Removed/reversed sale lines remain excluded under the existing effective-sales rules.
- Reversed parent sales remain excluded.

### Files
- backend/app/Services/Sales/SalesReportingService.php
- backend/app/Http/Controllers/Api/V1/Sales/SalesDashboardTrendController.php
- backend/routes/api.php
- frontend/src/pages/sales/SalesDashboardPage.jsx
- frontend/src/pages/sales/sales-update79.css

### Database
No migration required.

### Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100H8-SALES-DASHBOARD-ANALYTICS.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
```

If Vite is already running, hard-refresh the browser with Command + Shift + R. Otherwise run `npm run dev`.
