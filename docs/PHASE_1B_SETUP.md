# YODAMEN Phase 1B Setup Guide

**Phase:** 1B – Master Data Foundations  
**Target branch:** `development`  
**Database:** `yodamen_dev`

This package is an incremental update on top of the approved Phase 1A baseline. It does not contain `.env` files and does not change existing credentials.

## What Phase 1B adds

- Reference Lists with stable system codes and editable display labels.
- Services & Pricing with permanent service identity and effective-dated price history.
- Personnel master with Employee/Partner separation, Position, branch, linked user, Hourly Rate, OT entitlement/multiplier, operational compensation rule, service/commission eligibility, and partner profit-sharing profile.
- React pages for Reference Lists, Services & Pricing, and Personnel.
- Server-side module/action permission enforcement.
- SQL audit events for create/edit/toggle/price/personnel actions.
- Automated tests for the new master data rules.
- Hardening so rerunning the initial Super Admin seeder does not reset an existing Access Code.

## Before applying

Confirm:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
git branch
```

Expected:

```text
* development
  stable/approved
```

Confirm the working tree is clean:

```bash
git status
```

If it is not clean, do not apply this update until the current changes are committed or intentionally preserved.

## Apply the update package

From the project root, extract the Phase 1B update ZIP over the current project:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-1B-UPDATE.zip -d /Users/domalonzo/Documents/YODAMEN-System-Development
```

If the file downloaded with a different browser-generated name, use the actual filename shown in Downloads.

## Backend update

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan optimize:clear
php artisan migrate
php artisan db:seed --class=MasterDataSeeder
php artisan test
```

Do not run `migrate:fresh`.

The migration should add:

- `reference_groups`
- `reference_values`
- `services`
- `service_prices`
- `personnel`

The targeted seeder will add reference categories/options only. It will not reset the Super Admin Access Code.

## Frontend validation

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run lint
npm run build
```

If both pass, restart the development server if it is not already running:

```bash
npm run dev
```

## Browser validation

Open:

```text
http://localhost:5173
```

Validate:

1. Existing login still works using the Access Code you already changed.
2. Administration → Reference Lists opens.
3. Administration → Services & Pricing opens.
4. HR & Payroll → Personnel opens.
5. Add a temporary custom Payment Method reference, then deactivate/reactivate it.
6. Add one test service with an initial price.
7. Edit the service details and confirm the price is not overwritten.
8. Schedule a second price and confirm price history shows separate effective periods.
9. Add one Employee Personnel record.
10. Add one Partner/Managing Partner record and confirm profit-sharing fields are enforced.

## After successful validation

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
git status
git add .
git commit -m "Complete YODAMEN Phase 1B master data foundations"
```

Do not move `stable/approved` yet. First confirm Phase 1B behavior in the browser. Once accepted, move the stable checkpoint to the approved Phase 1B commit.

## Rollback if installation fails

Because Phase 1A is preserved on `stable/approved`, source code can be restored with Git. Do not run destructive database commands. If a migration fails, copy the exact terminal error into ChatGPT before attempting rollback.
