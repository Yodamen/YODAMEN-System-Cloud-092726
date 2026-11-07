# Browser onboarding verification November 5 2026

Current installer: yodamen-installer-20261105-onboarding-02.tar.gz. Clean installation
now enables protected browser onboarding by default; terminal/JSON setup and SQL
restore remain available. Source changes are uncommitted on codex/subscription-tiers
and the package manifest records explicit inclusion of new untracked app files.

## Passed

- 48 focused backend tests for one-time setup, invalid/expired codes, confirmation,
  completed-setup lockout, rollback, request throttling, clean initialization,
  authentication and paid/trial access; 4 frontend tests, changed-file lint and build.
- Extracted and built the actual installer, ran all migrations on empty MySQL 8.4,
  and enabled onboarding without importing a dump or creating a default user.
- Verified private setup file permissions, SHA-256-only token storage, HTTP setup
  status, the /setup page, CSRF rejection, bad-code rejection and confirmation errors.
- Completed setup through the real HTTP endpoint, creating one chosen business,
  branch and Super Admin with automatic session sign-in and 50 unused trial activities.
  No customers, bookings or sales were created. Bootstrap and auth/me returned 200.
- Confirmed token consumption and replay rejection, permanently closed setup after
  ownership exists, and successful signed permanent Basic activation afterward.
- Reruns preserved keys, owner, identity and license, and cleared the consumed local
  plaintext code. Restart preserved the session and kept setup closed.
- Expired a disposable pending code, refreshed it through the installer and proved
  the previous code no longer worked. Required setup remained open for the new code.
- Rehearsed CLI/JSON setup and SQL restore on macOS Bash 3.2. Replaced empty-array
  argument expansion with explicit branches for that shell's nounset behavior.
- Original app/database remained unchanged. Disposable tests were stopped. A separate
  empty review preview is intentionally running on port 8081; no owner exists until
  the reviewer completes its form. The private code is in that preview's .install-state.
- Archive manifest/exclusion, shell/PHP/Python syntax and Git whitespace checks passed.

This is per-installation setup, not public multi-client signup, payment processing
or automatic activation-code issuance. Windows launchers include the setup options
but still require Windows 11 target-machine acceptance. Visual/browser acceptance
of the form is part of the review preview; API/session/security flows were exercised
programmatically. No client account or access code was reset.

---

# Earlier clean CLI installation verification November 5 2026

Current archive: yodamen-installer-20261105-clean-02.tar.gz. Includes the current
working app changes and new initialization classes, explicitly recorded as
uncommitted/untracked app source in release.json. No commit or push was performed.

## Current clean and restore checks passed

- 39 focused backend tests covering clean setup, rollback, refusal to overwrite an
  existing owner/business, input validation, confirmation, licensing and login.
- Built the actual archive on Mac Apple Silicon with Docker Desktop and ran the
  complete migration chain against an EMPTY MySQL 8.4 database. No schema or SQL
  dump was imported. Fixed one legacy index and three foreign-key names longer
  than MySQL's identifier limit; existing migrated databases do not rerun them.
- Created only the chosen business, main branch, roles, permissions, reference
  settings, default chart of accounts and one Super Admin. Customers, bookings,
  sales, personnel, services, products, suppliers, payments, inventory movements,
  expenses and payroll remained empty. The Premium trial began at zero of 50.
- Verified real HTTP session/CSRF login, bootstrap, Subscription and permanent Basic
  activation using a publisher-signed code matching the new business/identity.
- Duplicate initialization and changing an existing mode were refused. Reruns and
  restarts preserved credentials, key, administrator, identity and accepted license.
- Invalid initial credentials left no business/user. Correcting the private setup
  file and retrying the same migrated database succeeded without regenerating keys
  or deleting tables. A simulated seeding failure rolled back in the backend test.
- Rehearsed SQL restore with synthetic historical records, original APP_KEY and
  uploaded fixture. Pending migrations completed and reruns preserved that data.
- Archive exclusions and checksums passed; shell, PHP, Python and Git whitespace
  checks passed. Test containers were stopped. Original app/database unchanged.

Windows 11 launchers include clean/restore selection but have not been executed
on Windows. Target-machine acceptance remains necessary. This is CLI first setup;
no public signup/onboarding webpage or automated billing was added.

---

# Earlier restore-only installer verification November 5 2026

Final archive: yodamen-installer-20261105-02.tar.gz. Uses the current working app
snapshot on codex/subscription-tiers, including uncommitted permanent-license
changes. release.json records the base commit, app digest and working-copy status.
No Git push or client installation was performed while creating this package.

## Passed on Mac Apple Silicon with Docker Desktop

- Inspected the actual archive and verified every payload checksum. Environment
  files, SQL dumps, uploads, publisher tools and signing keys are excluded.
- Built the combined React/Laravel image with PHP Sodium.
- Imported synthetic records from a complete pre-licensing database snapshot;
  applied pending migrations automatically without seeding or resetting accounts.
- Preserved imported users, customers, bookings and sales. Historical activities
  were excluded from trial counting and a new installation identity was assigned.
- Restored an uploaded fixture and preserved a supplied original APP_KEY. Created
  configuration files with mode 600. Separately tested generating a fresh key.
- Verified HTTP session/CSRF login, /auth/me, bootstrap, Subscription, code generation
  on the publisher host and permanent Premium activation over the HTTP API.
- Reran installation with and without original input arguments. Credentials, key,
  uploads, records, identity, activated license, accepted revision and nonzero
  trial counter were preserved; no dump was reimported.
- Created a database/uploads/configuration backup and verified its files.
- Restarted the app and verified HTTP session and subscription access afterward.
- Rejected a changed package file before creating configuration or importing data.
- Injected invalid SQL after a first table creation. The app stayed closed and a
  retry refused to treat that partial import as a completed database.
- Original yodamen-trial container stayed running and unchanged. Disposable test
  containers were stopped; test volumes and private fixtures were retained.
- Shell/PHP syntax and Git whitespace checks passed.

An initial GitHub dependency download returned HTTP 504. Retrying succeeded before
any SQL import. This remains a source installer requiring Docker and internet.

## Acceptance still required

Windows 11 launchers are included but were not executed on Windows. Test WSL path
conversion, permissions, Docker integration and the image build on that target.
Verify the client's UI workflows, real uploads and restoration procedure with
approved data. LAN/HTTPS/Tailscale are separate and not enabled by this installer.

Full backup recovery was exercised for the shared Docker layout during the patch
rehearsal; see ../patch/VERIFICATION.md. The installer backup command itself was
verified here, without claiming another full restore rehearsal.
