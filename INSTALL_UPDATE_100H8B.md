# YODAMEN Update 100H8B — Sales Dashboard Chart Drill-Down

## Purpose
Replaces the table-based yearly trend modal from Update 100H8 with chart-based analytics while keeping the existing Year selector, branch context, and PNG download.

## Chart mapping
- Sales Type: monthly stacked bar chart
- Sales Mix: annual donut chart
- Customer Mix: monthly line chart
- Service Category: monthly line chart; top categories are shown and smaller categories are grouped as Others when needed
- Booking Type: monthly stacked bar chart
- Payment Method Mix: annual donut chart; smaller methods are grouped as Others when needed

The analytics cards remain clickable. The drill-down still uses the existing `/api/v1/sales-dashboard/trend` endpoint from Update 100H8, so no backend or database change is required.

## Install
From the project root:

```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100H8B-SALES-DASHBOARD-CHART-DRILLDOWN.zip -d .
cd frontend
npm run build
```

If the Vite dev server is not already running:

```bash
npm run dev
```

If it is already running, hard refresh the browser with Command + Shift + R.

## Notes
- No database migration.
- No new npm package.
- PNG export is generated in-browser from the rendered SVG chart.
- This patch assumes Update 100H8 is already installed because it uses the trend endpoint introduced there.
