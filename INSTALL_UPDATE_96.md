# YODAMEN Update 96 - UOM Mapping and Cost Foundation

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-96-UOM-COST-CONSUMPTION-MAPPING.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

cd ../frontend
npm run build
npm run dev
```

Expected migration:
`2026_09_20_015000_inventory_uom_mapping_and_cost_foundation`
