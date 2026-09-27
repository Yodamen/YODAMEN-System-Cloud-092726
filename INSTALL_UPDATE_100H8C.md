# YODAMEN Update 100H8C — Polished Sales Dashboard Charts

## Purpose
Polishes the Sales Dashboard analytics drill-down introduced in Update 100H8B. The charts now include presentation-ready headings, subtitles, branch/year context, axis labels where applicable, cleaner grid/axis styling, hover values, and YODAMEN branding in both the on-screen chart and PNG export.

## Changes
- Adds a chart-specific title and explanatory subtitle to every analytics chart.
- Adds selected year and branch context inside the chart.
- Adds X-axis label `Month` and Y-axis label `Sales amount (PHP)` for line and stacked-bar trends.
- Improves gridlines, axis lines, legends, spacing, typography, and chart proportions.
- Adds hover values to line points, stacked bars, and donut slices.
- Adds a small YODAMEN logo in the chart header area.
- Adds the YODAMEN logo to the downloaded PNG.
- Keeps donut charts axis-free because axes are not applicable to composition charts; annual amount and percentage remain visible in the center/legend.

## Files
- `frontend/src/pages/sales/SalesDashboardPage.jsx`
- `frontend/src/pages/sales/sales-update79.css`

## Install
```bash
cd /Users/domalonzo/Documents/YODAMEN-System-Development
unzip -o ~/Downloads/YODAMEN-UPDATE-100H8C-POLISHED-SALES-CHARTS.zip -d .
cd frontend
npm run build
```

If the Vite development server is not already running:
```bash
npm run dev
```

If Vite is already running, hard refresh Chrome with `Command + Shift + R`.

## Notes
- No backend change.
- No database migration.
- No new npm package.
- Uses the existing `frontend/src/assets/yodamen-logo-black.png` asset already in the YODAMEN project.
- Requires Update 100H8/100H8B because it builds on the existing Sales Dashboard trend endpoint and chart drill-down.
