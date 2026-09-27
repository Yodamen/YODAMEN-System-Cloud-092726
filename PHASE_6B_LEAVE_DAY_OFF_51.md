# YODAMEN Phase 6B — Leave & Day Off — Update 51

Adds My Leave & Day Off filing, admin Leave Management, leave types/balances, approval/rejection/cancellation, day-off schedule overrides, and Attendance calendar integration.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6B-LEAVE-DAY-OFF-51.zip -d .
cd backend
php artisan migrate
php artisan db:seed --class=HrLeaveSeeder
php artisan test --filter=LeaveManagementTest
cd ../frontend
npm run build
```

Do not use `migrate:fresh`.
