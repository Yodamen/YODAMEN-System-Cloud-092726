import { useMemo, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { collectReportSections, exportSectionsToExcel, exportSectionsToPdf } from '../../utils/reportExports'
import { useAuth } from '../../context/AuthContext'

const EXPORTABLE_ROUTES = {
  '/customers': { title: 'Customer List', module: 'customers' },
  '/customers/service-balances': { title: 'Customer Service Balances', module: 'service-balances' },
  '/sales/transactions': { title: 'Sales Transactions', module: 'sales' },
  '/sales/z-reading': { title: 'Z Reading', module: 'sales' },
  '/sales/cash-handling': { title: 'Cash Handling', module: 'cash-deposits' },
  '/sales/dashboard': { title: 'Sales Dashboard', module: 'sales-dashboard' },
  '/accounting/collections': { title: 'Collections', module: 'collections' },
  '/expenses': { title: 'Expense Log', module: 'expenses' },
  '/expenses/petty-cash': { title: 'Petty Cash Ledger', module: 'petty-cash' },
  '/hr/attendance': { title: 'Attendance', module: 'attendance' },
  '/hr/daily-compensation': { title: 'Daily Compensation', module: 'daily-compensation' },
  '/hr/commissions': { title: 'Commission Summary', module: 'commissions' },
  '/hr/cash-advances': { title: 'Cash Advances', module: 'cash-advances' },
  '/hr/payroll': { title: 'Payroll Register', module: 'payroll' },
  '/hr/payslips': { title: 'Payslips', module: 'payslips' },
  '/inventory/products': { title: 'Inventory Masterlist', module: 'inventory-master' },
  '/inventory/stock-received': { title: 'Stock Received', module: 'stock-received' },
  '/inventory/stock-returns': { title: 'Stock Returns', module: 'stock-returns' },
  '/inventory/service-consumption': { title: 'Service Consumption', module: 'service-consumption' },
  '/inventory/count': { title: 'Inventory Audit Count', module: 'inventory-count' },
  '/inventory/adjustments': { title: 'Inventory Adjustments', module: 'inventory-adjustments' },
  '/accounting/chart-of-accounts': { title: 'Chart of Accounts', module: 'coa' },
  '/accounting/general-ledger': { title: 'General Ledger', module: 'general-ledger' },
  '/accounting/trial-balance': { title: 'Trial Balance', module: 'trial-balance' },
  '/accounting/cash-management': { title: 'Cash and Bank Balances', module: 'cash-management' },
  '/accounting/cash-flow': { title: 'Cash Flow Statement', module: 'cash-flow' },
  '/accounting/financial-statements': { title: 'Financial Statements', module: 'financial-statements' },
  '/accounting/accounts-receivable': { title: 'Accounts Receivable Aging', module: 'ar' },
  '/accounting/accounts-payable': { title: 'Accounts Payable Aging', module: 'ap' },
}


function currentExport(pathname) {
  return EXPORTABLE_ROUTES[pathname] || null
}

export default function PageReportExport() {
  const location = useLocation()
  const { hasPermission } = useAuth()
  const [message, setMessage] = useState('')
  const config = useMemo(() => currentExport(location.pathname), [location.pathname])

  if (!config || !hasPermission(config.module, 'DOWNLOAD')) return null
  const { title } = config

  const run = (type) => {
    try {
      setMessage('')
      const sections = collectReportSections(document.querySelector('.content-area'))
      if (!sections.length) throw new Error('No visible report data is available on this page yet.')
      if (type === 'excel') exportSectionsToExcel(title, sections)
      else exportSectionsToPdf(title, sections)
    } catch (error) {
      setMessage(error?.message || 'Unable to export this report.')
    }
  }

  return <div className="page-report-export-shell">
    <div className="page-report-export-toolbar" aria-label="Report downloads">
      <span className="page-report-export-label">Download</span>
      <button type="button" className="secondary-button compact-button" onClick={() => run('excel')}>Excel</button>
      <button type="button" className="secondary-button compact-button" onClick={() => run('pdf')}>PDF</button>
    </div>
    {message ? <div className="page-report-export-message">{message}</div> : null}
  </div>
}
