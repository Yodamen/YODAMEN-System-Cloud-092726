Update 83 delivers the requested inventory and sales dashboard revisions.

Scope included:
- Inventory Masterlist filter bar tightened so Item Name uses a shorter field and Apply/Reset stay aligned on the same row.
- Inventory Dashboard retitled properly, summary cards rearranged, Available Units removed, and Category / Status / SKU Name filters added.
- Inventory Dashboard SKU filter changed from free-text search to an SKU Name dropdown backed by product references.
- Sales Dashboard now defaults to month-to-date, and comparison notes now use the same shifted period from the previous month unless a manual Compare range is enabled.
- Sales Dashboard adds a Compare checkbox with optional Compare From / Compare To fields.
- Remaining Inventory submodules are activated in the sidebar and routed to live pages: Stock Received, Service Consumption, and Inventory Count.
- Global form field content weight is normalized so text inside form bars is not bold.

Notes:
- No migration is included.
- Backend API payloads for Inventory Dashboard and Sales Dashboard were extended to support the new UI behavior.
- Newly activated inventory submodules are live and filterable; they will show records once matching inventory movement entries exist in the database.
