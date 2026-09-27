# YODAMEN Phase 3A — Customer Audience UI + Promo Dropdown Update 49

## Purpose

This patch improves the Customer module UX after Smart Audience Update 46 and Multi-Select Update 47.

### 1. Smart Audience multi-value dropdown
- The checkbox dropdown is rendered through a React portal above the modal instead of inside the scrollable condition card.
- It automatically opens above or below the field depending on available viewport space.
- Larger scrollable option area.
- Search remains available for long option lists.
- Cleaner Select All and Clear controls.
- Selected rows receive a subtle gold highlight.
- Selected-count summary is shown in the dropdown header.

### 2. Main Customers Promo Code filter
- Replaces free-text Promo Code input with a dropdown.
- Loads all distinct promo codes for the current business.
- A promo is considered currently active when at least one rule for that code is ACTIVE and currently within its effective period.
- Codes with no currently effective active rule remain selectable and display a subtle `Inactive` tag.
- Selecting an inactive promo intentionally produces the existing no-active-rule/zero-qualified-customers behavior.

## Files

### REPLACE EXISTING
- `backend/app/Http/Controllers/Api/V1/Operations/CustomerController.php`
- `frontend/src/pages/operations/CustomersPage.jsx`
- `frontend/src/pages/operations/CustomerAudienceBuilder.jsx`
- `frontend/src/index.css`

### ADD NEW
- `backend/tests/Feature/Operations/CustomerPromoOptionsTest.php`

## Database

No migration is required.

## Install

From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-3A-CUSTOMER-AUDIENCE-UI-PROMO-DROPDOWN-49.zip -d .
```

Then test:

```bash
cd backend
php artisan test --filter=CustomerPromoOptionsTest
php artisan test --filter=CustomerAudienceTest
php artisan test --filter=CustomerTest
```

Frontend:

```bash
cd ../frontend
npm run build
```

## Regression boundaries

This patch does not change Discount Rule evaluation, Checkout discount logic, Service Credit logic, Attendance, Users/Roles, or database schema.
