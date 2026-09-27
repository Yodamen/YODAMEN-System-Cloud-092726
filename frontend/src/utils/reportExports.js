const encoder = new TextEncoder()

function downloadBlob(blob, fileName) {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = fileName
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  window.setTimeout(() => URL.revokeObjectURL(url), 1000)
}

function normalizeSpace(value) {
  return String(value ?? '').replace(/\s+/g, ' ').trim()
}

function elementText(element) {
  if (!element) return ''
  return normalizeSpace(element.innerText || element.textContent || '')
}

function isVisible(element) {
  if (!element) return false
  const style = window.getComputedStyle(element)
  if (style.display === 'none' || style.visibility === 'hidden' || Number(style.opacity) === 0) return false
  return Boolean(element.offsetWidth || element.offsetHeight || element.getClientRects().length)
}

function closestSectionTitle(table, fallback) {
  const card = table.closest('.content-card')
  const heading = card?.querySelector('h2, h3, .section-title, .card-title')
  return elementText(heading) || fallback
}

function extractMetricSections(root) {
  const rows = []
  const seen = new Set()
  const selectors = [
    '.metric-card',
    '.cash-balance-card',
    '.accounting-balance-strip > div',
  ]
  root.querySelectorAll(selectors.join(',')).forEach((card) => {
    if (!isVisible(card) || card.closest('table')) return
    let label = ''
    let value = ''
    if (card.classList.contains('cash-balance-card')) {
      label = elementText(card.querySelector('.cash-balance-name, .cash-flow-card-label'))
      if (!label) {
        const code = elementText(card.querySelector('.cash-balance-code'))
        const name = elementText(card.querySelector('strong'))
        label = normalizeSpace(`${code} ${name}`)
      }
      value = elementText(card.querySelector('.cash-balance-amount'))
    } else {
      label = elementText(card.querySelector('span, .metric-label-row span:first-child'))
      value = elementText(card.querySelector('strong'))
    }
    if (!label || !value) return
    const key = `${label}::${value}`
    if (seen.has(key)) return
    seen.add(key)
    rows.push([label, value])
  })
  return rows.length ? [{ name: 'Summary', rows: [['Metric', 'Value'], ...rows] }] : []
}

function extractTable(table, index) {
  const headerCells = [...table.querySelectorAll('thead th')]
  if (!headerCells.length) return null
  const includedIndexes = []
  const headers = []
  headerCells.forEach((cell, idx) => {
    const text = elementText(cell)
    if (/^actions?$/i.test(text)) return
    includedIndexes.push(idx)
    headers.push(text || `Column ${idx + 1}`)
  })
  if (!includedIndexes.length) return null

  const bodyRows = [...table.querySelectorAll('tbody tr')].filter(isVisible)
  const rows = [headers]
  bodyRows.forEach((tr) => {
    const cells = [...tr.children]
    const values = includedIndexes.map((idx) => elementText(cells[idx]))
    if (values.some(Boolean)) rows.push(values)
  })

  const title = closestSectionTitle(table, index === 0 ? 'Details' : `Details ${index + 1}`)
  return { name: title, rows }
}

export function collectReportSections(root = document.querySelector('.content-area')) {
  if (!root) return []
  const sections = [...extractMetricSections(root)]
  const seenNames = new Map()
  const tables = [...root.querySelectorAll('table.data-table')].filter(isVisible)
  tables.forEach((table, index) => {
    const section = extractTable(table, index)
    if (!section || section.rows.length < 2) return
    const count = (seenNames.get(section.name) || 0) + 1
    seenNames.set(section.name, count)
    if (count > 1) section.name = `${section.name} ${count}`
    sections.push(section)
  })
  return sections
}

function xmlEscape(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;')
}

function colName(index) {
  let n = index + 1
  let out = ''
  while (n > 0) {
    const r = (n - 1) % 26
    out = String.fromCharCode(65 + r) + out
    n = Math.floor((n - 1) / 26)
  }
  return out
}

