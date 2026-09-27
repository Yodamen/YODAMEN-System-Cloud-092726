# YODAMEN Phase 6 Protocol Alignment 04

## Authority

This update aligns the current React/Laravel foundation to the approved Commission + Compensation + Partner / Profit Sharing protocol supplied on 2026-09-17. The protocol is the business-rule source of truth for the downstream chain:

Booking → Visit → Visit Service → Sales → Commission → Daily Compensation → Payroll

and, separately:

Personnel → Accounting → Distributable Profit → Profit Sharing → My Profit

## Immediate corrections in this update

1. Commission-rule staff targeting now stores `personnel_id` rather than `employee_id`.
   - Existing `employee_id` values are copied into `personnel_id` by migration before the old column is removed.
   - `applies_to` remains `EMPLOYEE / POSITION / ALL` to preserve the approved rule hierarchy.
   - The frontend labels the specific-person rule as `Specific Personnel` so Partners who are commission eligible can also be targeted.

2. Managing Partner no longer automatically becomes Profit Sharing Eligible.
   - `Position = MANAGING_PARTNER` still enforces `Personnel Type = PARTNER`.
   - Profit Sharing eligibility remains an independent explicit setting.
   - A Managing Partner can therefore have `Operational Compensation = NONE`, remain commission eligible, and either participate or not participate in Profit Sharing.

3. Profit Sharing validation is explicit.
   - When Profit Sharing Eligible is enabled, Percentage and Effective From are required.

4. Flexible Commission Rules from Update 03 are preserved.
   - Employee/Personnel + Specific Service
   - Employee/Personnel + Category
   - Employee/Personnel + All Services
   - Position + Specific Service
   - Position + Category
   - Position + All Services
   - All Staff + Specific Service
   - All Staff + Category
   - General Default
   - Optional Branch specificity
   - Priority tie-breaker
   - Percentage and Fixed methods

## Commission posting state

Commission posting remains OFF in this update.

Do not create commission at Service Completion. Posting will only be implemented after the remaining prerequisite layers are aligned with the protocol.

## Required implementation sequence from this point

Current status:
- Personnel Master: present, aligned by this update.
- Commission Rules: present, aligned by Updates 03 + 04.

Still required before Commission Posting:
1. Attendance
2. Operational Compensation engine
3. Sales-confirmed Commission Posting + Reversal integrity
4. Daily Compensation snapshots
5. Cash Advances + Adjustments
6. Payroll Runs
7. My Payroll
8. Accounting foundation
9. Profit Sharing
10. My Profit

The next build should therefore start Attendance rather than skipping directly to commission posting.

## Files to ADD

- `backend/database/migrations/2026_09_17_002200_align_commission_rules_to_personnel.php`
- `PHASE_6_PROTOCOL_ALIGNMENT_04.md`

## Files to REPLACE

- `backend/app/Models/CommissionRule.php`
- `backend/app/Services/Commissions/CommissionRuleService.php`
- `backend/app/Http/Requests/Api/V1/Admin/SaveCommissionRuleRequest.php`
- `backend/app/Http/Requests/Api/V1/Hr/SavePersonnelRequest.php`
- `backend/app/Services/MasterData/PersonnelService.php`
- `backend/tests/Feature/Commissions/CommissionRuleTest.php`
- `backend/tests/Feature/MasterData/PersonnelTest.php`
- `frontend/src/pages/admin/CommissionRulesPage.jsx`
- `frontend/src/pages/hr/PersonnelPage.jsx`

## Files to NOT CHANGE

- Sales settlement logic
- Payment Applications
- Service Credit logic
- Package logic
- Transaction-tab classification
- Booking/Queue/Visit lifecycle
- Existing Payroll/Accounting placeholders

## Migration

Required:

`php artisan migrate`

Do not use `php artisan migrate:fresh`.

## Validation

Focused:

`php artisan test tests/Feature/MasterData/PersonnelTest.php`

`php artisan test tests/Feature/Commissions/CommissionRuleTest.php`

Then full suite:

`php artisan test`

Frontend:

`npm run lint`

`npm run build`
