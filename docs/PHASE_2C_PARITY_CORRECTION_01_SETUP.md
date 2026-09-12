# Phase 2C Parity / UI Correction 01 - Installation

Apply this package on top of the current Phase 2C development working tree. Do not reset back to Phase 2B first.

## Git safety

`stable/approved` should remain at Phase 2B checkpoint `8b8d3c1` until Phase 2C and this correction are accepted together.

The `development` working tree is expected to already contain uncommitted Phase 2C files.

## Install

1. Stop Laravel and Vite (`Control + C`).
2. From project root, extract the correction ZIP over the project.
3. Go to backend and run:

```bash
php artisan optimize:clear
php artisan migrate
php artisan test
```

Expected test count from the current package baseline: **42 passed**.

4. Go to frontend and run:

```bash
npm run lint
npm run build
npm run dev
```

5. Start Laravel again:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

## Browser validation

### Customers
- Registration date displays like `Sep. 11, 2026`.
- First Name and Last Name are the only required registration inputs.
- Blank Mobile and blank Birthday can be saved.
- Duplicate Mobile blocks save when a mobile is supplied.
- Duplicate First Name + Last Name + Birthday blocks save when birthday is supplied.
- Action column uses a dropdown.

### Global UI
- Top bar no longer repeats Business Name / Business Date.
- Module headers show only the module name.
- Typography, cards, inputs, tables, buttons, sidebar and modals are more compact and uniform.

### Bookings
- Tabs are exactly Active / Completed / Cancelled.
- Completed history loads under Completed.
- Action column uses a dropdown.

### Queuing
- No status tabs.
- Status uses a dropdown filter.
- Action column uses a dropdown.

### Servicing
- No status tabs.
- Status uses a dropdown filter.

### Personnel
- OT Entitled and OT Multiplier are inside a full-width Overtime Eligibility section.
- Overtime Eligibility appears immediately before Service & Commission Eligibility.
- Action column uses a dropdown.

### Services & Pricing
- No standalone Price action in the table.
- Edit Service contains Pricing controls.
- Edit Service contains historical/effective-dated Price History.
- Action column uses a dropdown.

### Reference Lists
- Action column uses a dropdown.

## Acceptance

Do not move `stable/approved` or commit Phase 2C as approved until the user confirms the corrected UI and workflows.
