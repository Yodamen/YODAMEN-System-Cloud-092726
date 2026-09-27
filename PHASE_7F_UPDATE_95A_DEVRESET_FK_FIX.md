Update 95A hardens full DEVRESET against restrictive transactional foreign keys.

Fix:
- Temporarily disables FK enforcement only for the full-reset deletion window.
- Deletes every table in the declared transactional reset set.
- Verifies every declared transactional table is empty before the reset is accepted.
- Always re-enables FK enforcement in a finally block, including on failure.
- Preserved master/reference/setup tables remain untouched.
- Identity and transactional code-sequence reset behavior from Update 95 remains unchanged.
- Regression coverage now includes commission_earnings -> sale_items RESTRICT foreign-key data.

No database migration is required.
