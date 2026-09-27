# Install Update 89

From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-89-INVENTORY-UI-STATUS-PRICE-HISTORY.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
npm run dev
```

No database migration is required for Update 89.
