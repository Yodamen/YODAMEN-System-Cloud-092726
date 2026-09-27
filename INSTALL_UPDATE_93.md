# Install Update 93

From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-UPDATE-93-CUSTOMER-TRANSACTION-UI.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
npm run dev
```

No database migration is included in Update 93.