function safeSheetName(name, fallback) {
  const clean = normalizeSpace(name).replace(/[\\/*?:\[\]]/g, ' ').slice(0, 31).trim()
  return clean || fallback
}

function sheetXml(rows) {
  const xmlRows = rows.map((row, rIdx) => {
    const cells = row.map((value, cIdx) => {
      const ref = `${colName(cIdx)}${rIdx + 1}`
      const text = xmlEscape(value)
      return `<c r="${ref}" t="inlineStr"><is><t xml:space="preserve">${text}</t></is></c>`
    }).join('')
    return `<row r="${rIdx + 1}">${cells}</row>`
  }).join('')
  return `<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\n<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>${xmlRows}</sheetData></worksheet>`
}

function crc32(bytes) {
  let crc = 0xFFFFFFFF
  for (let i = 0; i < bytes.length; i += 1) {
    crc ^= bytes[i]
    for (let j = 0; j < 8; j += 1) crc = (crc >>> 1) ^ ((crc & 1) ? 0xEDB88320 : 0)
  }
  return (crc ^ 0xFFFFFFFF) >>> 0
}

function u16(value) {
  return Uint8Array.from([value & 0xFF, (value >>> 8) & 0xFF])
}

function u32(value) {
  return Uint8Array.from([value & 0xFF, (value >>> 8) & 0xFF, (value >>> 16) & 0xFF, (value >>> 24) & 0xFF])
}

function concatBytes(parts) {
  const total = parts.reduce((sum, part) => sum + part.length, 0)
  const out = new Uint8Array(total)
  let offset = 0
  parts.forEach((part) => { out.set(part, offset); offset += part.length })
  return out
}

function dosDateTime(date = new Date()) {
  const year = Math.max(1980, date.getFullYear())
  const time = (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2)
  const day = ((year - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate()
  return { time, date: day }
}

function zipStore(files) {
  const localParts = []
  const centralParts = []
  let offset = 0
  const stamp = dosDateTime()

  files.forEach((file) => {
    const name = encoder.encode(file.name)
    const data = file.data instanceof Uint8Array ? file.data : encoder.encode(file.data)
    const crc = crc32(data)
    const localHeader = concatBytes([
      u32(0x04034B50), u16(20), u16(0x0800), u16(0), u16(stamp.time), u16(stamp.date),
      u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0), name,
    ])
    localParts.push(localHeader, data)

    const central = concatBytes([
      u32(0x02014B50), u16(20), u16(20), u16(0x0800), u16(0), u16(stamp.time), u16(stamp.date),
      u32(crc), u32(data.length), u32(data.length), u16(name.length), u16(0), u16(0), u16(0), u16(0),
      u32(0), u32(offset), name,
    ])
    centralParts.push(central)
    offset += localHeader.length + data.length
  })

  const centralDirectory = concatBytes(centralParts)
  const end = concatBytes([
    u32(0x06054B50), u16(0), u16(0), u16(files.length), u16(files.length),
    u32(centralDirectory.length), u32(offset), u16(0),
  ])
  return concatBytes([...localParts, centralDirectory, end])
}

function slug(value) {
  return normalizeSpace(value).replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toUpperCase() || 'REPORT'
}

export function exportSectionsToExcel(title, sections) {
  if (!sections?.length) throw new Error('No report data is currently available to export.')

  const names = new Set()
  const normalized = sections.map((section, index) => {
    let base = safeSheetName(section.name, `Sheet ${index + 1}`)
    let name = base
    let suffix = 2
    while (names.has(name)) {
      name = safeSheetName(`${base} ${suffix}`, `Sheet ${index + 1}`)
      suffix += 1
    }
    names.add(name)
    return { ...section, name }
  })

  const workbookSheets = normalized.map((section, index) => `<sheet name="${xmlEscape(section.name)}" sheetId="${index + 1}" r:id="rId${index + 1}"/>`).join('')
  const workbookRels = normalized.map((section, index) => `<Relationship Id="rId${index + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet${index + 1}.xml"/>`).join('')
  const overrides = normalized.map((section, index) => `<Override PartName="/xl/worksheets/sheet${index + 1}.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>`).join('')

  const files = [
    { name: '[Content_Types].xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>${overrides}</Types>` },
    { name: '_rels/.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>` },
    { name: 'xl/workbook.xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>${workbookSheets}</sheets></workbook>` },
    { name: 'xl/_rels/workbook.xml.rels', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">${workbookRels}<Relationship Id="rId${normalized.length + 1}" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>` },
    { name: 'xl/styles.xml', data: `<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/><name val="Aptos"/></font></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/></cellXfs></styleSheet>` },
    ...normalized.map((section, index) => ({ name: `xl/worksheets/sheet${index + 1}.xml`, data: sheetXml(section.rows) })),
  ]

  const bytes = zipStore(files)
  downloadBlob(new Blob([bytes], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }), `${slug(title)}.xlsx`)
}

function ascii(value) {
  return String(value ?? '')
    .replace(/₱/g, 'PHP ')
    .replace(/[–—]/g, '-')
    .replace(/[“”]/g, '"')
    .replace(/[‘’]/g, "'")
    .replace(/[^\x20-\x7E]/g, '?')
}

function pdfEscape(value) {
  return ascii(value).replace(/\\/g, '\\\\').replace(/\(/g, '\\(').replace(/\)/g, '\\)')
}

function makeTextTable(rows, widthBudget = 118) {
  if (!rows?.length) return []
  const columns = Math.max(...rows.map((row) => row.length))
  const sepWidth = Math.max(0, columns - 1) * 3
  const usable = Math.max(columns * 6, widthBudget - sepWidth)
  const widths = Array.from({ length: columns }, (_, idx) => {
    const maxLen = Math.max(...rows.map((row) => ascii(row[idx] ?? '').length), 8)
    return Math.min(24, Math.max(8, maxLen))
  })
  while (widths.reduce((a, b) => a + b, 0) > usable) {
    let target = 0
    for (let i = 1; i < widths.length; i += 1) if (widths[i] > widths[target]) target = i
    if (widths[target] <= 6) break
    widths[target] -= 1
  }
  return rows.map((row) => widths.map((width, idx) => {
    const raw = ascii(row[idx] ?? '')
    const clipped = raw.length > width ? `${raw.slice(0, Math.max(1, width - 3))}...` : raw
    return clipped.padEnd(width, ' ')
  }).join(' | ').replace(/\s+$/g, ''))
}

function pdfDocument(title, sections) {
  const reportLines = []
  sections.forEach((section, index) => {
    if (index) reportLines.push('')
    reportLines.push(ascii(section.name).toUpperCase())
    reportLines.push(...makeTextTable(section.rows))
  })

  const pageSize = 45
  const pages = []
  for (let i = 0; i < reportLines.length; i += pageSize) pages.push(reportLines.slice(i, i + pageSize))
  if (!pages.length) pages.push(['No report data available.'])

  const objects = []
  const pageRefs = []
  const catalogId = 1
  const pagesId = 2
  const fontId = 3
  let nextId = 4

  pages.forEach((lines, pageIndex) => {
    const contentId = nextId++
    const pageId = nextId++
    pageRefs.push(`${pageId} 0 R`)
    let stream = 'BT\n/F1 7.5 Tf\n32 560 Td\n'
    stream += `(${pdfEscape(title)}) Tj\n0 -14 Td\n`
    stream += `(${pdfEscape(`Generated ${new Date().toLocaleString('en-PH')} | Page ${pageIndex + 1} of ${pages.length}`)}) Tj\n0 -16 Td\n`
    lines.forEach((line) => { stream += `(${pdfEscape(line)}) Tj\n0 -10 Td\n` })
    stream += 'ET\n'
    objects[contentId] = `<< /Length ${encoder.encode(stream).length} >>\nstream\n${stream}endstream`
    objects[pageId] = `<< /Type /Page /Parent ${pagesId} 0 R /MediaBox [0 0 842 595] /Resources << /Font << /F1 ${fontId} 0 R >> >> /Contents ${contentId} 0 R >>`
  })

  objects[catalogId] = `<< /Type /Catalog /Pages ${pagesId} 0 R >>`
  objects[pagesId] = `<< /Type /Pages /Kids [${pageRefs.join(' ')}] /Count ${pageRefs.length} >>`
  objects[fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>'

  let pdf = '%PDF-1.4\n%YODAMEN\n'
  const offsets = [0]
  for (let id = 1; id < objects.length; id += 1) {
    offsets[id] = encoder.encode(pdf).length
    pdf += `${id} 0 obj\n${objects[id]}\nendobj\n`
  }
  const xref = encoder.encode(pdf).length
  pdf += `xref\n0 ${objects.length}\n0000000000 65535 f \n`
  for (let id = 1; id < objects.length; id += 1) pdf += `${String(offsets[id]).padStart(10, '0')} 00000 n \n`
  pdf += `trailer\n<< /Size ${objects.length} /Root ${catalogId} 0 R >>\nstartxref\n${xref}\n%%EOF`
  return encoder.encode(pdf)
}

export function exportSectionsToPdf(title, sections) {
  if (!sections?.length) throw new Error('No report data is currently available to export.')
  const bytes = pdfDocument(title, sections)
  downloadBlob(new Blob([bytes], { type: 'application/pdf' }), `${slug(title)}.pdf`)
}
