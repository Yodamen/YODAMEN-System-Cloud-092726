# Install Update 82

The ZIP is structured with `backend/` and `frontend/` at the root and is intended to be extracted directly into the existing YODAMEN project.

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-82-PERSONNEL-ACCOUNTING-DASHBOARD.zip -d .

cd backend

php artisan optimize:clear

php artisan migrate

php artisan migrate:status

php artisan test

cd ../frontend

npm run build

npm run dev
```

New migration expected as Ran:

`2026_09_19_007000_create_accounting_journal_foundation`
