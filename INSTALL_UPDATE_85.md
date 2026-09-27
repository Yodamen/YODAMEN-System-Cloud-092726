# Install Update 85

From Terminal 3:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-85-UOM-SUPPLIERS-RETAIL-CONTROL.zip -d .

cd backend

php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test
```

Expected new migration:

`2026_09_20_009000_add_inventory_uom_retail_suppliers`

Then rebuild/start frontend:

```bash
cd ../frontend
npm run build
npm run dev
```

If the Laravel server is stopped, use Terminal 1:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan serve --port=8001
```

Hard refresh Chrome with Command + Shift + R.
