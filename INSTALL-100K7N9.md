# YODAMEN 100K7N9 - Cash Handling Tips Tab

Prerequisite: 100K7N8 Electronic Tips Payable installed.

## Changes
- Adds a third Cash Handling process tab: Tips.
- Moves Electronic Tips Payable out of the Remit/Deposit list view and into the new Tips tab.
- Hides List/Calendar controls while Tips is selected because Tips uses a dedicated ledger view.
- Keeps From, To, and Branch filters inside the Tips tab.
- Payout Source continues to include Petty Cash plus all eligible bank/e-wallet Operating Fund Source accounts.
- Cash on Hand, Cash with Manager, and POS/E-Wallet Clearing remain excluded from tip payout sources.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N9-CASH-HANDLING-TIPS-TAB.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No migration and no backend changes are included.
