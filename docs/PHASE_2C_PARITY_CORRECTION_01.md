# YODAMEN Phase 2C Parity / UI Correction 01

## Purpose

Consolidates user-review corrections found across Phase 1B through Phase 2C before Phase 2C acceptance. This package is applied on the current Phase 2C development working tree. It must not move `stable/approved` until local tests and browser validation pass.

## Corrections included

- Customer Registration Date display uses `Mmm. #, ####` presentation, e.g. `Sep. 11, 2026`.
- Customer registration requires only First Name and Last Name. Mobile, birthday, email, gender, lead source, address, registration date input, and notes are optional user inputs. Registration date defaults to the YODAMEN business date when omitted.
- Duplicate customer controls remain active when identifying data is supplied:
  - normalized Mobile Number duplicate blocks save;
  - normalized First Name + Last Name + exact Birthday duplicate blocks save.
- Current table Action columns use compact dropdown controls instead of multiple inline action links.
- The application top bar no longer repeats Business Name and Business Date on every module.
- Module headers now retain the module/submodule name without eyebrow/subheadline copy.
- System-wide spacing, typography, inputs, tables, cards, sidebar, top bar, and modals are compacted for a sleeker UI.
- Bookings tabs are exactly Active / Completed / Cancelled.
- Queuing and Servicing status tabs are removed and replaced with compact Status filters.
- Personnel OT entitlement is grouped in its own full-width Overtime Eligibility section immediately before Service & Commission Eligibility.
- Services & Pricing removes the standalone Price action. Price scheduling and full effective-dated Price History are available inside Edit Service.

## Database change

Migration:

`2026_09_12_000510_make_customer_optional_fields_nullable.php`

Changes existing customer columns to nullable:

- `mobile_number`
- `mobile_normalized`
- `birthday`

No finalized financial tables are affected.

## Validation

Before accepting the correction:

1. `php artisan migrate`
2. `php artisan test`
3. `npm run lint`
4. `npm run build`
5. Browser-check Customers, Bookings, Queuing, Servicing, Personnel, Reference Lists, and Services & Pricing.
6. Confirm `stable/approved` remains on Phase 2B until Phase 2C plus this correction is accepted.
