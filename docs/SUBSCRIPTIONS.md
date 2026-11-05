# Permanent plans and Premium trial

Implementation lives in this repository's backend and frontend. No private signing
key is committed. Admin > Subscription is available to active Super Admin users.

| Plan | Included business groups |
| --- | --- |
| Basic | Customer List, Operations, Sales |
| Standard | Basic plus HR & Payroll, Expenses, Inventory |
| Premium | Standard plus Accounting and Reports |

Business setup, users/roles, branding, service pricing, references and audit are
core administration. Existing employee permissions still apply; a plan is not a
role grant. Super Admin does not bypass subscription checks. Collections and cash
handling stay in Sales even though some existing APIs use accounting URLs. Customer
classification and service balances stay in Customers. Existing Reports placeholders
remain placeholders; this change does not implement missing report pages.

## Trial

An unactivated installation has Premium access for 50 combined new activities:
each newly created booking, including a walk-in, counts once; each standalone sale
counts once. A sale linked to a booking already counted does not count again.
Rebooking/editing and cancellation do not refund usage. Failed and rolled-back
workflows do not consume usage. Imported history is excluded by the migration.
The counter is business scoped and row locked inside the record insertion
transaction, so concurrent creations cannot both reserve the last available use.

At 50 activities, existing records remain viewable but HTTP mutation endpoints
are blocked. Authentication, changing your own access code and license activation
remain available. Activate a paid plan to continue. The trial is usage based, not
a guessed number of days. Basic, Standard and Premium paid plans are permanent.

## Permanent paid plans

Basic, Standard and Premium never expire and have no trial activity cap after
activation. Only the unactivated Premium trial ends at 50 activities. New codes
contain expires_at: null. Previously issued signed codes with dates are also
permanent under this policy; the backend ignores their old date while continuing
to verify the signature, installation, business, tier and revision.

Plan changes need a revision higher than the installed revision. Older revisions
cannot be replayed after accepting a newer revision. Re-entering the exact current
code is idempotent. Records are preserved when activating or changing plans.

This is offline license verification. Customers controlling the server/source can
modify it, restore old databases or change the clock. This is not hardware-bound
DRM or an online revocation/billing system. Protect the publisher key and backups.

## Issue a code on the publisher computer

Use PHP with the Sodium extension. Obtain the installation ID, business code and
current revision from Admin > Subscription. Keep the private signing key outside
the deployed application and Git. Use the existing publisher key matching the
public key in backend/config/subscriptions.php; do not regenerate it casually.

```sh
php tools/issue-license.php --key-file=/private/path/signing.key \
  --installation=CLIENT_UUID --business=YDM --tier=standard \
  --revision=1 --out=/private/path/activation.txt
```

Replace placeholders and choose a revision higher than the currently installed
revision. No expiry option is needed. The old --expires=never flag is accepted for
compatibility; date values are rejected. Send only the activation
code to the customer. They paste it into Admin > Subscription. The signing key
must never be included in installers, frontend files, environment files on the
client's server, or commits.

## Apply to an installed application

Use the maintained [Docker patch workflow](../deployment/patch/PATCH.md) for
existing installer-based deployments. The builder and Windows/Mac launchers live
in deployment/patch in this repository. See its VERIFICATION.md for the tested
upgrade, license preservation and recovery checks.

Back up the installed database and uploads. Deploy frontend/backend together, then
run php artisan migrate and php artisan optimize:clear in the correct runtime.
This adds license identity and usage fields without resetting existing records.
Do not run migrate:fresh or reimport the original dump. Docker installations need
a newly built image; changing this source tree does not change a running image.
Existing installations enter the 50-use Premium trial until activated. No live
database migration is performed merely by adding these source files.

## Checks

Run php artisan test, npm test, npm run build, and lint changed frontend files.
Tests use SQLite memory and do not require the real MySQL database. Rehearse
backup restoration and verify UI activation on the target platform before rollout.
