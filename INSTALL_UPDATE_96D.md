# YODAMEN Update 96D — Consumption UOM Conversion & Cost Correction

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-96D-CONSUMPTION-UOM-LOGIC-FIX.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test
```

Expected migration:

`2026_09_20_016000_correct_service_consumption_conversion_semantics`

Then:

```bash
cd ../frontend
npm run build
npm run dev
```
