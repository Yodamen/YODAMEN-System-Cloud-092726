# YODAMEN Update 90

Inventory approval workflow and UI refinements.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-90-INVENTORY-APPROVAL-UI.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan migrate:status
php artisan test

cd ../frontend
npm run build
npm run dev
```

Migration expected:
`2026_09_20_012000_inventory_item_approval_workflow`
