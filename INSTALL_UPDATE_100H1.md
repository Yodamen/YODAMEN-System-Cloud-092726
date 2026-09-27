# YODAMEN Update 100H1 — Reversed Transaction Type Column

## Purpose
Cleans up Sales → Transactions → Reversed.

## Changes
- Removes the `REMOVED BY CORRECTION` status badge from the Service column.
- Renames the last column from `Action` to `Type` while viewing the Reversed tab.
- Reversed history rows now display exactly one of these values in Type:
  - `Correction` for a line removed through an approved transaction correction.
  - `Reversal` for a transaction/line reversed through the reversal workflow.
- The Service column continues to preserve useful audit detail such as assigned staff, original quantity/amount, correction request code, and correction reason.
- Other transaction tabs continue to use the normal `Action` column.

## Installation
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-100H1-REVERSED-TYPE-COLUMN.zip -d .

cd frontend
npm run build
```

If the Vite development server is not running:

```bash
npm run dev
```

If it is already running, hard-refresh Chrome with `Command + Shift + R`.

## Database
No migration required.
