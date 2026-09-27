# YODAMEN Update 82C - Compensation Regression Fixture Fix

This patch changes only the Personnel feature test fixture introduced in Update 82A.

The failing test previously inserted a commission earning with fake `sale_id` and `sale_item_id` values while attempting to disable foreign keys. SQLite keeps foreign-key enforcement active inside the RefreshDatabase transaction, causing the test to fail before the compensation-date rule could be tested.

Update 82C creates a valid Sale and Sale Item first, then inserts the pending commission against those real parent rows.

No production business logic, frontend code, or database migration is changed.
