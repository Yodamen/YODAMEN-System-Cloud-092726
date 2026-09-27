# Phase 5A - Package / Service Credit Foundation

## Scope

Phase 5A establishes the normalized package and entitlement core without finalizing Service Credit redemption/payment allocation behavior.

### New tables
- `package_enrollments`
- `service_credits`
- `service_credit_usages`
- `service_credit_ledger`

### New lineage fields
Package Enrollment and Service Credit links are carried on Booking, Queue and Visit service lines, Service Completion Events and Sale Items. `payment_applications.package_enrollment_id` is now constrained to the package-enrollment table.

### Operational behavior
- Any active service configured with multiple sessions is automatically treated as `NEW_PACKAGE` when newly purchased.
- A pending enrollment is created at Booking/Walk-In creation and follows the same line through Queue and Visit.
- A package added during Servicing can also create a pending enrollment.
- Cancelling the package before checkout cancels the pending enrollment without deleting history.
- Completing the purchase session records a pending package-session ledger movement.

### Financial activation
At Sale financial finalization, each PACKAGE Sale Item activates its linked Package Enrollment. One Service Credit is created for that enrollment. Repeat package purchases never stack into one entitlement row.

Entitlement is independent of payment: a finalized package can be Active while its originating Sale Item remains On Account.

### Service Balances
The Operations > Service Balances page is activated. It shows one row per enrollment with Customer, Service, Purchased, Used, Remaining, Collected, Balance and Status. Clicking a row opens package payment monitoring and payment history.

## Explicit Phase 5B boundary
Phase 5A does not yet expose an existing Service Credit as a selectable source in Booking/Walk-In. Session redemption, Service Credit usage writes, package-balance cash redirection and final harmonization of Advance/Interim/Checkout settlement remain Phase 5B.

### Package selection UX
Booking, Walk-In and Servicing service selectors identify multi-session services with their session count. Package quantity is fixed to one purchase line because the configured `number_of_sessions` defines the entitlement; operational quantity is not used to multiply package sessions.
