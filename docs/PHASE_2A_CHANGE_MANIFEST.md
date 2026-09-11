# YODAMEN Phase 2A Change Manifest

## Scope
Customers + reservation Booking foundation only.

## Added
- Customers relational master with legacy-compatible fields and duplicate controls for normalized mobile number and normalized First Name + Last Name + Birthday.
- Reservation bookings with service/staff snapshots and free-text retail snapshot lines.
- Append-only rebooking history.
- Pre-check-in booking cancellation with reason and audit.
- Customers and Bookings React pages.
- Operational reference seed values for Gender, Lead Source, Customer Type, and Booking Source.

## Deliberately deferred
- Walk-In creation and Check-In, because Queue must be created atomically in Phase 2B.
- Advance Payment receipt, because cash belongs in the immutable Payment ledger planned for Sales core.
- Service Credit claims, because package enrollment/service-credit entities are not active yet.
- Customer Last Visit / LTV / completed-visit metrics, which will be derived after Visit and Payment tables exist.
- Queue, Visit, Servicing, Sales, Commission, Payroll.
