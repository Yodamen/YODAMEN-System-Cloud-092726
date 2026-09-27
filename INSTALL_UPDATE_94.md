# INSTALL UPDATE 94

From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-94-ADJUSTMENT-APPROVAL-SERVICE-RECIPES.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

cd ../frontend
npm run build
npm run dev
```

Migration expected:

`2026_09_20_014000_inventory_adjustment_approval_and_service_recipes`
