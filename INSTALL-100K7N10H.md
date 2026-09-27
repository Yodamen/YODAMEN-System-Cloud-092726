# YODAMEN 100K7N10H — Customer List Type + Class/Type Filters

Frontend-only patch.

## Changes
- Customer List table classification column renamed from Class to Type.
- Type column now shows the configured Customer Type with its configured color indicator.
- Customer Class remains the badge beside the customer name.
- Added Customer Class filter.
- Added Customer Type filter.
- Reset clears both new filters.
- Customer filters remain responsive on smaller screens.

## Install
```bash
cd "/Users/domalonzo/Documents/YODAMEN-System-Development"
unzip -o ~/Downloads/YODAMEN-UPDATE-100K7N10H-CUSTOMER-LIST-TYPE-FILTERS.zip -d .
cd frontend
npm run build
```

No migration and no backend change are required.
