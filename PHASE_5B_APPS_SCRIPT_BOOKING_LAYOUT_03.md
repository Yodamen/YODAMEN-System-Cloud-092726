# Phase 5B - Apps Script Booking Layout Parity 03

Frontend-only UI parity update for Booking and Walk-In transaction forms.

## Updated layout
- Customer Information card
- Booking Information card (Booking only)
- Service card for new services/packages only
- Separate Service Credit card with Claim Service Credit flow
- Product Purchase card
- Advance Payment card
- Notes card
- Sticky footer actions
- Add New Customer moved to modal header, matching Apps Script layout

## Service Credit behavior
- Selecting a customer loads active service credits.
- Helper text reports whether service credit balances are available.
- Claim Service Credit opens an inline builder with Available Credit, Sessions, and Staff.
- Available sessions are shown in the credit selector.
- Claiming multiple sessions creates the matching number of one-session service-credit reservations, preserving current backend reservation/consumption behavior.
- Claimed credits are displayed separately from new services.

No database migration or backend change is included.
