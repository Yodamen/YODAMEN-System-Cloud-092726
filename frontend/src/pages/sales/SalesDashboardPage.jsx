import SearchableSelect from '../../components/common/SearchableSelect'
import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, getApiErrorMessage } from '../../api/client'
import Modal from '../../components/common/Modal'
import PageRefreshButton from '../../components/common/PageRefreshButton'
import CustomerSegmentTag from '../../components/common/CustomerSegmentTag'
import { formatPeso } from '../../utils/formatters'
import yodamenLogo from '../../assets/yodamen-logo-black.png'
import { useAuth } from '../../context/AuthContext'
import './sales-update79.css'

function dateValue(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function defaultFilters() {
  const today = new Date()
  const compareFrom = new Date(today.getFullYear(), today.getMonth() - 1, 1)
  const compareTo = new Date(today.getFullYear(), today.getMonth() - 1, today.getDate())

  return {
    from: dateValue(new Date(today.getFullYear(), today.getMonth(), 1)),
    to: dateValue(today),
    branch_id: '',
    compare_enabled: false,
    compare_from: dateValue(compareFrom),
    compare_to: dateValue(compareTo),
    top_n: 10,
  }
}

function ComparisonNote({ change, label }) {
  if (!change) return <small className="sales-change-note neutral">vs {label}</small>

  const difference = Number(change.difference || 0)
  const percent = change.percent
  const direction = difference > 0 ? 'up' : difference < 0 ? 'down' : 'flat'
  const arrow = direction === 'up' ? '↑' : direction === 'down' ? '↓' : '→'
  const percentText = percent === null ? 'New' : `${Math.abs(Number(percent || 0)).toFixed(1)}%`

  return (
    <small className={`sales-change-note ${direction}`}>
      <span className="sales-change-arrow" aria-hidden="true">{arrow}</span>
      {percentText} vs {label}
    </small>
  )
}

function BreakdownTable({ rows = [], emptyText = 'No data in this period.', nameLabel = 'Type', clientRows = false }) {
  return (
    <div className="table-wrap">
      <table className="data-table sales-breakdown-table">
        <thead><tr><th>{nameLabel}</th><th>Count</th><th>Amount</th><th>% Amount</th></tr></thead>
        <tbody>
          {rows.map((row, index) => (
            <tr key={`${row.label || row.name || row.customer_id || 'row'}-${index}`}>
              <td>
                <strong>{row.label || row.name || '—'}</strong>
                {clientRows ? <CustomerSegmentTag customerClass={row.customer_class} customerType={row.customer_type || row.segment} typeColor={row.customer_type_color} /> : null}
              </td>
              <td>{Number(row.count || 0).toLocaleString('en-PH')}</td>
              <td><strong>{formatPeso(row.amount || 0)}</strong></td>
              <td>{Number(row.percent_amount || 0).toFixed(1)}%</td>
            </tr>
          ))}
          {!rows.length ? <tr><td colSpan="4"><div className="empty-state compact-empty">{emptyText}</div></td></tr> : null}
        </tbody>
      </table>
    </div>
  )
}

function AnalyticsCard({ title, subtitle, rows, nameLabel = 'Type', emptyText, clientRows = false, onOpen }) {
  function openFromKeyboard(event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      onOpen()
    }
  }

  return (
    <section className="content-card sales-analytics-card" role="button" tabIndex="0" onClick={onOpen} onKeyDown={openFromKeyboard}>
      <div className="section-heading-row compact-section-heading sales-analytics-card-heading">
        <div><h2>{title}</h2><p>{subtitle}</p></div>
        <span className="sales-trend-link">View analytics</span>
      </div>
      <BreakdownTable rows={rows} nameLabel={nameLabel} emptyText={emptyText} clientRows={clientRows} />
    </section>
  )
}

function MetricTrendCard({ label, value, note, change, compareLabel, onOpen }) {
  function onKeyDown(event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      onOpen()
    }
  }

  return (
    <article className="content-card sales-metric-card sales-metric-drilldown" role="button" tabIndex="0" onClick={onOpen} onKeyDown={onKeyDown}>
      <div className="sales-metric-label-row"><span>{label}</span><small>View trend</small></div>
      <strong>{value}</strong>
      <small>{note}</small>
      <ComparisonNote change={change} label={compareLabel} />
    </article>
  )
}

function trendYearTotals(trendData) {
  const series = trendData?.series || []
  const totals = Object.fromEntries(series.map((label) => [label, 0]))
  let grandTotal = 0
  for (const month of trendData?.months || []) {
    for (const label of series) totals[label] += Number(month.values?.[label] || 0)
    grandTotal += Number(month.total || 0)
  }
  return { totals, grandTotal }
}

