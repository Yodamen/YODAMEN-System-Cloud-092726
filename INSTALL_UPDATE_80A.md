# Update 80A - Day Off Access Fix

This patch makes Day Off self-service follow My Attendance access instead of the separate my-leave permission.

- Day Off appears for linked personnel who can access My Attendance.
- Leave appears only when Paid Leave Entitled is enabled.
- The self-service leave/day-off API is protected by My Attendance access; backend business rules continue to block Leave for non-entitled personnel and enforce record ownership.
- No migration required.
