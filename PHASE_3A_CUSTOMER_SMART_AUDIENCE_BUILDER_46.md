# YODAMEN Update 46 — Customer Smart Audience Builder

## Purpose
Adds a Meta-style grouped customer audience builder to Operations → Customers while keeping the existing quick filters.

## Logic
- Root can match ALL groups (AND) or ANY group (OR).
- Each group can match ALL conditions or ANY condition. A group behaves like parentheses.
- Service Availed uses actual COMPLETED Visit Service Lines. Booking alone does not count. Cancelled services do not count.
- Package Purchased uses financially finalized PACKAGE Sale Items from valid Sales.
- Lifetime Value reuses the current received-payment-capped customer metric.
- Promo qualification reuses the active Discount Rule customer eligibility engine.
- Quick filters can further narrow the Smart Audience result.

## Available conditions
- Service Availed
- Service Category Availed
- Package Purchased
- Completed Visit Count
- Lifetime Value
- Last Visit
- Qualifies for Promo Code
- Lead Source
- Birth Month
- Customer Status

Service/package conditions support Any Of, All Of, None Of, Never, Count At Least/At Most, Last Availed/Purchased More Than or Within X Days, plus All Time / Last N Days / Between Dates periods.

## Database
No migration is required.

## Installation
Install after Updates 44 and 45.

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-3A-CUSTOMER-SMART-AUDIENCE-BUILDER-46.zip -d .
```

Backend tests:

```bash
cd backend
php artisan test --filter=CustomerAudienceTest
php artisan test --filter=CustomerTest
```

Frontend:

```bash
cd ../frontend
npm run build
```

After all three updates are installed and their focused tests pass, run the full backend test suite:

```bash
cd ../backend
php artisan test
```
