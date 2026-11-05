# Fresh installer verification November 5 2026

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
