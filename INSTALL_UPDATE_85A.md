INSTALL UPDATE 85A

From the YODAMEN project root:

unzip -o ~/Downloads/YODAMEN-UPDATE-85A-REGRESSION-TEST-ALIGNMENT.zip -d .

cd backend
php artisan optimize:clear
php artisan test

No migration is required.
No frontend rebuild is required.
