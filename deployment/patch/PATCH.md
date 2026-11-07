# Update an existing Yodamen Docker installation

This patch adds tier activation, permanent Basic/Standard/Premium plans and the
50-activity unactivated Premium trial. It updates backend and frontend together.
It is for installations created by the existing Yodamen Docker installer, with
`compose.yaml`, `.env` and `runtime.env`, database sessions, and app/db services.
Custom Compose overrides require a separate review. It does not install a fresh
database, import a dump, seed accounts or generate a new APP_KEY.

## Before starting

Schedule a quiet period. Keep Docker Desktop running, provide internet for image
build dependencies, and allow enough disk space for a new image plus a database
and uploads backup. Obtain an independent off-machine backup. Keep the running
installation folder intact; extract this patch into a DIFFERENT folder. Never
extract this patch over the existing installation. Use trusted release files.

This is a source package, not an offline Docker image installer. Its release.json
records the source hash, Git base commit and whether uncommitted app changes were
included. Review those changes and rehearse the patch before client rollout.

## Windows 11

Extract into a separate local folder such as `C:\Yodamen-Patches\release-name`.
Double-click `Update-Yodamen.cmd`. Enter the EXISTING installed folder, for example
`C:\Yodamen`. WSL Ubuntu and Docker Desktop integration must already be ready.
No database dump, uploads folder or original key is requested.

For a preflight only, in PowerShell from the patch folder:

```powershell
.\Update-Windows.ps1 -InstallationFolder 'C:\Yodamen' -Check
```

If a downloaded script is blocked, review the source and use the approved Windows
unblock process; do not change system-wide execution policy.

## Mac

Extract the patch separately. Double-click `Update.command` and give the existing
installed folder, or run in Terminal from the EXTRACTED PATCH folder:

```sh
bash update.sh --installation "$HOME/Yodamen" --check
bash update.sh --installation "$HOME/Yodamen"
```

The preflight checks the selected running installation and payload checksums. It
does not prove that the Docker image build or migrations will succeed.

## What the updater does

1. Verify payload checksums and the existing Compose installation; refuse a custom
   override rather than overwrite it. A directory lock prevents simultaneous patches.
2. Copy the release under the installed folder's `.yodamen-releases`, assign a new
   image tag, and build while the old app remains available. A rollback image tag
   preserves the previous local image.
3. Put the old app into maintenance and stop it. Back up the database, uploaded
   files, configuration, Compose files and previous image identity under `backups`.
   A BACKUP-COMPLETE file is written only after dump/archive checks succeed.
4. Save a managed `compose.override.yaml` that points to the new image and release
   source. Database and uploads volume identities and port settings are preserved.
   A new persistent framework volume keeps maintenance state across containers.
5. Keep maintenance enabled, apply pending migrations, clear caches, and verify
   migration and license state. No trial reset or license replacement is performed.
6. Start the new app, reopen it, check HTTP health, and save APP_VERSION in the
   existing `.env`. `runtime.env`, including APP_KEY, remains byte-for-byte unchanged.

The health check is a startup check. The operator must verify login, existing
records, images, allowed/restricted modules and Admin > Subscription. Keep the
backup and previous image until the release is accepted. A dated paid code is
permanent in this release. The 50-use trial is not a calendar trial.

## If the patch stops

A build failure leaves the running app available. After shutdown starts, a backup
or migration failure leaves the app closed. A failed HTTP health check closes it
again. Do not run the installer, import the original dump, run migrate:fresh, delete
volumes or use `docker compose down -v`.

Backups contain credentials and business data; transfer them privately off-machine.
If BACKUP-COMPLETE is missing, the backup is incomplete. Preserve the existing
volumes and investigate rather than attempting a destructive restore.

If the new schema is valid, fix the error and complete the pending migration and
verification steps in maintenance under developer supervision. If reverting, use
the previous image AND its matching pre-patch database/uploads/configuration backup.
An old image alone does not undo database migrations. The rollback image tag and
image ID are in the backup. Do not prune Docker images during recovery.

For a supervised full recovery, stop the app, restore the database into an empty
database with the same name, replace the uploads from uploads.tar.gz, restore the
backup's .env/runtime.env/compose.yaml and its override if present. If no override
existed before this patch, MOVE the newly managed override aside. For a pre-patch
installation with an existing framework volume, clear its maintenance flag only
after validating the restored app. Verify configuration, record counts, uploaded
files and login before reopening access. Retain the failed release for diagnosis.

A stale `.yodamen-update-lock` after power loss must be reviewed; remove only the
empty lock after confirming no updater is running. Failed builds leave their
release folder for inspection. Move that failed folder aside before retrying the
same release; do not overwrite a release that an installed override references.

## Build another patch on the publisher computer

From the actual app repository (Python 3 required):

```sh
python3 deployment/patch/package-patch.py \
  --output /private/path/releases --version patch-YYYYMMDD-N
```

The builder copies tracked backend/frontend working files, including tracked edits,
and records their digest. New untracked app files must be staged/committed first
or explicitly included with --include-untracked for a test build. It excludes runtime environment files, uploads, database
dumps, dependency folders and publisher tools/private signing keys. Source scripts
live in the app repository; packaged snapshots are deployment artifacts.
