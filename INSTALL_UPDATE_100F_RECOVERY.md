# YODAMEN Update 100F Customer Reference Recovery — Safe for Update 100G

This recovery package restores the Customer Profile > Transaction History reference layout that was part of Update 100F without replacing the newer Update 100G service-consumption logic.

Restored display:
- Booking ID as the transaction headline when a booking exists
- `Direct Sale` as the headline for sales without a booking
- Sale ID (`SAL-...`) as the first subline
- Branch address/location as the second subline, falling back to branch name

No database migration is included.

## Install on macOS

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100F-CUSTOMER-REFERENCE-RECOVERY-SAFE-FOR-100G.zip -d .

cd backend
php artisan optimize:clear
php artisan test

cd ../frontend
npm run build
npm run dev
```

Do NOT reinstall the original full Update 100F ZIP over Update 100G. The original 100F package contained older service-consumption logic, while Update 100G contains the newer proportional multi-session recipe behavior.
