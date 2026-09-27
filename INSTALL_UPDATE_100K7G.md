# YODAMEN Update 100K7G — Transaction Collection Booking-Only Control

## Purpose
Removes direct payment collection from the Sales > Transactions action menu.

All Advance, Interim, Checkout, and subsequent customer collections must remain associated with a Booking/payment workflow. The Transactions module remains a transaction review/correction surface rather than an alternate collection entry point.

## UI behavior
- Pending: Checkout + Correction remain as applicable.
- On Account: Correction only.
- Paid: Correction only.
- Service Credit: Correction only.
- Reversed: existing reversal/refund display remains unchanged.
- The old `Collect Payment` action and its Collection modal wiring are removed from `TransactionsPage.jsx`.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7G-TRANSACTION-COLLECTION-BOOKING-ONLY.zip -d .

cd "/Users/domalonzo/Documents/YODAMEN-System-Development/frontend"
npm run build
```

No database migration is required.
