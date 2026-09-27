import { createBrowserRouter, Navigate } from 'react-router-dom'
import ProtectedRoute from '../features/auth/ProtectedRoute'
import ModuleRoute from '../features/auth/ModuleRoute'
import SuperAdminRoute from '../features/auth/SuperAdminRoute'
import AppLayout from '../layouts/AppLayout'
import DashboardPage from '../pages/DashboardPage'
import LoginPage from '../pages/LoginPage'
import BusinessSetupPage from '../pages/admin/BusinessSetupPage'
import BrandingPage from '../pages/admin/BrandingPage'
import ReferenceListsPage from '../pages/admin/ReferenceListsPage'
import ServicesPricingPage from '../pages/admin/ServicesPricingPage'
import CommissionRulesPage from '../pages/admin/CommissionRulesPage'
import DiscountRulesPage from '../pages/admin/DiscountRulesPage'
import IncentiveRulesPage from '../pages/admin/IncentiveRulesPage'
import UsersRolesPage from '../pages/admin/UsersRolesPage'
import DevelopmentResetPage from '../pages/admin/DevelopmentResetPage'
import OpeningBalancesPage from '../pages/admin/OpeningBalancesPage'
import AttendancePage from '../pages/hr/AttendancePage'
import MyAttendancePage from '../pages/hr/MyAttendancePage'
import LeaveManagementPage from '../pages/hr/LeaveManagementPage'
import CommissionSummaryPage from '../pages/hr/CommissionSummaryPage'
import DailyCompensationPage from '../pages/hr/DailyCompensationPage'
import CashAdvancesPage from '../pages/hr/CashAdvancesPage'
import PayrollPage from '../pages/hr/PayrollPage'
import PayslipsPage from '../pages/hr/PayslipsPage'
import MyPayrollPage from '../pages/hr/MyPayrollPage'
import PersonnelPage from '../pages/hr/PersonnelPage'
import CustomersPage from '../pages/operations/CustomersPage'
import BookingsPage from '../pages/operations/BookingsPage'
import QueuePage from '../pages/operations/QueuePage'
import ServicingPage from '../pages/operations/ServicingPage'
import TransactionsPage from '../pages/sales/TransactionsPage'
import RetailPosPage from '../pages/sales/RetailPosPage'
import SalesDashboardPage from '../pages/sales/SalesDashboardPage'
import ZReadingPage from '../pages/sales/ZReadingPage'
import RetailProductsPage from '../pages/inventory/RetailProductsPage'
import SupplierMasterlistPage from '../pages/inventory/SupplierMasterlistPage'
import InventoryDashboardPage from '../pages/inventory/InventoryDashboardPage'
import InventoryAdjustmentsPage from '../pages/inventory/InventoryAdjustmentsPage'
import ReorderMonitoringPage from '../pages/inventory/ReorderMonitoringPage'
import StockReceivedPage from '../pages/inventory/StockReceivedPage'
import StockReturnsPage from '../pages/inventory/StockReturnsPage'
import InventoryCountPage from '../pages/inventory/InventoryCountPage'
import ServiceConsumptionPage from '../pages/inventory/ServiceConsumptionPage'
import ServiceBalancesPage from '../pages/packages/ServiceBalancesPage'
import CustomerMixPage from '../pages/customers/CustomerMixPage'
import ExpensesPage from '../pages/expense/ExpensesPage'
import PettyCashPage from '../pages/expense/PettyCashPage'
import ChartOfAccountsPage from '../pages/accounting/ChartOfAccountsPage'
import { GeneralLedgerPage, TrialBalancePage, CashManagementPage, CashFlowPage, DailyClosingPage, FinancialStatementsPage } from '../pages/accounting/AccountingCorePages'
import { AccountsReceivablePage, AccountsPayablePage, CollectionsPage, BankReconciliationPage, ProfitSharingPage, MyProfitPage, AccountingMappingPage } from '../pages/accounting/AccountingOperationsPages'
import CashHandlingPage from '../pages/sales/CashHandlingPage'