const SALES_CHART_CONFIG = {
  sales_type: {
    kind: 'stacked_bar',
    label: 'Monthly Sales Type Mix',
    title: 'Sales Type Trend',
    subtitle: 'Monthly sales amount by service and retail transaction type.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Sales amount (PHP)',
  },
  sales_mix: {
    kind: 'donut',
    label: 'Annual Sales Mix',
    title: 'Sales Mix',
    subtitle: 'Annual share of sales amount across services, packages, and products.',
  },
  customer_mix: {
    kind: 'line',
    label: 'Monthly Customer Mix',
    title: 'Customer Mix Trend',
    subtitle: 'Monthly sales amount generated by each customer segment.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Sales amount (PHP)',
  },
  customer_class: {
    kind: 'donut',
    label: 'Annual Customer Class Mix',
    title: 'Customer Class',
    subtitle: 'Annual share of sales amount by customer lifetime-value class.',
    maxSeries: 8,
  },
  service_category: {
    kind: 'line',
    label: 'Monthly Service Category Trend',
    title: 'Service Category Trend',
    subtitle: 'Monthly sales amount by active service category.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Sales amount (PHP)',
    maxSeries: 6,
  },
  booking_type: {
    kind: 'stacked_bar',
    label: 'Monthly Booking Type Mix',
    title: 'Booking Type Trend',
    subtitle: 'Monthly sales amount from Walk-In and Reservation bookings.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Sales amount (PHP)',
  },
  payment_method_mix: {
    kind: 'donut',
    label: 'Annual Payment Method Mix',
    title: 'Payment Method Mix',
    subtitle: 'Annual share of effective received collections by payment method.',
    maxSeries: 6,
  },
  average_booking_value: {
    kind: 'line',
    label: 'Monthly Average Booking Value',
    title: 'Average Booking Value Trend',
    subtitle: 'Average finalized net value per booking by month.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Average booking value (PHP)',
    valueKind: 'amount',
  },
  client_visits: {
    kind: 'line',
    label: 'Monthly Client Visits',
    title: 'Client Visits Trend',
    subtitle: 'Completed client visits by month.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Client visits',
    valueKind: 'count',
  },
  average_lifetime_value: {
    kind: 'line',
    label: 'Monthly Average Lifetime Value',
    title: 'Average Lifetime Value Trend',
    subtitle: 'Average cumulative paid value of customers active in each month.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Average lifetime value (PHP)',
    valueKind: 'amount',
  },
  unique_customers: {
    kind: 'line',
    label: 'Monthly Unique Customers',
    title: 'Unique Customers Trend',
    subtitle: 'Distinct customers with completed visits by month.',
    xAxisLabel: 'Month',
    yAxisLabel: 'Unique customers',
    valueKind: 'count',
  },
}

const SALES_CHART_COLORS = ['#8a6a08', '#4f6f52', '#5577a3', '#a85d4a', '#725a9c', '#4f8b8b', '#9a7b4f']

function compactPeso(value) {
  const amount = Number(value || 0)
  if (Math.abs(amount) >= 1000000) return `₱${(amount / 1000000).toFixed(amount >= 10000000 ? 0 : 1)}M`
  if (Math.abs(amount) >= 1000) return `₱${(amount / 1000).toFixed(amount >= 100000 ? 0 : 1)}K`
  return `₱${Math.round(amount).toLocaleString('en-PH')}`
}

function trendValueLabel(type, value, compact = false) {
  const config = SALES_CHART_CONFIG[type] || {}
  const number = Number(value || 0)
  if (config.valueKind === 'count') {
    if (compact && Math.abs(number) >= 1000) return new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 1 }).format(number)
    return Math.round(number).toLocaleString('en-PH')
  }
  return compact ? compactPeso(number) : formatPeso(number)
}

function chartDataForTrend(trendData, type) {
  const config = SALES_CHART_CONFIG[type] || { kind: 'line' }
  const originalSeries = trendData?.series || []
  const months = trendData?.months || []
  const totals = trendYearTotals(trendData).totals
  const maxSeries = config.maxSeries || originalSeries.length

  if (originalSeries.length <= maxSeries) {
    return { ...trendData, series: originalSeries, months }
  }

  const ordered = [...originalSeries].sort((a, b) => Number(totals[b] || 0) - Number(totals[a] || 0))
  const kept = ordered.slice(0, maxSeries)
  const grouped = ordered.slice(maxSeries)
  const nextSeries = [...kept, 'Others']
  const nextMonths = months.map((month) => {
    const values = Object.fromEntries(kept.map((label) => [label, Number(month.values?.[label] || 0)]))
    values.Others = grouped.reduce((sum, label) => sum + Number(month.values?.[label] || 0), 0)
    return { ...month, values }
  })
  return { ...trendData, series: nextSeries, months: nextMonths, grouped_series: grouped }
}

