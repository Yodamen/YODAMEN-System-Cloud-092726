# YODAMEN Update 99F — Transaction Approval Queue + Super Admin Manual Self-Approval

## Locked rule

- Correction / Refund / Reversal requests still require explicit approval.
- Normal users and Managers cannot approve or reject their own request.
- A user whose role is the actual Super Admin role may manually approve or reject their own request.
- Super Admin self-approval is never automatic.
- The request remains `PENDING_MANAGER_APPROVAL` until the Super Admin clicks **Approve & Post** or **Reject**.
- Existing stale-request validation, atomic posting, audit trail, permissions, and downstream controls remain active.

## UI changes

- Added **For Approval** tab under Sales > Transactions.
- Pending Correction / Refund / Reversal requests are listed there.
- Authorized approvers see **Review & Approve**.
- Request-only users can see the requests they personally submitted as **View Request**.
- When a Super Admin submits a request, the page automatically switches to **For Approval**.
- The same Super Admin can open the request and gets **Approve & Post** and **Reject** buttons.
- The review modal clearly states when the Super Admin self-approval override is being used.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-99F-APPROVAL-QUEUE-SUPERADMIN.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
```

There is **no migration** in Update 99F. Do not run `php artisan migrate` for this patch.

After the backend tests and frontend build pass, restart the development servers if needed.

## Live test

1. Log in as Super Admin.
2. Sales > Transactions > open a transaction > Correction.
3. Submit the request.
4. The page should move to **For Approval**.
5. Open the request.
6. The same Super Admin should see **Approve & Post** and **Reject**.
7. Click **Approve & Post** manually.
8. Confirm the request posts and disappears from the pending approval queue.

Then test maker-checker with a normal Manager/requester account. A non-Super-Admin requester must not be able to approve their own request.
