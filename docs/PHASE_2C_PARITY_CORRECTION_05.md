# Phase 2C Parity Correction 05

Final UI refinement requested before Phase 2C approval.

## Change

- Replaced the text refresh glyph beside Bookings, Queuing, and Servicing with a two-arrow circular sync/refresh icon matching the current Apps Script visual reference.
- The refresh control now performs a full browser page reload using `window.location.reload()`.
- The control remains accessible through an aria label/title and respects the existing disabled state while a page is loading or saving.
- No backend, schema, migration, seeder, or business-rule changes are included.
