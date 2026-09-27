# YODAMEN Phase 5B — Checkout Final Authority 16

## Purpose
Make Checkout the authoritative final financial confirmation step for every Booking.

## Approved behavior
- Checkout Payment is always visible whether or not the Booking already has an Advance Payment.
- Checkout Payment starts at ₱0.00. The system no longer assumes or auto-fills that the remaining balance was collected.
- The user explicitly enters the amount actually collected at Checkout against the current Booking lines.
- Payment Method is required only when the Checkout Payment is greater than ₱0.00.
- Existing Advance Payments are still allocated to the current Booking before final status is calculated.
- Existing Interim Payments remain linked to the Booking where they were collected but continue settling the older On Account balance. They do not reduce the current Booking's charge by mistake.
- The separate "Leave balance On Account" checkbox is removed. The resulting balance determines the final status automatically.
- Single-session services and product purchases must still be fully settled before Checkout can be finalized.
- Multi-session packages may finalize with a remaining balance. Any remaining package balance results in On Account.
- A fully settled chargeable Booking results in Paid.
- A Service-Credit-only Booking with no cash collection remains Service Credit. Service Credit with Booking-level collection continues to be presented under On Account according to the previously approved transaction-tab rule.
- Checkout shows a live Checkout Result before finalization.

## Complete replacement files
- frontend/src/components/sales/CheckoutModal.jsx
- backend/app/Services/Sales/CheckoutSettlementService.php
- backend/app/Http/Requests/Api/V1/Sales/FinalizeCheckoutRequest.php
- backend/tests/Feature/Sales/CheckoutSettlementTest.php

## Migration
No database migration required.