function SvgChartHeader({ type, year, branchName, width = 1100 }) {
  const config = SALES_CHART_CONFIG[type] || {}
  return (
    <g className="sales-chart-heading">
      <text x="56" y="42" fontSize="24" fontWeight="800" fill="#171717">{config.title || 'Sales Analytics'}</text>
      <text x="56" y="67" fontSize="12.5" fill="#65656f">{config.subtitle || 'Yearly sales analytics.'}</text>
      <text x="56" y="89" fontSize="11.5" fontWeight="600" fill="#8a8a94">{year} · {branchName || 'All Branches'}</text>
      <line x1="56" x2={width - 56} y1="108" y2="108" stroke="#eeeeF2" />
    </g>
  )
}

function SvgLegend({ series = [], width = 1100, y = 126 }) {
  const itemWidth = Math.max(135, Math.floor((width - 112) / Math.min(series.length || 1, 4)))
  return (
    <g className="sales-chart-legend">
      {series.map((label, index) => {
        const row = Math.floor(index / 4)
        const column = index % 4
        const x = 56 + (column * itemWidth)
        const itemY = y + (row * 24)
        return (
          <g key={label} transform={`translate(${x} ${itemY})`}>
            <rect width="11" height="11" rx="3" fill={SALES_CHART_COLORS[index % SALES_CHART_COLORS.length]} />
            <text x="18" y="10" fontSize="11.5" fontWeight="600" fill="#52525a">{label}</text>
          </g>
        )
      })}
    </g>
  )
}

function LineTrendChart({ trendData, type, branchName }) {
  const width = 1100
  const height = 610
  const plot = { left: 104, right: 42, top: 178, bottom: 92 }
  const plotWidth = width - plot.left - plot.right
  const plotHeight = height - plot.top - plot.bottom
  const series = trendData?.series || []
  const months = trendData?.months || []
  const values = months.flatMap((month) => series.map((label) => Number(month.values?.[label] || 0)))
  const maxValue = Math.max(1, ...values)
  const config = SALES_CHART_CONFIG[type] || {}
  const roundedMax = config.valueKind === 'count'
    ? (maxValue <= 20 ? Math.max(4, Math.ceil(maxValue / 4) * 4) : maxValue <= 100 ? Math.ceil(maxValue / 10) * 10 : maxValue <= 1000 ? Math.ceil(maxValue / 100) * 100 : Math.ceil(maxValue / 1000) * 1000)
    : (maxValue <= 1000 ? Math.ceil(maxValue / 100) * 100 || 100 : Math.ceil(maxValue / 1000) * 1000)
  const xFor = (index) => plot.left + (months.length <= 1 ? plotWidth / 2 : (index * plotWidth) / (months.length - 1))
  const yFor = (value) => plot.top + plotHeight - ((Number(value || 0) / roundedMax) * plotHeight)

  if (!values.some((value) => value > 0)) return <div className="empty-state">No chart data for this year.</div>

  return (
    <svg className="sales-trend-chart" id="sales-trend-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`${config.title || 'Sales'} monthly trend chart`} fontFamily="Inter, Arial, sans-serif">
      <rect width={width} height={height} fill="#ffffff" />
      <SvgChartHeader type={type} year={trendData?.year} branchName={branchName} width={width} />
      <SvgLegend series={series} width={width} y={126} />
      {[0, 1, 2, 3, 4].map((step) => {
        const value = (roundedMax / 4) * step
        const y = yFor(value)
        return (
          <g key={step}>
            <line x1={plot.left} x2={width - plot.right} y1={y} y2={y} stroke="#e8e8ed" strokeDasharray={step === 0 ? undefined : '4 5'} />
            <text x={plot.left - 14} y={y + 4} textAnchor="end" fontSize="11" fill="#7b7b86">{trendValueLabel(type, value, true)}</text>
          </g>
        )
      })}
      <line x1={plot.left} x2={plot.left} y1={plot.top} y2={plot.top + plotHeight} stroke="#cfcfd6" />
      <line x1={plot.left} x2={width - plot.right} y1={plot.top + plotHeight} y2={plot.top + plotHeight} stroke="#cfcfd6" />
      {months.map((month, index) => <text key={month.month_number} x={xFor(index)} y={height - 56} textAnchor="middle" fontSize="11" fontWeight="600" fill="#666670">{month.month?.slice(0, 3)}</text>)}
      <text x={plot.left + (plotWidth / 2)} y={height - 20} textAnchor="middle" fontSize="11.5" fontWeight="700" fill="#55555f">{config.xAxisLabel || 'Month'}</text>
      <text transform={`translate(24 ${plot.top + (plotHeight / 2)}) rotate(-90)`} textAnchor="middle" fontSize="11.5" fontWeight="700" fill="#55555f">{config.yAxisLabel || 'Amount (PHP)'}</text>
      {series.map((label, seriesIndex) => {
        const points = months.map((month, index) => `${xFor(index)},${yFor(month.values?.[label] || 0)}`).join(' ')
        const color = SALES_CHART_COLORS[seriesIndex % SALES_CHART_COLORS.length]
        return (
          <g key={label}>
            <polyline points={points} fill="none" stroke={color} strokeWidth="3" strokeLinejoin="round" strokeLinecap="round" />
            {months.map((month, index) => {
              const value = Number(month.values?.[label] || 0)
              return (
                <circle key={`${label}-${month.month_number}`} cx={xFor(index)} cy={yFor(value)} r="4.5" fill="#fff" stroke={color} strokeWidth="2">
                  <title>{`${month.month} · ${label}: ${trendValueLabel(type, value)}`}</title>
                </circle>
              )
            })}
          </g>
        )
      })}
    </svg>
  )
}

