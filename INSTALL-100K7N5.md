# YODAMEN 100K7N5 — Rule Multi-Select Checkbox Scopes

Non-payroll update. The held 100K7O statutory payroll engine is not included.

## Install

```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N5-RULE-MULTI-SELECT-CHECKBOX-SCOPES.zip -d .

cd backend
php artisan optimize:clear
php artisan migrate
php artisan test tests/Feature/Rules/RuleMultiScopeTest.php
php artisan test

cd ../frontend
npm run build
```

## Scope behavior

- Personnel can be selected as multiple checkboxes.
- Service Categories can be selected as multiple checkboxes.
- Services can be selected as multiple checkboxes.
- In service scope, selected Services and selected Service Categories are additive (OR). A service matches if it is directly selected OR belongs to a selected category.
- Leaving both Service Categories and Services empty means All Services.
- Promotion personnel selection is optional. Empty means All Personnel.
- Commission and Incentive Personnel selection is required only when the rule target is Specific Personnel.
- Retail-specific rules keep their existing retail product/category controls.
