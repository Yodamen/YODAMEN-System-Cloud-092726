# YODAMEN 100K7N14B – My Payroll Running Payroll Layout

## Changes
- Restores the **Commission** card in **My Payroll > Running Payroll**.
- Keeps the previously removed duplicate cards out of Running Payroll:
  - Next Cash Advance Deduction
  - Outstanding Recovery
  - Projected Net
- Orders the Running Payroll cards as:
  1. Basic / Guarantee Top-Up
  2. Commission
  3. Additions
  4. Other Deductions
  5. Prior Payroll Recovery
- This places **Other Deductions** directly to the left of **Prior Payroll Recovery** on the second desktop row.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"

unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N14B-MY-PAYROLL-LAYOUT.zip -d .

cd frontend
npm run build
```

No migration or backend command is required.
