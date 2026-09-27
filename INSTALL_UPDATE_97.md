# YODAMEN Update 97 — Service Consumption Engine + Negative Stock

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-97-SERVICE-CONSUMPTION-ENGINE-NEGATIVE-STOCK.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test
```

Expected migration:

`2026_09_20_017000_activate_service_consumption_engine`

Then rebuild the frontend:

```bash
cd ../frontend
npm run build
npm run dev
```

## Important behavior

Service Material Recipes are now operational. Completing a service automatically posts material consumption. A material shortage does **not** block service completion: stock is allowed to become negative and later receipts, approved positive stock adjustments, or an approved audit count can offset the deficit.
