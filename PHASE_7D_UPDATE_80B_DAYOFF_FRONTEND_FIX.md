# Update 80B - Day Off Frontend Visibility Fix

## Root cause
Update 80A corrected the backend route permission, but the My Attendance frontend still had two restrictive conditions:
1. Day Off visibility additionally required `user.personnel.id`, which could hide the tab even when My Attendance access was valid.
2. The Day Off page body was mistakenly guarded by `canUseLeave`, so a non-leave-entitled staff member could not render Day Off content.

## Approved behavior
- `my-attendance.VIEW` => Day Off tab is available.
- Paid Leave Entitled = true => Leave tab is available.
- Paid Leave Entitled = false => Leave tab is hidden while Day Off remains available.

## Migration
None.
