# YODAMEN Update 71 — Branding Upload, Typography & Colors

## Scope
This update expands Administration → Branding & Appearance.

### Added
- Logo Source: Upload Image, Logo URL, or No Logo
- Uploaded logo storage through Laravel application storage
- Public logo delivery endpoint, so the same uploaded logo works before login
- Recommended logo guidance: 800 × 200 px, 4:1 horizontal, PNG/JPG/WebP, max 5 MB
- Font Style selector
- Primary Color
- Accent Color
- Sidebar Color
- Live branding preview
- Uploaded/URL logo shown at the top of the live sidebar
- Branding applied to Login and authenticated app shell

## Color behavior
- Primary Color: main action buttons
- Accent Color: active navigation, focus and refresh accents
- Sidebar Color: sidebar background

## Font options
- Inter / Modern Sans
- System UI
- Arial
- Helvetica
- Trebuchet MS
- Verdana
- Tahoma
- Georgia
- Times New Roman

## Migration
Adds branding appearance fields to `business_branding_settings` and preserves existing Logo URL records.

## Install
From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-PHASE-7A-BRANDING-UPLOAD-TYPOGRAPHY-COLORS-71.zip -d .
cd backend
php artisan migrate
php artisan test --filter=BusinessBrandingAdministrationTest
cd ../frontend
npm run build
```

Do not run `migrate:fresh`.

## Notes
No `php artisan storage:link` command is required. Uploaded logos are served by the Laravel public branding endpoint.
