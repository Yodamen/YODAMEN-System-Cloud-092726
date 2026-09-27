# Phase 7G — Update 97A

## Regression correction

### Visit completion
Removed visit-level audit metadata that referenced an out-of-scope `$consumption` variable. Service-consumption metadata remains correctly recorded on the individual `COMPLETE_SERVICE` audit event, where the specific consumption record exists.

### Retail POS tests
Retail POS test setup no longer calls the production Adjust Stock endpoint to create fixture inventory. That endpoint is now intentionally maker-checker and creates a pending adjustment request. The test now seeds opening `InventoryStock` directly so POS behavior is tested independently from inventory-adjustment approval behavior.
