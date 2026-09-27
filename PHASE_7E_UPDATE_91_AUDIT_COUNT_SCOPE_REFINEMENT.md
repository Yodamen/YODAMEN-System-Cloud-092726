# Update 91 - Audit Count Scope Refinement

This update converts Audit Count scope selection into a stock-type driven workflow.

Included:
- Count ID is plain text; View remains under Action.
- Count date is displayed as a readable date, with Posted/Finished time as a subheadline.
- New Audit Count scope choices: Full Count, Inventory, Non-inventory, Asset.
- Inventory / Non-inventory / Asset scopes expose a scrollable multi-select category dropdown with a Full Count option.
- Matching items are previewed in a checkbox table before the blind count is created.
- User can include/exclude individual items before starting the count.
- Audit count history adds Stock Type / Count Scope and dependent Category filtering.
- Scope labels include Full Count, Full Count - Inventory, Full Count - Non-inventory, Full Count - Asset, plus category/selected-item scopes.
- Audit Count can now create/count quantity snapshots for active Inventory, Non-inventory, and Asset masterlist items. Other operational stock flows remain unchanged.
- Existing legacy FULL/CATEGORY/SELECTED count API payloads remain supported.
