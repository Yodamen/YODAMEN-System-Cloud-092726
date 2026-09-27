# YODAMEN Update 100K7J — Admin-Only Opening Balances

## Changes
- Removes the **Add Balance** button from Accounting → Cash Management.
- Cash Management is now view/report only.
- All beginning/opening balance entry remains exclusively under **Admin → Opening Balances**.
- Removes unused Cash Management navigation/auth imports introduced only for the old Add Balance shortcut.

## Dependency
Install after Update 100K7I (Opening Balance Cutover).

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7J-ADMIN-ONLY-OPENING-BALANCES.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No migration is required.
