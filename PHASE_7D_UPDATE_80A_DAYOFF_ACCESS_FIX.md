# Update 80A - Day Off Access Fix

## Root cause
Update 79 separated Leave visibility from Day Off visibility, but Day Off still depended on the `my-leave` permission. Existing Barber role records in a live database may not have that permission even when they have `my-attendance` access, causing the Day Off tab to disappear.

## Approved behavior
- My Attendance access + linked personnel record => Day Off tab available.
- Paid Leave Entitled = true => Leave tab available.
- Paid Leave Entitled = false => Leave tab hidden; Day Off remains available.

## Backend
Self-service `/hr/my-leave` routes now use `my-attendance:VIEW` because Leave and Day Off are embedded under My Attendance. `LeaveService` continues to enforce leave entitlement and ownership.

## Test
Adds a regression test proving a Barber with My Attendance access can still use Day Off after all `my-leave` permissions are removed from the role.
