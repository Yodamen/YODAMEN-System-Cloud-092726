# Update 85 - UOM Conversion, Supplier Masterlist, Retail Availability

## Inventory UOM controls
- `unit` remains the Basic UOM used by stock balances, movements, audit counts, and future service consumption.
- Each inventory item now also stores a Purchase UOM and Purchase-to-Basic conversion factor.
- Stock Received captures Purchase Quantity, Purchase UOM, Conversion Factor, Purchase Unit Cost, and the computed Basic UOM quantity.
- A per-line Override Conversion control permits receipt-specific changes. "Save as item default" updates the product's Purchase UOM/conversion for future receipts.
- Converted Basic UOM quantity must resolve to a whole Basic UOM quantity. Use a smaller Basic UOM such as ml or g when fractional stocking would otherwise occur.
- Receipt weighted-average costing continues at the Basic UOM level. Purchase Unit Cost is divided by the conversion factor to derive Basic Unit Cost.

## Retail availability
- Inventory items now have an `Available for Retail` checkbox.
- Only retail-enabled items are included in Potential Retail Value.
- Retail POS, booking/walk-in retail product selectors, and retail commission rule product options only expose retail-enabled items.
- Service-use inventory may remain quantity tracked and reorder controlled while being excluded from retail selling.

## Supplier Masterlist
New Inventory > Supplier Masterlist module with:
- Company Name (required)
- Contact Person
- Contact Number
- Channel
- Address
- Items supplied (checkbox assignment)
- Store Link
- Status

Supplier Channel is added to Administration > Reference Lists. Default channels include Phone, Email, Viber, Messenger, WhatsApp, WeChat, and Other.

The supplier form includes a New Item action. A new item can be created without leaving the supplier form using the Inventory Masterlist fields, then automatically assigned to the supplier.

## Stock Received + suppliers
- Supplier is now required for new stock receipts.
- Selecting a supplier limits the Item dropdown to items assigned to that supplier.
- Receipt history preserves the supplier name snapshot.

## Inventory Masterlist UI
- Item Name filter is now a dropdown instead of a free-text box.
- Apply and Reset remain aligned with the other desktop filters.
- Inventory item form now includes Basic UOM, Purchase UOM, Conversion Factor, and Available for Retail.
