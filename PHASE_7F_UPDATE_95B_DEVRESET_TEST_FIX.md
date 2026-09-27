# Phase 7F — Update 95B — DEVRESET Test Fixture Fix

The Update 95A regression test attempted to use `DB::table('personnel')->first()` even though `FoundationSeeder` and `MasterDataSeeder` do not guarantee a Personnel row. This caused a null dereference before the DEVRESET service was exercised.

Update 95B creates a valid Personnel master/setup fixture using existing reference values, then uses that retained record for the `commission_earnings` FK test. This preserves the intended assertion that DEVRESET clears transactional commission/sale records while retaining Personnel master data.