function StackedBarTrendChart({ trendData, type, branchName }) {
  const width = 1100
  const height = 610
  const plot = { left: 104, right: 42, top: 178, bottom: 92 }
  const plotWidth = width - plot.left - plot.right
  const plotHeight = height - plot.top - plot.bottom
  const series = trendData?.series || []
  const months = trendData?.months || []
  const totals = months.map((month) => series.reduce((sum, label) => sum + Number(month.values?.[label] || 0), 0))
  const maxValue = Math.max(1, ...totals)
  const roundedMax = maxValue <= 1000 ? Math.ceil(maxValue / 100) * 100 || 100 : Math.ceil(maxValue / 1000) * 1000
  const groupWidth = plotWidth / Math.max(months.length, 1)
  const barWidth = Math.min(54, groupWidth * .62)
  const yFor = (value) => plot.top + plotHeight - ((Number(value || 0) / roundedMax) * plotHeight)
  const config = SALES_CHART_CONFIG[type] || {}

  if (!totals.some((value) => value > 0)) return <div className="empty-state">No chart data for this year.</div>

  return (
    <svg className="sales-trend-chart" id="sales-trend-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`${config.title || 'Sales'} monthly stacked chart`} fontFamily="Inter, Arial, sans-serif">
      <rect width={width} height={height} fill="#ffffff" />
      <SvgChartHeader type={type} year={trendData?.year} branchName={branchName} width={width} />
      <SvgLegend series={series} width={width} y={126} />
      {[0, 1, 2, 3, 4].map((step) => {
        const value = (roundedMax / 4) * step
        const y = yFor(value)
        return (
          <g key={step}>
            <line x1={plot.left} x2={width - plot.right} y1={y} y2={y} stroke="#e8e8ed" strokeDasharray={step === 0 ? undefined : '4 5'} />
            <text x={plot.left - 14} y={y + 4} textAnchor="end" fontSize="11" fill="#7b7b86">{compactPeso(value)}</text>
          </g>
        )
      })}
      <line x1={plot.left} x2={plot.left} y1={plot.top} y2={plot.top + plotHeight} stroke="#cfcfd6" />
      <line x1={plot.left} x2={width - plot.right} y1={plot.top + plotHeight} y2={plot.top + plotHeight} stroke="#cfcfd6" />
      {months.map((month, monthIndex) => {
        const x = plot.left + (monthIndex * groupWidth) + ((groupWidth - barWidth) / 2)
        let running = 0
        return (
          <g key={month.month_number}>
            {series.map((label, seriesIndex) => {
              const value = Number(month.values?.[label] || 0)
              const start = running
              running += value
              const yTop = yFor(running)
              const yBottom = yFor(start)
              return (
                <rect key={label} x={x} y={yTop} width={barWidth} height={Math.max(0, yBottom - yTop)} rx="2" fill={SALES_CHART_COLORS[seriesIndex % SALES_CHART_COLORS.length]}>
                  <title>{`${month.month} · ${label}: ${formatPeso(value)}`}</title>
                </rect>
              )
            })}
            <text x={x + (barWidth / 2)} y={height - 56} textAnchor="middle" fontSize="11" fontWeight="600" fill="#666670">{month.month?.slice(0, 3)}</text>
          </g>
        )
      })}
      <text x={plot.left + (plotWidth / 2)} y={height - 20} textAnchor="middle" fontSize="11.5" fontWeight="700" fill="#55555f">{config.xAxisLabel || 'Month'}</text>
      <text transform={`translate(24 ${plot.top + (plotHeight / 2)}) rotate(-90)`} textAnchor="middle" fontSize="11.5" fontWeight="700" fill="#55555f">{config.yAxisLabel || 'Amount (PHP)'}</text>
    </svg>
  )
}

