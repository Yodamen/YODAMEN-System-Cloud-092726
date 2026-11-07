# Patch verification November 5 2026

Candidate archive: yodamen-patch-20261105-06.tar.gz. Includes the current tracked
working edits on codex/subscription-tiers (base commit 409d4f8); inspect release.json
for the complete source digest. No commit, branch promotion or client update was
performed by packaging it.

## Passed on macOS Apple Silicon and Docker Desktop

- Extracted the actual archive and checked it for runtime environment files,
  SQL dumps, uploads, dependency folders and publisher/signing key files.
- Built the new image with PHP Sodium and the combined React/Laravel application.
- Upgraded a disposable installation using the existing installer's database
  schema and migration metadata, populated with synthetic records only.
- Applied the two licensing migrations without losing users, customers, bookings
  or sales. Existing bookings were marked historical and trial usage stayed zero.
- Preserved runtime.env byte-for-byte, the application key and an uploaded fixture.
- Created an installation ID, saved the new APP_VERSION and restarted successfully.
- Applied a second patch after activating a permanent Standard license and setting
  a nonzero trial count. UUID, accepted code/revision, usage and records survived.
- Invalid state JSON caused the verifier to return failure rather than continue.
- Injected a migration failure after creating a test table. The updater kept the
  app stopped, did not advance the saved version, and retained a complete backup.
- Restored matching database, uploads and configuration from that backup. Verified
  the license, key, counters and records, and confirmed the partial DDL was removed.
- Original yodamen-trial app stayed running. Disposable containers were stopped;
  test volumes and private backups were retained under the rehearsal workspace.
- Shell/PHP syntax checks and Git whitespace checks passed.

## Remaining acceptance

Windows launcher files are included but have not been run on Windows 11. Test the
launcher and target-machine image build before client rollout. A health endpoint
is not a full UI acceptance test: verify login, images, modules and the client's
normal business workflows. LAN, Tailscale and fresh installation are separate.

The old pre-licensing migration chain has an overlong MySQL index name when run
from an empty database. The existing installer uses an imported schema. This
rehearsal used that schema plus its migration history to test the supported update
path; it did not modify that unrelated old migration.

Subsequent clean-install work shortened the legacy MySQL index and foreign-key
names that prevented migration from an empty database. See the current installer
verification report for the successful full-MySQL migration rehearsal.
