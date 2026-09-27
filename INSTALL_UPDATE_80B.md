# Update 80B - Day Off Frontend Visibility Fix

This patch corrects the remaining frontend conditions for the employee Day Off tab.

- Day Off visibility follows `my-attendance.VIEW`.
- Day Off content renders using the Day Off permission condition, independent of paid leave entitlement.
- Leave remains visible only when the linked personnel is paid-leave entitled.
- No migration required.