function donutArc(cx, cy, radius, startAngle, endAngle) {
  const start = { x: cx + radius * Math.cos(startAngle), y: cy + radius * Math.sin(startAngle) }
  const end = { x: cx + radius * Math.cos(endAngle), y: cy + radius * Math.sin(endAngle) }
  const largeArc = endAngle - startAngle > Math.PI ? 1 : 0
  return `M ${start.x} ${start.y} A ${radius} ${radius} 0 ${largeArc} 1 ${end.x} ${end.y}`
}

function DonutTrendChart({ trendData, type, branchName }) {
  const width = 1100
  const height = 610
  const series = trendData?.series || []
  const { totals, grandTotal } = trendYearTotals(trendData)
  const entries = series.map((label) => ({ label, value: Number(totals[label] || 0) })).filter((entry) => entry.value > 0)
  const cx = 326
  const cy = 357
  const radius = 150
  let cursor = -Math.PI / 2
  const config = SALES_CHART_CONFIG[type] || {}

  if (!entries.length || grandTotal <= 0) return <div className="empty-state">No chart data for this year.</div>

  return (
    <svg className="sales-trend-chart" id="sales-trend-chart" viewBox={`0 0 ${width} ${height}`} role="img" aria-label={`${config.title || 'Sales'} annual composition chart`} fontFamily="Inter, Arial, sans-serif">
      <rect width={width} height={height} fill="#ffffff" />
      <SvgChartHeader type={type} year={trendData?.year} branchName={branchName} width={width} />
      <text x="56" y="137" fontSize="10.5" fontWeight="700" fill="#90909a" letterSpacing=".8">ANNUAL COMPOSITION BY AMOUNT</text>
      <circle cx={cx} cy={cy} r={radius} fill="none" stroke="#f0f0f2" strokeWidth="58" />
      {entries.map((entry, index) => {
        const slice = (entry.value / grandTotal) * Math.PI * 2
        const start = cursor
        const end = cursor + slice
        cursor = end
        return (
          <path key={entry.label} d={donutArc(cx, cy, radius, start, end)} fill="none" stroke={SALES_CHART_COLORS[index % SALES_CHART_COLORS.length]} strokeWidth="58" strokeLinecap="butt">
            <title>{`${entry.label}: ${formatPeso(entry.value)} · ${((entry.value / grandTotal) * 100).toFixed(1)}%`}</title>
          </path>
        )
      })}
      <text x={cx} y={cy - 12} textAnchor="middle" fontSize="12" fontWeight="700" fill="#7a7a83">YEAR TOTAL</text>
      <text x={cx} y={cy + 25} textAnchor="middle" fontSize="27" fontWeight="800" fill="#18181b">{compactPeso(grandTotal)}</text>
      <text x={cx} y={cy + 48} textAnchor="middle" fontSize="10.5" fill="#8a8a94">100% of selected year</text>
      <g transform="translate(610 170)">
        {entries.map((entry, index) => {
          const y = index * 58
          const percent = grandTotal ? (entry.value / grandTotal) * 100 : 0
          return (
            <g key={entry.label} transform={`translate(0 ${y})`}>
              <rect width="13" height="13" rx="3" fill={SALES_CHART_COLORS[index % SALES_CHART_COLORS.length]} />
              <text x="24" y="12" fontSize="13" fontWeight="750" fill="#2f2f33">{entry.label}</text>
              <text x="24" y="34" fontSize="12" fill="#6f6f78">{formatPeso(entry.value)} · {percent.toFixed(1)}%</text>
            </g>
          )
        })}
      </g>
    </svg>
  )
}

function SalesTrendChart({ trendData, type, branchName }) {
  const chartData = chartDataForTrend(trendData, type)
  const config = SALES_CHART_CONFIG[type] || { kind: 'line' }
  if (config.kind === 'donut') return <DonutTrendChart trendData={chartData} type={type} branchName={branchName} />
  if (config.kind === 'stacked_bar') return <StackedBarTrendChart trendData={chartData} type={type} branchName={branchName} />
  return <LineTrendChart trendData={chartData} type={type} branchName={branchName} />
}

function loadImage(source) {
  return new Promise((resolve, reject) => {
    const image = new Image()
    image.onload = () => resolve(image)
    image.onerror = reject
    image.src = source
  })
}

