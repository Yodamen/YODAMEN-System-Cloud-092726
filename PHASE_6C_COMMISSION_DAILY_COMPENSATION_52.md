# YODAMEN Phase 6C — Commission Earnings & Daily Compensation — Update 52

Adds auditable Commission Earnings posting/reversal foundation and Daily Compensation snapshots combining approved attendance/paid leave, OT, guaranteed-pay rules, and confirmed commission.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6C-COMMISSION-DAILY-COMPENSATION-52.zip -d .
cd backend
php artisan migrate
php artisan test --filter=DailyCompensationTest
php artisan test --filter=CommissionRuleTest
cd ../frontend
npm run build
```

Do not use `migrate:fresh`.
