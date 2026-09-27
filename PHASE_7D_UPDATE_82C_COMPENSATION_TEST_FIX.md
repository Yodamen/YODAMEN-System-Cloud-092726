# Phase 7D - Update 82C Compensation Test Fixture Fix

## Purpose
Align the Update 82A pending-commission regression test with SQLite foreign-key enforcement.

## Change
- Removed the foreign-key-disable workaround from `PersonnelTest`.
- Creates valid `sales` and `sale_items` parent rows before creating the test `commission_earnings` row.
- Keeps the same business assertion: OPEN commission activity dated 2026-09-21 blocks a compensation term effective on 2026-09-21 and permits 2026-09-22.

## Runtime impact
None. Test-only patch.
