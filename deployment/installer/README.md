# Yodamen installation on a new computer

This installer contains the updated React frontend and Laravel backend with tier
activation, a 50-activity Premium trial and permanent Basic/Standard/Premium paid
plans. Use this for a NEW Docker installation. Use the separate patch package for
an app already installed with an older release. Do not extract over that app.

## Files to prepare

- This installer archive, extracted into a permanent local folder.
- Complete SQL dump folder containing the schema, migration history and accounts.
- Original backend/storage/app folder for uploaded logos, photos and attachments.
- Original backend .env when preserving its APP_KEY is required for encrypted data.

The dump, uploads and original .env are NOT inside the shareable installer. Transfer
those privately. The publisher signing key and code generator are not deployed.
No account/password is created or reset by the installer; imported accounts remain.

## Windows 11

Install WSL Ubuntu, finish its first-run username/password setup, and use WSL 2.
Install Docker Desktop, enable its Ubuntu integration and Linux containers, then
wait for the engine to run. Administrator PowerShell for first-time WSL setup:

```powershell
wsl --install -d Ubuntu
```

Extract this archive into `C:\Yodamen`. Double-click `Install-Yodamen.cmd`. Enter
full Windows paths for the SQL dump folder, optional original storage/app folder,
and optional original backend .env. Do not enter an Illustrator logo source file
as the uploads folder. The Windows launcher invokes the same Linux installer in
WSL; PHP, Node and MySQL do not need separate Windows installations.

The Windows launcher requires target-machine acceptance; Mac Docker testing does
not verify Windows permissions, path conversion or WSL integration.

## Mac

Install Docker Desktop for the Mac processor type, open it and wait for the engine.
Extract to a permanent folder such as `~/Yodamen`. Double-click `Install.command`
and answer its prompts. Or, in Terminal, use actual paths:

```sh
cd "$HOME/Yodamen"
bash install.sh --dump "$HOME/Yodamen-Data/Dump20261101" \
  --uploads "$HOME/Yodamen-Data/storage-app" \
  --key-file "$HOME/Yodamen-Data/original-backend.env"
```

Omit optional inputs only when unavailable. A new APP_KEY is generated if the
original is not supplied; existing encrypted data may require the original key.

## First startup and activation

Docker must be running and the first build needs internet access. The installer
checks the archive files, creates private installation credentials, imports only
an empty database, restores uploads, applies pending migrations and checks startup.
An incomplete SQL import is blocked from automatic retry to avoid treating a
partial database as a finished installation. It never runs migrate:fresh or seeds
replacement accounts. Review a failed import with the deployment contact.

Open `http://localhost:8080` on the installed computer. Sign in with an imported
account and verify records and images. If the page was already open during setup,
refresh and sign in again if requested. Account recovery, when needed:

```sh
docker compose exec app php artisan yodamen:super-admin-recover
```

Open Admin > Subscription as Super Admin. Send the displayed Installation ID,
Business code and current revision to the publisher. The publisher issues a code
on their own computer. Paste it into Activation code and click Activate license.
Paid Basic, Standard and Premium plans are permanent. A new unactivated business
gets 50 Premium activities; imported historical bookings/sales are excluded. A
booking counts once and its linked sale does not count again; standalone sales
share that limit. At the limit, records remain viewable but writes need activation.
A restored database that already contains license identity, revision or usage keeps
that state; reinstalling does not reset it.

## Reruns backups and updates

Keep the installed folder, `.env`, `runtime.env` and Docker volumes. Rerunning the
same installer starts the same release and preserves accounts, key, license,
counters and uploads; it does not reimport the dump or reapply first-install
migrations. If setup stopped before completing a first import, read the error and
contact the developer. Never delete volumes to retry an import.

From the installed folder, run `bash backup.sh` in Terminal on Mac or Ubuntu on
Windows and copy the backup off the server. Backups contain credentials and business
data. Test restoration before relying on them. Use the separate update package
for later application releases; do not install over existing data.

The default project is `yodamen` and port is 8080. For a separate test installation,
use `--project yodamen-test --port 8081` on the first run. The installer refuses to
reuse an existing project/volume with no matching local configuration. Keep Docker
running and the server awake. The default listens on localhost only. LAN access,
HTTPS and Tailscale are separate configurations, not enabled by this installer.
