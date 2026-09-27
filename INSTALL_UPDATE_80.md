# YODAMEN Update 80 Installation

From Terminal 3:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-80-INVENTORY-MASTERLIST.zip -d .

cd backend

php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

cd ../frontend
npm run build
npm run dev
```

If the backend server is not running, use Terminal 1:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan serve --port=8001
```

Expected new migration:

`2026_09_19_006000_expand_inventory_classification_types`

After the frontend starts, hard refresh Chrome with Command + Shift + R.
