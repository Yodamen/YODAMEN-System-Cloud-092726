# YODAMEN Update 100H5 — Customer Profile Transaction Layout

## Changes
- Customer Profile > Transaction History now shows the branch name only (for example, `San Jose City Branch`) instead of the full branch address.
- Customer Profile modal width increased to up to 1180px on desktop.
- Transaction History table uses the wider modal more efficiently and wraps long item/reference text so desktop users do not need horizontal side-scrolling under normal viewport sizes.
- Mobile/small-screen fallback still allows horizontal scrolling when the viewport is too narrow to display all eight columns legibly.

## Install
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100H5-CUSTOMER-PROFILE-LAYOUT.zip -d .
cd frontend
npm run build
```

If Vite is not running:

```bash
npm run dev
```

If Vite is already running, hard-refresh the browser with `Command + Shift + R`.

## Database
No migration required.
