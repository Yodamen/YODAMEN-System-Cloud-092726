# Yodamen installation on a new computer

This installer contains the updated React frontend and Laravel backend with tier
activation, a 50-activity Premium trial and permanent Basic/Standard/Premium paid
plans. Use this for a NEW Docker installation. Use the separate patch package for
an app already installed with an older release. Do not extract over that app.

## Choose clean setup or restore

For a NEW client with no business data, choose **clean** when the launcher asks
for the installation type. No SQL dump, uploads or original key is needed. The
installer creates the tables and prints a private one-time setup code. Open the
printed /setup address in your browser, paste that code, and enter your business
name/code, main branch and first Super Admin credentials. Setup creates the required
reference settings, roles and permissions. Access-code entry is hidden and confirmed. It creates no customers, transactions,
services, products, staff or payroll records. Default reference categories and chart
of accounts are settings, not opening balances or transactions.

For an existing client's data on a new computer, choose **restore** and supply the
files below. An already installed older app should use the separate patch package.

## Files to prepare for restore

- This installer archive, extracted into a permanent local folder.
- Complete SQL dump folder containing the schema, migration history and accounts.
- Original backend/storage/app folder for uploaded logos, photos and attachments.
- Original backend .env when preserving its APP_KEY is required for encrypted data.

The dump, uploads and original .env are NOT inside the shareable installer. Transfer
those privately. The publisher signing key and code generator are not deployed.
Restore preserves imported accounts. Clean setup creates only the administrator
whose credentials you choose.

## Windows 11

Install WSL Ubuntu, finish its first-run username/password setup, and use WSL 2.
Install Docker Desktop, enable its Ubuntu integration and Linux containers, then
wait for the engine to run. Administrator PowerShell for first-time WSL setup:

```powershell
wsl --install -d Ubuntu
```

Extract this archive into `C:\Yodamen`. Double-click `Install-Yodamen.cmd`, choose clean or restore. For restore, enter
full Windows paths for the SQL dump folder, optional original storage/app folder,
and optional original backend .env. Do not enter an Illustrator logo source file
as the uploads folder. The Windows launcher invokes the same Linux installer in
WSL; PHP, Node and MySQL do not need separate Windows installations.

The Windows launcher requires target-machine acceptance; Mac Docker testing does
not verify Windows permissions, path conversion or WSL integration.

## Mac

Install Docker Desktop for the Mac processor type, open it and wait for the engine.
Extract to a permanent folder such as `~/Yodamen`. Double-click `Install.command`
and choose clean or restore. For a new client, run:

```sh
cd "$HOME/Yodamen"
bash install.sh --clean
```

Open the printed /setup URL, paste the setup code and complete the business and
Super Admin forms. There is no shared/default Super Admin password. The setup code
is separate from a paid-plan activation code. In restore mode, use actual paths:

```sh
cd "$HOME/Yodamen"
bash install.sh --restore --dump "$HOME/Yodamen-Data/Dump20261101" \
  --uploads "$HOME/Yodamen-Data/storage-app" \
  --key-file "$HOME/Yodamen-Data/original-backend.env"
```

Omit optional inputs only when unavailable. A new APP_KEY is generated if the
original is not supplied; existing encrypted data may require the original key.

## First startup and activation

Docker must be running and the first build needs internet access. The installer
checks the archive files and creates private installation credentials. Clean mode
migrates an empty database and enables first-time browser setup; restore mode
imports only an empty database and restores uploads. Both apply pending migrations
and check startup.
An incomplete SQL import is blocked from automatic retry to avoid treating a
partial database as a finished installation. It never runs migrate:fresh or seeds
replacement accounts. Review a failed import with the deployment contact.

Open `http://localhost:8080` on the installed computer. Sign in with the chosen Super Admin (clean setup) or an imported
account (restore), then verify the business settings or restored records/images. If the page was already open during setup,
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

## Browser setup protection

The installer generates a random 64-character setup code and stores it privately
in .install-state/setup-token.txt (mode 600). The backend stores only its SHA-256
hash with a 24-hour expiry. Paste the code into the form; it is never put in a URL
or browser local storage. Keep the printed code private and do not post it publicly.

The business and first owner are created in one transaction, together with consuming
the code. Setup closes as soon as a business/user exists. The created owner is signed
in and sees the 50-use Premium trial and activation details. Existing installations
never open registration automatically. Setup requests require CSRF protection and
are rate limited. There is no self-service payment or automatic license issuance.

If a pending setup code expires, rerun the installer to refresh it. After successful
onboarding, rerunning the installer clears its local plaintext setup-code file.
The consumed code cannot be reused even if the file has not yet been cleared.
For supervised recovery before any business exists, the local command
`docker compose exec app php artisan yodamen:setup-token` issues a replacement code;
it refuses to reopen an initialized app. Never expose a setup token in a URL.

## Command line and scripted clean setup

Use `bash install.sh --clean --cli-setup` to create the first owner through terminal
prompts instead of the browser. Windows supports `-Clean -CliSetup` on
Install-Windows.ps1. A private --setup-file also selects command-line setup. Keep
the same setup method when rerunning a pending installation.


For supervised automation, keep a private setup JSON file outside Git and outside
the extracted installer, with these fields:

```json
{
  "business_name": "Client Salon",
  "business_code": "CLIENT",
  "branch_name": "Main Branch",
  "username": "owner",
  "display_name": "Business Owner",
  "access_code": "REPLACE_WITH_A_PRIVATE_ACCESS_CODE"
}
```

Use a unique private code of at least six characters. On Mac/Linux, restrict the
file using `chmod 600 /private/path/setup.json`. Run:

```sh
bash install.sh --clean --setup-file /private/path/setup.json
```

Windows PowerShell supports `-Clean -SetupFile 'C:\Private\setup.json'` on
Install-Windows.ps1. The access code is passed via stdin, not a process argument,
logs or runtime.env. The setup file is not copied into the installed folder. Keep
it protected or securely remove it after setup using your normal private-file
handling process. Prefer interactive entry for normal client installations.

The database initializer is a command, not a public signup endpoint:
`php artisan yodamen:initialize`. It runs only on a migrated database with no
business/user. It refuses to reset an existing business, administrator or trial.
Command-line initialization is transactional; a settings failure leaves no partial account.
Concurrent initializers lock the same migration row before checking emptiness.
`php artisan yodamen:initialize --check` checks completed setup without changing it.

If interrupted after migrations but before account creation, rerun the same clean
installer and finish the browser form or the selected terminal prompts. It retains its generated credentials and does
not delete/recreate tables. A partial migration failure needs developer review;
the installer never runs migrate:fresh. Switching an installed folder from clean
to restore or vice versa is refused. Keep `.install-state` with the installation.

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
