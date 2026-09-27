# YODAMEN Update 99A — Transaction Posting Schema Repair

## Why this patch is required
Update 99 introduced new transaction-action fields used by Servicing, Correction, Refund, Reversal, Inventory, Service Credits, Payments, and Commissions.

The original Update 99 migration grouped multiple column additions behind one `Schema::hasColumn()` condition. If an earlier migration execution partially created a group and then stopped, a later rerun could see the first column, skip the rest of that group, and still complete. This can leave runtime code ahead of the actual MySQL schema.

Observed error:
`Unknown column 'effective_net_line_total' in 'field list'`

Update 99A repairs every Update 99 schema group independently. It does not delete transactions and it does not roll back Update 99.

## Install

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development

unzip -o ~/Downloads/YODAMEN-UPDATE-99A-SCHEMA-REPAIR.zip -d .

cd backend

php artisan migrate
php artisan optimize:clear
php artisan test
```

Do not run `migrate:fresh`, `db:wipe`, or DEVRESET for this repair.

## Verify the exact failing field

```bash
/usr/local/mysql-8.4.11-macos15-arm64/bin/mysql -u root -p -D yodamen_dev -e "SHOW COLUMNS FROM sale_items LIKE 'effective_net_line_total';"
```

Expected: one row named `effective_net_line_total`.

## Recommended integrity verification

```bash
/usr/local/mysql-8.4.11-macos15-arm64/bin/mysql -u root -p -D yodamen_dev -e "
SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='yodamen_dev'
AND (
  (TABLE_NAME='sales' AND COLUMN_NAME IN ('correction_adjustment_amount','refund_adjustment_amount','cash_refunded_amount','refund_due_amount','effective_net_amount','refund_status','reversed_at','reversed_by','reversal_action_request_id'))
  OR
  (TABLE_NAME='sale_items' AND COLUMN_NAME IN ('correction_adjustment_amount','refund_adjustment_amount','cash_refunded_amount','refund_due_amount','refunded_quantity','returned_quantity','effective_net_line_total','correction_status','refund_status','last_transaction_action_request_id'))
  OR
  (TABLE_NAME='service_consumptions' AND COLUMN_NAME IN ('effective_service_quantity','correction_status','last_transaction_action_request_id'))
  OR
  (TABLE_NAME='inventory_movements' AND COLUMN_NAME IN ('transaction_action_request_id','transaction_action_request_line_id','source_service_consumption_line_id','action_effect_key'))
  OR
  (TABLE_NAME='commission_earnings' AND COLUMN_NAME IN ('source_transaction_action_request_id','source_commission_earning_id','transaction_action_effect_key'))
)
ORDER BY TABLE_NAME, ORDINAL_POSITION;"
```

## Retest the path that failed
1. Open Servicing.
2. Click Manage on an active visit.
3. Complete a service.
4. Confirm that the service completes and the transaction is created without the SQLSTATE 42S22 error.
5. Open Sales > Transactions and confirm the new transaction appears with the correct amount.
6. Do not test Correction/Refund/Reversal until the normal service completion path succeeds first.

## What the repair checks
The migration independently verifies and repairs Update 99 columns for:
- transaction action requests
- sales
- sale items
- payments
- payment applications
- inventory movements
- service consumption
- service-credit usage
- service-completion events
- visit retail lines
- commission earnings

It also restores missing foreign keys and idempotency unique indexes used by downstream posting controls.
