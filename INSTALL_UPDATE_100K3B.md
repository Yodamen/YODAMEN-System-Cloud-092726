# YODAMEN Update 100K3B — Development Reset Navigation Wiring

## What this fixes

Update 100K3 included the Development Reset page, Super Admin route guard, backend endpoints, and service, but the package omitted the existing frontend router/navigation files that actually expose the page in the application.

This patch wires the already-installed feature into the UI:

- Adds `/admin/development-reset` to the React router.
- Wraps the route in `SuperAdminRoute`.
- Adds **Development Reset** under **Admin**.
- Hides the navigation item from non-Super-Admin accounts.

No database migration is included.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100K3B-DEVRESET-NAV-WIRING.zip -d .

cd frontend
npm run build
```

If the frontend dev server is already running, Vite should reload automatically. If it does not, stop and restart `npm run dev`.

## Verify

Sign in as a Super Admin and expand **Admin**. You should see:

- Accounting Mapping
- Development Reset
- Users & Roles

Open **Development Reset** and confirm the page loads. Non-Super-Admin accounts should neither see the menu item nor be able to open the route directly.