async function downloadTrendChartImage(trendData, type, branchName) {
  if (!trendData) return
  const svg = document.getElementById('sales-trend-chart')
  if (!svg) return

  const viewBox = svg.viewBox?.baseVal
  const width = Number(viewBox?.width || 1100)
  const height = Number(viewBox?.height || 610)
  const scale = 2
  const canvas = document.createElement('canvas')
  canvas.width = width * scale
  canvas.height = height * scale
  const ctx = canvas.getContext('2d')
  if (!ctx) return
  ctx.scale(scale, scale)
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, width, height)

  const cloned = svg.cloneNode(true)
  cloned.setAttribute('xmlns', 'http://www.w3.org/2000/svg')
  cloned.setAttribute('width', String(width))
  cloned.setAttribute('height', String(height))
  const markup = new XMLSerializer().serializeToString(cloned)
  const blob = new Blob([markup], { type: 'image/svg+xml;charset=utf-8' })
  const url = URL.createObjectURL(blob)

  try {
    const chartImage = await loadImage(url)
    ctx.drawImage(chartImage, 0, 0, width, height)

    try {
      const logo = await loadImage(yodamenLogo)
      const targetWidth = 102
      const targetHeight = targetWidth * (logo.naturalHeight / logo.naturalWidth)
      ctx.globalAlpha = .92
      ctx.drawImage(logo, width - targetWidth - 54, 28, targetWidth, targetHeight)
      ctx.globalAlpha = 1
    } catch {
      // Keep the export usable even if the logo asset fails to load.
    }

    canvas.toBlob((pngBlob) => {
      if (!pngBlob) return
      const pngUrl = URL.createObjectURL(pngBlob)
      const link = document.createElement('a')
      const cleanTitle = String(trendData.title || 'sales-analytics').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
      link.href = pngUrl
      link.download = `YODAMEN-${cleanTitle}-${trendData.year}.png`
      document.body.appendChild(link)
      link.click()
      link.remove()
      URL.revokeObjectURL(pngUrl)
    }, 'image/png')
  } finally {
    URL.revokeObjectURL(url)
  }
}

