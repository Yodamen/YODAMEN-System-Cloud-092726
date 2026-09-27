# Install Update 86

From your project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-86-INVENTORY-DASHBOARD-WEIGHTED-COST.zip -d .
```

Backend:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan optimize:clear
php artisan test
```

Frontend:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/frontend
npm run build
npm run dev
```

No database migration is required for Update 86.
