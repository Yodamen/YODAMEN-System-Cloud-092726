# Phase 5B Service Credit Separate Card 04

Frontend-only UI correction.

## Changes
- Booking form: Service Credit moved out of the regular Service selector into its own full-width card.
- Walk-In form: same separate Service Credit card behavior.
- Regular Service card now contains only new/current-price service or package selections.
- Service Credit card detects the selected customer's available balances.
- `Claim Service Credit` opens an inline claim builder for credit, sessions, and staff.
- Claimed sessions stay in the existing booking service-line payload so backend reservation/consumption rules remain unchanged.
- Current available-session calculation continues to subtract sessions already claimed on the open form.

No migration or backend change is included.
