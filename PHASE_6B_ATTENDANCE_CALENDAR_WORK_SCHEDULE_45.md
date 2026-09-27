# YODAMEN Phase 6B — Work Schedule + Attendance Calendar Update 45

Requires Update 44 to be installed first because the cumulative API routes include the activated Users & Roles routes.

## Adds
- Personnel → Work Schedule action
- Monday–Sunday work schedule with expected hours and optional start/end time
- Date overrides for Workday or Day Off
- My Attendance → List View / Calendar View
- Calendar month navigation
- Yellow = Present
- Red = Absent
- Blue = Day Off
- Orange = Not Full Day
- Neutral states for Working, Pending Approval, Future, Today, and Unscheduled

## Rules
- Absent is shown only for a past scheduled workday with no valid attendance.
- Day Off comes only from the configured weekly schedule or a date override.
- Present / Not Full Day require APPROVED or ADJUSTED attendance.
- Pending approval is not marked absent.
- If no schedule is configured, the system does not invent absences or days off.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-PHASE-6B-ATTENDANCE-CALENDAR-WORK-SCHEDULE-45.zip -d .
cd backend
php artisan migrate
php artisan test --filter=WorkScheduleCalendarTest
php artisan test --filter=AttendanceTest
cd ../frontend
npm run build
```

Do not run `migrate:fresh`.
