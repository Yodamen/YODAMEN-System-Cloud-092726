INSTALL UPDATE 88

From the project root:

cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-88-RECEIVING-SUPPLIER-REFINEMENTS.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

Then:

cd ../frontend
npm run build
npm run dev

Expected migration:
2026_09_20_011000_supplier_codename_receipt_delivery_fee
