# YODAMEN 100K7N10E — Sidebar Active Highlight Fix

## Scope
Frontend-only sidebar navigation fix.

## Changes
- Only the most specific matching submodule is highlighted.
- Customer List no longer remains highlighted while Service Balances or Customer Mix is selected.
- Expense Log no longer remains highlighted while Petty Cash is selected.
- Nested/detail routes still highlight their correct parent submodule.
- Module sections still automatically expand for the active submodule.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10E-SIDEBAR-ACTIVE-HIGHLIGHT-FIX.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No migration or backend change is required.
