# Update 96D — Consumption UOM Logic Fix

Corrects Consumption Conversion to mean:

**1 Basic UOM = N Consumption UOM**

Example: Basic UOM = Vial, Consumption UOM = ml, Conversion = 8 means **1 Vial = 8 ml**.

Pricing & Inventory now derives and displays:
- Purchase UOM Cost (manual)
- Consumption UOM Cost = Basic UOM Cost / Consumption Conversion
- Current Weighted Avg. Cost expressed per Consumption UOM

Service Material Recipe math is corrected accordingly:
- Basic Quantity = Consumption Quantity / Consumption Conversion
- Estimated Cost = Consumption Quantity × Weighted Avg. Cost per Consumption UOM

The legacy database column name `consumption_to_basic_factor` remains for compatibility, but its business meaning is now Basic-to-Consumption. API responses also expose `basic_to_consumption_factor`.

The migration recalculates existing service recipe `basic_quantity_per_service` values using the corrected direction.
