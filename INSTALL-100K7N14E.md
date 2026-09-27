# YODAMEN 100K7N14E — Cash & Bank Balance Cards

Frontend-only UI refinement.

## Changes
- Replaces the Cash & Bank Balances table rows in Accounting → Cash Management with compact balance cards.
- Each card shows the account code, account name, and ledger-derived balance.
- Adds a restrained gold side accent, compact code pill, tabular currency figures, subtle depth, and responsive card sizing.
- Negative balances receive a restrained warning treatment.
- No accounting calculation, API, database, permission, or posting behavior changes.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14E-CASH-BANK-BALANCE-CARDS.zip -d .
cd frontend
npm run build
```

No migration is required.
