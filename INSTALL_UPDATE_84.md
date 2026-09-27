# Install Update 84

From Terminal 3:

cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-84-INVENTORY-RECEIVING-AUDIT-COUNT.zip -d .

cd backend

php artisan optimize:clear

php artisan migrate

php artisan migrate:status

php artisan test

Then rebuild the frontend:

cd ../frontend

npm run build

npm run dev

New migration expected as Ran:
2026_09_20_008000_create_inventory_receiving_and_audit_counts
