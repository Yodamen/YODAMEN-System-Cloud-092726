# Phase 7D — Update 81: Cash Advance UX, Payment History & Completion Status

## Scope

1. Correct repayment and write-off modal layout.
2. Make cash-advance transactions clickable.
3. Surface the full cash-advance ledger in a transaction detail modal.
4. Distinguish completion status by actual closure method.

## Status logic

- `DRAFT`: not yet approved.
- `ACTIVE`: approved and still has an outstanding balance.
- `FULLY_PAID`: closed through manual repayments and/or payroll deductions with no write-off entry.
- `WRITTEN_OFF`: closed with a `WRITE_OFF` ledger entry.

The persisted database status remains compatible with the existing workflow (`DRAFT`, `ACTIVE`, `CLOSED`). The API adds `display_status` for user-facing behavior, avoiding a destructive schema/status migration.

## Payment history

The transaction detail view shows:

- Advance Issued
- Manual Repayment
- Payroll Deduction
- Written Off
- notes/reference
- linked payroll run ID when applicable

`payment_total` includes manual repayments and payroll deductions only. `written_off_total` is reported separately.

## Regression coverage

Cash Advance tests now verify that Fully Paid and Written Off are distinguishable and filterable through the API.
