# Install Update 87

From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-87-INVENTORY-CONTROL-REFINEMENTS.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

cd ../frontend
npm run build
npm run dev
```

Expected new migration:
`2026_09_20_010000_inventory_pricing_and_adjustment_control`
