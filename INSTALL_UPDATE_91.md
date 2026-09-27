# Install Update 91

From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-UPDATE-91-AUDIT-COUNT-SCOPE-REFINEMENT.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

cd ../frontend
npm run build
npm run dev
```

Expected migration:

`2026_09_20_013000_expand_inventory_audit_count_scope`
