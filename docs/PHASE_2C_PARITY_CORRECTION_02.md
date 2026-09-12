# Phase 2C Parity Correction 02

## Scope

Align the React Queuing and Servicing pages with the current Apps Script live operational workspace shown in the user-provided screenshots.

## Changes

- Queuing: removed status dropdown, queue-date selector, and summary cards.
- Queuing: displays the current business-date `WAITING` queue only.
- Queuing: retains `New Walk-In`, Start Servicing, Cancel, queue priority, and current service/staff data.
- Servicing: removed status dropdown, visit-date selector, and summary cards.
- Servicing: displays current business-date `IN_SERVICE` visits only.
- Both pages: compact refresh control beside the page title.
- Both pages: one clean content card below the heading, matching the Apps Script layout direction.
- Bookings remains the only Operations page with `Active`, `Completed`, and `Cancelled` tabs.

## Database impact

None. No migration or seeder is required.

## Validation

Run locally after applying:

```bash
cd frontend
npm run lint
npm run build
```

Then start Laravel and Vite and visually verify Queuing and Servicing.
