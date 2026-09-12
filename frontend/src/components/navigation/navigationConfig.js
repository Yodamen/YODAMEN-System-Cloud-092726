export const navigationSections = [
  {
    label: 'Main',
    items: [
      { label: 'Dashboard', path: '/', module: 'dashboard', enabled: true },
    ],
  },
  {
    label: 'Operations',
    items: [
      { label: 'Customers', path: '/operations/customers', module: 'customers', enabled: true },
      { label: 'Bookings', path: '/operations/bookings', module: 'bookings', enabled: true },
      { label: 'Queuing', path: '/operations/queue', module: 'queue', enabled: true },
      { label: 'Servicing', path: '/operations/servicing', module: 'servicing', enabled: true },
      { label: 'Service Balances', module: 'service-balances' },
    ],
  },
  {
    label: 'Sales',
    items: [
      { label: 'Dashboard', module: 'sales-dashboard' },
      { label: 'Z-Reading', module: 'sales' },
      { label: 'Transactions', module: 'sales' },
    ],
  },
  {
    label: 'HR & Payroll',
    items: [
      { label: 'My Payroll', module: 'my-payroll' },
      { label: 'My Profit', module: 'my-profit' },
      { label: 'My Attendance', module: 'my-attendance' },
      { label: 'Personnel', path: '/hr/personnel', module: 'employees', enabled: true },
      { label: 'Attendance', module: 'attendance' },
      { label: 'Daily Compensation', module: 'daily-compensation' },
      { label: 'Commission Summary', module: 'commissions' },
      { label: 'Cash Advances', module: 'cash-advances' },
      { label: 'Payroll', module: 'payroll' },
      { label: 'Payslips', module: 'payslips' },
      { label: 'Leave & Adjustments', module: 'leave' },
    ],
  },
  {
    label: 'Accounting',
    items: [
      { label: 'Profit Sharing', module: 'profit-sharing' },
      { label: 'Accounts Receivable', module: 'ar' },
      { label: 'Collections', module: 'collections' },
      { label: 'Expenses', module: 'expenses' },
      { label: 'Petty Cash', module: 'petty-cash' },
      { label: 'Bank Reconciliation', module: 'bank-reconciliation' },
      { label: 'Cash Management', module: 'cash-management' },
      { label: 'Daily Closing', module: 'daily-closing' },
      { label: 'Cash Flow Dashboard', module: 'cash-flow' },
      { label: 'Financial Statements', module: 'financial-statements' },
    ],
  },
  {
    label: 'Inventory',
    items: [
      { label: 'Inventory Dashboard', module: 'inventory-dashboard' },
      { label: 'Inventory Master', module: 'inventory-master' },
      { label: 'Stock Received', module: 'stock-received' },
      { label: 'Inventory Movements', module: 'inventory-movements' },
      { label: 'Service Consumption', module: 'service-consumption' },
      { label: 'Inventory Count', module: 'inventory-count' },
      { label: 'Adjustments', module: 'inventory-adjustments' },
      { label: 'Reorder Monitoring', module: 'reorder' },
    ],
  },
  {
    label: 'Reports',
    items: [
      { label: 'Sales', module: 'report-sales' },
      { label: 'Customers', module: 'report-customers' },
      { label: 'Services', module: 'report-services' },
      { label: 'Staff Performance', module: 'report-staff' },
      { label: 'HR & Payroll', module: 'report-hr' },
      { label: 'Accounting', module: 'report-accounting' },
      { label: 'Inventory', module: 'report-inventory' },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Business Setup', module: 'business-setup' },
      { label: 'Branding & Appearance', module: 'branding' },
      { label: 'Reference Lists', path: '/admin/references', module: 'references', enabled: true },
      { label: 'Services & Pricing', path: '/admin/services', module: 'services-pricing', enabled: true },
      { label: 'Commission Rules', module: 'commission-rules' },
      { label: 'Users & Roles', module: 'users' },
    ],
  },
]
