# Phase 1A Local Setup and Validation

Use these steps in the actual working repository after replacing/adding the Phase 1A files.

## 1. Git checkpoint

Verify the current branch before applying the package. Keep approved code on `stable/approved` and perform active work on the development branch used by the repository.

## 2. Backend environment

Copy `.env.example` to `.env` only if the local `.env` does not already exist. Preserve real local secrets. Configure:

- `DB_DATABASE=yodamen_dev`
- valid local MySQL username/password
- `APP_TIMEZONE=Asia/Manila`
- `YODAMEN_TIMEZONE=Asia/Manila`
- `YODAMEN_SUPER_ADMIN_USERNAME=<local username>`
- `YODAMEN_SUPER_ADMIN_ACCESS_CODE=<temporary local access code, minimum 6 chars>`

Use the same hostname for frontend and backend during local SPA authentication. The examples use `localhost` for both ports.

## 3. Laravel

```bash
cd backend
composer install
php artisan key:generate
php artisan optimize:clear
php artisan migrate
php artisan db:seed
php artisan test
php artisan serve --host=127.0.0.1 --port=8000
```

If the existing local database contains disposable skeleton-only Laravel data and migration history conflicts with the revised foundation migrations, use `php artisan migrate:fresh --seed` only after confirming there is no real YODAMEN data in `yodamen_dev`.

## 4. React

```bash
cd frontend
cp .env.example .env
npm ci
npm run lint
npm run build
npm run dev
```

Open `http://localhost:5173`. The API example points to `http://localhost:8000/api`.

## 5. Acceptance checks

- Login succeeds using the environment-seeded username and temporary Access Code.
- The required Access Code reset modal blocks normal use until a new code is saved.
- Dashboard renders the business, branch, role and permission count.
- Non-migrated navigation is visible only when permitted and remains disabled.
- Logout ends the session.
- `audit_logs` contains `LOGIN`, `CHANGE_OWN_ACCESS_CODE`, permission denials and permission changes when those actions occur.
- A Manager role cannot call the Users/Role permission endpoint without `users.MANAGE_PERMISSIONS`.
- `ROLE-SA` permission update returns validation failure.
- Backend tests, frontend lint and frontend production build pass.
