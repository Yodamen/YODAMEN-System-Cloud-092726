# YODAMEN 100K7N14J — Smart Customer Audience Class/Type + UI Modernization

## Scope
- Adds Customer Type and Customer Class as Smart Customer Audience fields.
- Uses the live configurable Customer Mix criteria; no class/type labels are hardcoded into audience matching.
- Customer Type is resolved from Completed Visit Count.
- Customer Class is resolved from Lifetime Value.
- Aligns Smart Audience Lifetime Value with finalized non-reversed sales and received non-tip payments, matching the customer classification basis.
- Keeps ALL / ANY group behavior intact.
- Existing Customer List quick filters continue to narrow Smart Audience results after the audience is applied.
- Modernizes the Smart Customer Audience modal, group cards, condition cards, field spacing, helper notes, remove controls, and footer.

## Database
No migration.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14J-SMART-AUDIENCE-CLASS-TYPE-UI.zip -d .

cd backend
php artisan optimize:clear
php artisan test tests/Feature/Operations/CustomerAudienceTest.php
php artisan test

cd ../frontend
npm run build
```

## Held update
100K7O remains on hold and is not included.
