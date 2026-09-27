# YODAMEN Update 100G4 — Correction Auto-Detection, No Selection Checkbox

This patch fixes the Correction form so existing transaction lines are no longer selected with checkboxes.

## Behavior

- Correction has no line-selection checkbox column.
- Existing lines are automatically included only when Quantity, Staff, Unit Price, or Line Action differs from the original transaction.
- REMOVE is automatically treated as a correction change.
- Unchanged lines are excluded from the correction request.
- Added services, service credits, and retail products continue to be included automatically.
- Existing product lines use the retail personnel list for Staff.
- Refund keeps its own line-selection checkbox because the user must explicitly identify the line(s) to refund.

## Install

From the YODAMEN project root:

```bash
unzip -o ~/Downloads/YODAMEN-UPDATE-100G4-CORRECTION-NO-CHECKBOX.zip -d .
cd frontend
npm run build
```

If needed:

```bash
npm run dev
```

No database migration is required.
