# YODAMEN Update 100H6 — Booking Form Alignment

## Change
- Removes the "Automatically detected from completed visit history." subtitle below Customer Type in the Add/Edit Booking modal.
- Customer Type and Booking Source now align on the same row without the extra helper-text height.

## Retail inventory verification
No inventory logic is changed by this update.

Current booking-retail behavior remains:
- A retail product can be added/saved on a Booking even when its available stock is 0.
- Inventory is revalidated during final checkout.
- Checkout is blocked when available stock is insufficient.
- A Booking retail checkout therefore cannot create negative inventory.

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100H6-BOOKING-FORM-ALIGNMENT.zip -d .
cd frontend
npm run build
```

If Vite is already running, hard-refresh the browser with Command + Shift + R.

No backend change or database migration is included.
