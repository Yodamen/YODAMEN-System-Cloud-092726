INSTALL UPDATE 94A

This is a regression-test alignment patch only. No migration or frontend rebuild is required.

1. Extract into the existing YODAMEN-System-Development project root.
2. From backend, clear caches and run the test suite.

Commands:
php artisan optimize:clear
php artisan test
