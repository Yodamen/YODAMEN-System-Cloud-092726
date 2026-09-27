# YODAMEN Update 100G
## Correction History + Effective Collections + Multi-Session Recipe Allocation

### Included fixes

1. **Paid line removed by Correction remains auditable**
   - Removed lines remain visible in Sales > Transactions.
   - They display `REMOVED BY CORRECTION`.
   - Original quantity, original amount, correction request reference, reason, effective amount, and Refund Due remain visible.
   - Removed lines cannot be corrected again.

2. **Sales Dashboard / Z-Reading effective collections**
   - Immutable RECEIVED Payment records are preserved.
   - While a post-checkout Correction has Refund Due, the corrected excess is excluded from effective `Payments Received`.
   - Payment-method totals use the same effective reporting amount.
   - When an actual refund is later posted, the original receipt remains historical and the cash refund is reported separately.

3. **Multi-session package recipe allocation**
   - For SINGLE_SESSION services, the recipe remains per completed service.
   - For MULTI_SESSION services, the recipe entered in Service Consumption is treated as the TOTAL material recipe for the entire package.
   - Each completed session consumes exactly `full package recipe / configured number of sessions`.
   - Example: 24 ml saved for a 6-session package -> each completed session consumes 4 ml.
   - Legacy package Visit lines that carry the package entitlement quantity still post only one rendered session at a time.

4. **Service Consumption recipe UI**
   - Multi-session services clearly state that recipe quantities are for the FULL package.
   - The form shows the session count and estimated per-session material cost.

### Migration
No migration is included.

### Important historical note
The package recipe fix applies to new service-consumption postings after Update 100G. It does not silently rewrite already-posted inventory movements. Existing test records that over-consumed before this fix remain historical and should be reversed/corrected through the approved transaction controls if needed.
