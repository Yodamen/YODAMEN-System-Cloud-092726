# Phase 6A — Commission Rule Test Fix 02

## Fix
The Phase 6A commission feature tests previously assumed `MasterDataSeeder` created a Service record. It only creates reference/master values, so `Service::firstOrFail()` failed before commission logic was tested.

The replacement test now creates a valid active single-session service through the existing Services API during test setup, then runs all commission-rule assertions against that service.

## Production impact
None. No application logic, schema, or migration changes.