export default function SalesDashboardPage() {
  const { hasPermission } = useAuth()
  const canDownload = hasPermission('sales-dashboard', 'DOWNLOAD')
  const [data, setData] = useState(null)
  const [filters, setFilters] = useState(defaultFilters)
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [trend, setTrend] = useState(null)
  const [trendData, setTrendData] = useState(null)
  const [trendLoading, setTrendLoading] = useState(false)
  const [trendMessage, setTrendMessage] = useState('')

  const load = useCallback(async (nextFilters) => {
    setMessage('')
    const response = await api.get('/v1/sales-dashboard', {
      params: {
        from: nextFilters.from,
        to: nextFilters.to,
        branch_id: nextFilters.branch_id || undefined,
        compare_enabled: nextFilters.compare_enabled,
        compare_from: nextFilters.compare_enabled ? nextFilters.compare_from : undefined,
        compare_to: nextFilters.compare_enabled ? nextFilters.compare_to : undefined,
        top_n: nextFilters.top_n,
      },
    })
    setData(response.data)
    const resolvedBranch = response.data?.filters?.branch_id
    if (resolvedBranch && !nextFilters.branch_id) {
      setFilters((current) => ({ ...current, branch_id: String(resolvedBranch) }))
    }
  }, [])

  useEffect(() => {
    const initial = defaultFilters()
    const timer = window.setTimeout(() => {
      load(initial)
        .catch((error) => setMessage(getApiErrorMessage(error, 'Unable to load Sales Dashboard.')))
        .finally(() => setLoading(false))
    }, 0)
    return () => window.clearTimeout(timer)
  }, [load])

  async function apply(event) {
    event?.preventDefault()
    setLoading(true)
    try {
      await load(filters)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Sales Dashboard.'))
    } finally {
      setLoading(false)
    }
  }

  async function reset() {
    const next = defaultFilters()
    setFilters(next)
    setLoading(true)
    try {
      await load(next)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to load Sales Dashboard.'))
    } finally {
      setLoading(false)
    }
  }

  async function changeTopN(value) {
    const next = { ...filters, top_n: Number(value) }
    setFilters(next)
    setLoading(true)
    try {
      await load(next)
    } catch (error) {
      setMessage(getApiErrorMessage(error, 'Unable to refresh top sales lists.'))
    } finally {
      setLoading(false)
    }
  }

  async function loadTrend(type, year) {
    setTrendLoading(true)
    setTrendMessage('')
    try {
      const response = await api.get('/v1/sales-dashboard/trend', {
        params: {
          type,
          year,
          branch_id: filters.branch_id || undefined,
        },
      })
      setTrendData(response.data)
      setTrend((current) => current ? { ...current, year: Number(response.data?.year || year) } : current)
    } catch (error) {
      setTrendMessage(getApiErrorMessage(error, 'Unable to load monthly trend.'))
    } finally {
      setTrendLoading(false)
    }
  }

  function openTrend(type, title) {
    const filterYear = Number(String(filters.to || '').slice(0, 4))
    const years = data?.trend_years || []
    const initialYear = years.includes(filterYear) ? filterYear : Number(years[0] || new Date().getFullYear())
    setTrend({ type, title, year: initialYear })
    setTrendData(null)
    loadTrend(type, initialYear)
  }

  function closeTrend() {
    setTrend(null)
    setTrendData(null)
    setTrendMessage('')
  }

  async function changeTrendYear(event) {
    const year = Number(event.target.value)
    setTrend((current) => current ? { ...current, year } : current)
    await loadTrend(trend.type, year)
  }

  const summary = data?.summary || {}
  const comparison = data?.comparison || {}
  const compareLabel = useMemo(() => comparison.label || 'comparison period', [comparison.label])
  const changes = comparison.changes || {}
  const branchName = useMemo(() => {
    const selected = (data?.branches || []).find((branch) => String(branch.id) === String(filters.branch_id))
    return selected?.name || 'All Branches'
  }, [data?.branches, filters.branch_id])

  return (
    <div className="page-stack sales-report-page">
      <section className="page-heading live-page-heading">
        <div>
          <h1 className="live-page-title">Sales Dashboard <PageRefreshButton label="Refresh sales dashboard" disabled={loading} onRefresh={() => load(filters)} /></h1>
          <p className="page-subtitle">Month-to-date by default, with prior-period comparison and selectable date/branch controls.</p>
        </div>
      </section>

      {message ? <div className="form-alert error">{message}</div> : null}

      <section className="content-card">
        <form className="sales-dashboard-filters sales-dashboard-filters-compare" onSubmit={apply}>
          <label className="booking-filter-field"><span>From</span><input type="date" value={filters.from} onChange={(event) => setFilters((current) => ({ ...current, from: event.target.value }))} /></label>
          <label className="booking-filter-field"><span>To</span><input type="date" value={filters.to} onChange={(event) => setFilters((current) => ({ ...current, to: event.target.value }))} /></label>
          <label className="booking-filter-field"><span>Branch</span><SearchableSelect value={filters.branch_id} onChange={(event) => setFilters((current) => ({ ...current, branch_id: event.target.value }))}><option value="">All Branches</option>{(data?.branches || []).map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</SearchableSelect></label>
          <label className="sales-compare-toggle"><input type="checkbox" checked={filters.compare_enabled} onChange={(event) => setFilters((current) => ({ ...current, compare_enabled: event.target.checked }))} /> Compare</label>
          {filters.compare_enabled ? (
            <>
              <label className="booking-filter-field"><span>Compare From</span><input type="date" value={filters.compare_from} onChange={(event) => setFilters((current) => ({ ...current, compare_from: event.target.value }))} /></label>
              <label className="booking-filter-field"><span>Compare To</span><input type="date" value={filters.compare_to} onChange={(event) => setFilters((current) => ({ ...current, compare_to: event.target.value }))} /></label>
            </>
          ) : <div className="sales-compare-note">Auto compare: same period of previous month</div>}
          <div className="sales-filter-actions"><button className="primary-button compact-button" type="submit" disabled={loading}>Apply</button><button className="secondary-button compact-button" type="button" onClick={reset} disabled={loading}>Reset</button></div>
        </form>
      </section>

      <section className="sales-metric-grid sales-financial-metric-grid">
        <article className="content-card sales-metric-card"><span>Net Sales</span><strong>{formatPeso(summary.net_sales || 0)}</strong><small>{Number(summary.transactions || 0)} transaction(s)</small><ComparisonNote change={changes.net_sales} label={compareLabel} /></article>
        <article className="content-card sales-metric-card"><span>Gross Sales</span><strong>{formatPeso(summary.gross_sales || 0)}</strong><small>Discounts {formatPeso(summary.discounts || 0)}</small><ComparisonNote change={changes.gross_sales} label={compareLabel} /></article>
        <article className="content-card sales-metric-card"><span>Payments Received</span><strong>{formatPeso(summary.payments_received || 0)}</strong><small>Actual received collections</small><ComparisonNote change={changes.payments_received} label={compareLabel} /></article>
        <article className="content-card sales-metric-card"><span>Outstanding</span><strong>{formatPeso(summary.outstanding_balance || 0)}</strong><small>Average sale {formatPeso(summary.average_transaction || 0)}</small><ComparisonNote change={changes.outstanding_balance} label={compareLabel} /></article>
      </section>

      <section className="sales-metric-grid sales-secondary-metric-grid">
        <MetricTrendCard label="Ave Booking Value" value={formatPeso(summary.average_booking_value || 0)} note="Average finalized net value per booking" change={changes.average_booking_value} compareLabel={compareLabel} onOpen={() => openTrend('average_booking_value', 'Average Booking Value')} />
        <MetricTrendCard label="Ave Lifetime Value" value={formatPeso(summary.average_lifetime_value || 0)} note="Average cumulative paid value of active customers" change={changes.average_lifetime_value} compareLabel={compareLabel} onOpen={() => openTrend('average_lifetime_value', 'Average Lifetime Value')} />
        <MetricTrendCard label="Client Visits" value={Number(summary.client_visits || 0).toLocaleString('en-PH')} note="Completed client visits in the selected period" change={changes.client_visits} compareLabel={compareLabel} onOpen={() => openTrend('client_visits', 'Client Visits')} />
        <MetricTrendCard label="Unique Customers" value={Number(summary.unique_customers || 0).toLocaleString('en-PH')} note="Distinct customers with completed visits" change={changes.unique_customers} compareLabel={compareLabel} onOpen={() => openTrend('unique_customers', 'Unique Customers')} />
      </section>

      <div className="sales-analytics-three-column">
        <AnalyticsCard title="Sales Type" subtitle="Services vs Retail." rows={data?.sales_type || []} onOpen={() => openTrend('sales_type', 'Sales Type')} />
        <AnalyticsCard title="Sales Mix" subtitle="Services, Packages, Products." rows={data?.sales_mix || []} onOpen={() => openTrend('sales_mix', 'Sales Mix')} />
        <AnalyticsCard title="Customer Mix" subtitle="Based on completed visit frequency." rows={data?.customer_mix || []} onOpen={() => openTrend('customer_mix', 'Customer Mix')} />
        <AnalyticsCard title="Service Category" subtitle="Active service categories only." rows={data?.service_category || []} nameLabel="Category" emptyText="No active service categories." onOpen={() => openTrend('service_category', 'Service Category')} />
        <AnalyticsCard title="Booking Type" subtitle="Walk-In vs Reservation." rows={data?.booking_type || []} nameLabel="Booking Type" onOpen={() => openTrend('booking_type', 'Booking Type')} />
        <AnalyticsCard title="Customer Class" subtitle="Based on customer lifetime value." rows={data?.customer_class || []} nameLabel="Class" emptyText="No classified customer sales in this period." onOpen={() => openTrend('customer_class', 'Customer Class')} />
      </div>

      <section className="content-card">
        <div className="section-heading-row sales-top-heading">
          <div><h2>Top Performers</h2><p>Ranked by amount within the selected period.</p></div>
          <label className="sales-top-count-control sales-top-count-control-compact"><SearchableSelect aria-label="Top performer count" value={filters.top_n} onChange={(event) => changeTopN(event.target.value)} disabled={loading}><option value="5">Top 5</option><option value="10">Top 10</option><option value="20">Top 20</option><option value="30">Top 30</option></SearchableSelect></label>
        </div>
        <div className="sales-top-three-column">
          <div className="sales-top-panel"><h3>Top Services</h3><BreakdownTable rows={data?.top_services || []} nameLabel="Service" emptyText="No services sold in this period." /></div>
          <div className="sales-top-panel"><h3>Top Retail Items</h3><BreakdownTable rows={data?.top_retail_items || []} nameLabel="Item" emptyText="No retail items sold in this period." /></div>
          <div className="sales-top-panel"><h3>Top Clients</h3><BreakdownTable rows={data?.top_clients || []} nameLabel="Client" clientRows emptyText="No identified clients in this period." /></div>
        </div>
      </section>

      {trend ? (
        <Modal
          title={`${trend.title} Analytics`}
          subtitle={`${SALES_CHART_CONFIG[trend.type]?.label || 'Yearly Analytics'} · ${trend.year} · ${branchName}`}
          onClose={closeTrend}
          wide
          className="sales-trend-modal"
          headerAction={(
            <div className="sales-trend-actions">
              <label className="sales-trend-year"><span>Year</span><SearchableSelect value={trend.year} onChange={changeTrendYear} disabled={trendLoading}>{(trendData?.years || data?.trend_years || [trend.year]).map((year) => <option key={year} value={year}>{year}</option>)}</SearchableSelect></label>
              {canDownload ? <button className="secondary-button compact-button" type="button" onClick={() => downloadTrendChartImage(trendData, trend.type, branchName)} disabled={trendLoading || !trendData}>Download PNG</button> : null}
            </div>
          )}
        >
          {trendMessage ? <div className="form-alert error">{trendMessage}</div> : null}
          {trendLoading && !trendData ? <div className="empty-state">Loading analytics...</div> : null}
          {trendData ? (
            <div className="sales-trend-chart-wrap">
              <div className="sales-chart-stage">
                <img className="sales-chart-brand-logo" src={yodamenLogo} alt="YODAMEN" />
                <SalesTrendChart trendData={trendData} type={trend.type} branchName={branchName} />
              </div>
              {chartDataForTrend(trendData, trend.type)?.grouped_series?.length ? <p className="sales-chart-note">Smaller categories are grouped under Others to keep the chart readable.</p> : null}
            </div>
          ) : null}
        </Modal>
      ) : null}
    </div>
  )
}
