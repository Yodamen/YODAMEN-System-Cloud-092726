# Update 79 — HR, Sales Activation, and Inventory UX

## Scope

### HR / Payroll
- OT multiplier accepts any positive decimal value, including values below 1 (examples: 0.25, 0.50, 0.75).
- Personnel without leave entitlement no longer see the Leave self-service tab.
- Backend leave submission also blocks LEAVE requests for personnel who are not leave-entitled.
- Day Off self-service opens on the Admin-assigned weekly work schedule.
- Day Off requests are filed through a compact Reschedule action and modal.

### Sales
- Sales navigation order is Dashboard, Transaction, Retail POS, Z-Reading.
- Sales Dashboard is activated with live finalized-sales reporting.
- Z-Reading is activated with preview, close, and auditable reopen flows.
- Existing Manager and Reception roles receive Sales Dashboard VIEW access where applicable.

### Inventory
- Product Masterlist filter bar is compact, with a shorter Search field and Apply/Reset inline.
- Product loading clears stale error state so an old "Unable to load products" message is not retained after a successful refresh.
- Add/Edit Product removes the two requested explanatory helper lines and uses a cleaner aligned grid.
- Stock Type is persisted as INVENTORY or NON_INVENTORY and can be filtered in the Masterlist.
- Non-inventory products are excluded from tracked-stock adjustments and Retail POS product options.

## Database migrations
- 2026_09_19_005100_activate_sales_dashboard_access.php
- 2026_09_19_005200_create_z_readings.php
- 2026_09_19_005300_add_stock_type_to_retail_products.php

Note: if 2026_09_19_003000_strengthen_hr_controls is still pending, `php artisan migrate` will apply it first.

## Verification completed before packaging
- PHP syntax checks passed for changed backend files.
- Laravel route registration verified for `/api/v1/sales-dashboard` and `/api/v1/z-readings/*`.
- Full PHPUnit/Pest execution could not be run in the packaging environment because its PHP runtime lacks DOMDocument.
- Frontend production build could not be completed in the packaging environment because the uploaded macOS node_modules does not contain the Linux Rolldown native binding. Run npm install/build on the target Mac after applying the patch.
