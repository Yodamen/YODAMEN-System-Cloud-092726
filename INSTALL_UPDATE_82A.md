# Install Update 82A

From the YODAMEN project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-82A-COMPENSATION-DATE-CONTROL.zip -d .
cd backend
php artisan optimize:clear
php artisan test
```

No migration is required.

Then rebuild/restart the frontend:

```bash
cd ../frontend
npm run build
npm run dev
```

If the backend server needs to be restarted:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development/backend
php artisan serve --port=8001
```