export const router = createBrowserRouter([
  {
    path: '/login',
    element: <LoginPage />,
  },
  {
    element: <ProtectedRoute />,
    children: [
      {
        element: <AppLayout />,
        children: [
          { index: true, element: <DashboardPage /> },
          {
            path: '/customers',
            element: (
              <ModuleRoute module="customers">
                <CustomersPage />
              </ModuleRoute>
            ),
          },
          { path: '/operations/customers', element: <Navigate to="/customers" replace /> },
          {
            path: '/customers/service-balances',
            element: (
              <ModuleRoute module="service-balances">
                <ServiceBalancesPage />
              </ModuleRoute>
            ),
          },
          { path: '/operations/service-balances', element: <Navigate to="/customers/service-balances" replace /> },
          {
            path: '/customers/mix',
            element: (
              <ModuleRoute module="customer-type-criteria">
                <CustomerMixPage />
              </ModuleRoute>
            ),
          },
          { path: '/customers/type-criteria', element: <Navigate to="/customers/mix?tab=type" replace /> },
          { path: '/customers/class-criteria', element: <Navigate to="/customers/mix?tab=class" replace /> },
          {
            path: '/operations/bookings',
            element: (
              <ModuleRoute module="bookings">
                <BookingsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/operations/queue',
            element: (
              <ModuleRoute module="queue">
                <QueuePage />
              </ModuleRoute>
            ),
          },
          {
            path: '/operations/servicing',
            element: (
              <ModuleRoute module="servicing">
                <ServicingPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/sales/dashboard',
            element: (
              <ModuleRoute module="sales-dashboard">
                <SalesDashboardPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/sales/transactions',
            element: (
              <ModuleRoute module="sales">
                <TransactionsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/sales/z-reading',
            element: (
              <ModuleRoute module="sales">
                <ZReadingPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/sales/cash-handling',
            element: (<ModuleRoute module="cash-deposits"><CashHandlingPage /></ModuleRoute>),
          },
          {
            path: '/sales/cash-deposits',
            element: <Navigate to="/sales/cash-handling" replace />,
          },
          {
            path: '/sales/retail-pos',
            element: (
              <ModuleRoute module="retail-pos">
                <RetailPosPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/dashboard',
            element: (
              <ModuleRoute module="inventory-dashboard">
                <InventoryDashboardPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/products',
            element: (
              <ModuleRoute module="inventory-master">
                <RetailProductsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/suppliers',
            element: (
              <ModuleRoute module="supplier-master">
                <SupplierMasterlistPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/stock-received',
            element: (
              <ModuleRoute module="stock-received">
                <StockReceivedPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/stock-returns',
            element: (
              <ModuleRoute module="stock-returns">
                <StockReturnsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/service-consumption',
            element: (
              <ModuleRoute module="service-consumption">
                <ServiceConsumptionPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/count',
            element: (
              <ModuleRoute module="inventory-count">
                <InventoryCountPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/adjustments',
            element: (
              <ModuleRoute module="inventory-adjustments">
                <InventoryAdjustmentsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/inventory/reorder',
            element: (
              <ModuleRoute module="reorder">
                <ReorderMonitoringPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/expenses',
            element: (
              <ModuleRoute module="expenses">
                <ExpensesPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/expenses/petty-cash',
            element: (<ModuleRoute module="petty-cash"><PettyCashPage /></ModuleRoute>),
          },
          {
            path: '/accounting/chart-of-accounts',
            element: (<ModuleRoute module="coa"><ChartOfAccountsPage /></ModuleRoute>),
          },
          { path: '/accounting/general-ledger', element: (<ModuleRoute module="general-ledger"><GeneralLedgerPage /></ModuleRoute>) },
          { path: '/accounting/trial-balance', element: (<ModuleRoute module="trial-balance"><TrialBalancePage /></ModuleRoute>) },
          { path: '/accounting/cash-management', element: (<ModuleRoute module="cash-management"><CashManagementPage /></ModuleRoute>) },
          { path: '/accounting/cash-deposits', element: <Navigate to="/sales/cash-handling" replace /> },
          { path: '/accounting/cash-flow', element: (<ModuleRoute module="cash-flow"><CashFlowPage /></ModuleRoute>) },
          { path: '/accounting/daily-closing', element: (<ModuleRoute module="daily-closing"><DailyClosingPage /></ModuleRoute>) },
          { path: '/accounting/financial-statements', element: (<ModuleRoute module="financial-statements"><FinancialStatementsPage /></ModuleRoute>) },
          { path: '/accounting/accounts-receivable', element: (<ModuleRoute module="ar"><AccountsReceivablePage /></ModuleRoute>) },
          { path: '/accounting/accounts-payable', element: (<ModuleRoute module="ap"><AccountsPayablePage /></ModuleRoute>) },
          { path: '/accounting/collections', element: (<ModuleRoute module="collections"><CollectionsPage /></ModuleRoute>) },
          { path: '/accounting/bank-reconciliation', element: (<ModuleRoute module="bank-reconciliation"><BankReconciliationPage /></ModuleRoute>) },
          { path: '/accounting/profit-sharing', element: (<ModuleRoute module="profit-sharing"><ProfitSharingPage /></ModuleRoute>) },
          { path: '/hr/my-profit', element: (<ModuleRoute module="my-profit"><MyProfitPage /></ModuleRoute>) },
          {
            path: '/admin/business-setup',
            element: (
              <ModuleRoute module="business-setup">
                <BusinessSetupPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/branding',
            element: (
              <ModuleRoute module="branding">
                <BrandingPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/references',
            element: (
              <ModuleRoute module="references">
                <ReferenceListsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/services',
            element: (
              <ModuleRoute module="services-pricing">
                <ServicesPricingPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/commission-rules',
            element: (
              <ModuleRoute module="commission-rules">
                <CommissionRulesPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/discount-rules',
            element: (
              <ModuleRoute module="discount-rules">
                <DiscountRulesPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/incentive-rules',
            element: (
              <ModuleRoute module="incentive-rules">
                <IncentiveRulesPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/admin/accounting-mapping',
            element: (<ModuleRoute module="accounting-mapping"><AccountingMappingPage /></ModuleRoute>),
          },
          {
            path: '/admin/opening-balances',
            element: (
              <SuperAdminRoute>
                <OpeningBalancesPage />
              </SuperAdminRoute>
            ),
          },
          {
            path: '/admin/development-reset',
            element: (
              <SuperAdminRoute>
                <DevelopmentResetPage />
              </SuperAdminRoute>
            ),
          },
          {
            path: '/admin/users-roles',
            element: (
              <ModuleRoute module="users">
                <UsersRolesPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/personnel',
            element: (
              <ModuleRoute module="employees">
                <PersonnelPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/my-attendance',
            element: (
              <ModuleRoute module="my-attendance">
                <MyAttendancePage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/attendance',
            element: (
              <ModuleRoute module="attendance">
                <AttendancePage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/my-leave',
            element: <Navigate to="/hr/my-attendance?tab=leave" replace />,
          },
          {
            path: '/hr/leave',
            element: (
              <ModuleRoute module="leave">
                <LeaveManagementPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/commissions',
            element: (
              <ModuleRoute module="commissions">
                <CommissionSummaryPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/daily-compensation',
            element: (
              <ModuleRoute module="daily-compensation">
                <DailyCompensationPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/cash-advances',
            element: (
              <ModuleRoute module="cash-advances">
                <CashAdvancesPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/payroll',
            element: (
              <ModuleRoute module="payroll">
                <PayrollPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/payslips',
            element: (
              <ModuleRoute module="payslips">
                <PayslipsPage />
              </ModuleRoute>
            ),
          },
          {
            path: '/hr/my-payroll',
            element: (
              <ModuleRoute module="my-payroll" requiresOperationalCompensation>
                <MyPayrollPage />
              </ModuleRoute>
            ),
          },
          { path: '*', element: <Navigate to="/" replace /> },
        ],
      },
    ],
  },
])
