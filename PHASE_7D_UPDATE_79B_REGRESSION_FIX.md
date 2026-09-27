# Phase 7D - Update 79B Regression Fix

Update 79B is a corrective patch for Update 79.

## HR
The new entitlement rule intentionally prevents personnel without Leave Entitlement from filing LEAVE requests. Their self-service experience retains DAY_OFF only. The existing regression test was still asserting the previous behavior, so it was updated to validate the new approved rule.

## Inventory
NON_INVENTORY is treated as a non-stock-tracked classification. Reorder Point and Retail Commission Eligibility are therefore normalized to zero/false in both create and update paths, and serialized consistently for existing rows. The Product Masterlist UI mirrors the same rule.

## Migration
No migration is required.
