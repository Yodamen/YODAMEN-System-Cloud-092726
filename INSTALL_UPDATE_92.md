# Install Update 92

From Terminal 3:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-92-SALES-HR-UI-REFINEMENTS.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
npm run dev
```

No migration is included in this update.
