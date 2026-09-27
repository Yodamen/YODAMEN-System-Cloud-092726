# YODAMEN 100K7N14D — Five-card desktop summary row fix

Frontend-only patch.

Changes:
- My Payroll: forces all five top summary cards into one desktop row from 861px upward.
- Petty Cash: forces all five summary cards into one desktop row from 861px upward.
- Reduces desktop card padding/type sizing slightly so the five equal-width cards fit cleanly.
- Keeps responsive 2-column/mobile behavior below desktop width.

Install:

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14D-FIVE-CARD-DESKTOP-ROW-FIX.zip -d .
cd frontend
npm run build
```

No migration or backend command is required.
