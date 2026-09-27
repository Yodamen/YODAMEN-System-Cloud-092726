<?php

use App\Http\Controllers\Api\V1\Admin\BusinessSetupController;
use App\Http\Controllers\Api\V1\Admin\BrandingController;
use App\Http\Controllers\Api\V1\Admin\CommissionRuleController;
use App\Http\Controllers\Api\V1\Admin\DiscountRuleController;
use App\Http\Controllers\Api\V1\Admin\IncentiveRuleController;
use App\Http\Controllers\Api\V1\Admin\DevelopmentResetController;
use App\Http\Controllers\Api\V1\Admin\OpeningBalanceController;
use App\Http\Controllers\Api\V1\Admin\ReferenceDataController;
use App\Http\Controllers\Api\V1\Admin\RolePermissionController;
use App\Http\Controllers\Api\V1\Admin\ServicesPricingController;
use App\Http\Controllers\Api\V1\Admin\UserAdministrationController;
use App\Http\Controllers\Api\V1\Accounting\ChartOfAccountsController;
use App\Http\Controllers\Api\V1\Accounting\AccountingReportsController;
use App\Http\Controllers\Api\V1\Accounting\AccountingOperationsController;
use App\Http\Controllers\Api\V1\Accounting\AccountingMappingController;
use App\Http\Controllers\Api\V1\Accounting\AccountsPayableController;
use App\Http\Controllers\Api\V1\Accounting\CashDepositController;
use App\Http\Controllers\Api\V1\Accounting\CashRemittanceController;
use App\Http\Controllers\Api\V1\Accounting\CashHandlingController;
use App\Http\Controllers\Api\V1\Accounting\StaffTipController;
use App\Http\Controllers\Api\V1\Accounting\BusinessFundingController;
use App\Http\Controllers\Api\V1\Expense\PettyCashController;
use App\Http\Controllers\Api\V1\Expense\ExpenseController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BootstrapController;
use App\Http\Controllers\Api\V1\Foundation\SupportingDocumentController;
use App\Http\Controllers\Api\V1\Foundation\WorkflowReasonController;
use App\Http\Controllers\Api\V1\PublicBrandingController;
use App\Http\Controllers\Api\V1\Hr\AttendanceController;
use App\Http\Controllers\Api\V1\Hr\CashAdvanceController;
use App\Http\Controllers\Api\V1\Hr\CommissionSummaryController;
use App\Http\Controllers\Api\V1\Hr\DailyCompensationController;
use App\Http\Controllers\Api\V1\Hr\MyAttendanceController;
use App\Http\Controllers\Api\V1\Hr\MyLeaveController;
use App\Http\Controllers\Api\V1\Hr\LeaveManagementController;
use App\Http\Controllers\Api\V1\Hr\PersonnelController;
use App\Http\Controllers\Api\V1\Hr\PayrollController;
use App\Http\Controllers\Api\V1\Hr\PayslipController;
use App\Http\Controllers\Api\V1\Hr\MyPayrollController;
use App\Http\Controllers\Api\V1\Hr\WorkScheduleController;
use App\Http\Controllers\Api\V1\Inventory\RetailProductController;
use App\Http\Controllers\Api\V1\Inventory\SupplierController;
use App\Http\Controllers\Api\V1\Inventory\InventoryOperationsController;
use App\Http\Controllers\Api\V1\Inventory\InventoryControlController;
use App\Http\Controllers\Api\V1\Inventory\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\InventoryReceiptActionController;
use App\Http\Controllers\Api\V1\Inventory\StockReturnController;
use App\Http\Controllers\Api\V1\Inventory\ServiceConsumptionController;
use App\Http\Controllers\Api\V1\Packages\ServiceBalanceController;
use App\Http\Controllers\Api\V1\Operations\BookingController;
use App\Http\Controllers\Api\V1\Operations\CustomerController;
use App\Http\Controllers\Api\V1\Operations\QueueController;
use App\Http\Controllers\Api\V1\Operations\ServicingController;
use App\Http\Controllers\Api\V1\Customers\CustomerCriteriaController;
use App\Http\Controllers\Api\V1\Sales\TransactionsController;
use App\Http\Controllers\Api\V1\Sales\TransactionActionController;
use App\Http\Controllers\Api\V1\Sales\RetailPosController;
use App\Http\Controllers\Api\V1\Sales\SalesDashboardController;
use App\Http\Controllers\Api\V1\Sales\SalesDashboardTrendController;
use App\Http\Controllers\Api\V1\Sales\ZReadingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'system' => 'YODAMEN',
        'backend' => 'Laravel',
        'database' => 'MySQL',
        'timezone' => config('app.timezone'),
    ]);
});

Route::prefix('v1')->group(function () {
    Route::get('/public/branding', PublicBrandingController::class);
    Route::get('/public/branding/logo/{business}', [PublicBrandingController::class, 'logo']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::put('/auth/access-code', [AuthController::class, 'changeAccessCode']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/bootstrap', BootstrapController::class);


        Route::get('/workflow-reasons', [WorkflowReasonController::class, 'index']);
        Route::get('/workflow-reasons/contexts', [WorkflowReasonController::class, 'contexts']);


        Route::prefix('supporting-documents')->group(function () {
            Route::post('/temp', [SupportingDocumentController::class, 'uploadTemp']);
            Route::get('/', [SupportingDocumentController::class, 'index']);
            Route::get('/{supportingDocument}/download', [SupportingDocumentController::class, 'download']);
        });


        Route::prefix('customers')->group(function () {
            Route::get('/type-criteria', [CustomerCriteriaController::class, 'types'])
                ->middleware('permission:customer-type-criteria,VIEW');
            Route::put('/type-criteria', [CustomerCriteriaController::class, 'updateTypes'])
                ->middleware('permission:customer-type-criteria,EDIT');
            Route::get('/class-criteria', [CustomerCriteriaController::class, 'classes'])
                ->middleware('permission:customer-class-criteria,VIEW');
            Route::put('/class-criteria', [CustomerCriteriaController::class, 'updateClasses'])
                ->middleware('permission:customer-class-criteria,EDIT');
        });

        Route::prefix('operations')->group(function () {
            Route::get('/customers', [CustomerController::class, 'index'])
                ->middleware('permission:customers,VIEW');
            Route::get('/customers/audience-options', [CustomerController::class, 'audienceOptions'])
                ->middleware('permission:customers,VIEW');
            Route::post('/customers/audience-preview', [CustomerController::class, 'audiencePreview'])
                ->middleware('permission:customers,VIEW');
            Route::get('/customers/{customer}', [CustomerController::class, 'show'])
                ->middleware('permission:customers,VIEW');
            Route::post('/customers', [CustomerController::class, 'store'])
                ->middleware('permission:customers,CREATE');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])
                ->middleware('permission:customers,EDIT');
            Route::patch('/customers/{customer}/toggle', [CustomerController::class, 'toggle'])
                ->middleware('permission:customers,TOGGLE');

            Route::get('/bookings/bootstrap', [BookingController::class, 'bootstrap'])
                ->middleware('permission:bookings,VIEW');
            Route::get('/customers/{customer}/service-credits', [BookingController::class, 'customerServiceCredits'])
                ->middleware('permission:bookings,VIEW');
            Route::get('/bookings', [BookingController::class, 'index'])
                ->middleware('permission:bookings,VIEW');
            Route::get('/bookings/{booking}', [BookingController::class, 'show'])
                ->middleware('permission:bookings,VIEW');
            Route::post('/bookings', [BookingController::class, 'store'])
                ->middleware('permission:bookings,CREATE');
            Route::put('/bookings/{booking}', [BookingController::class, 'update'])
                ->middleware('permission:bookings,EDIT');
            Route::post('/bookings/{booking}/rebook', [BookingController::class, 'rebook'])
                ->middleware('permission:bookings,EDIT');
            Route::post('/bookings/{booking}/cancel', [BookingController::class, 'cancel'])
                ->middleware('permission:bookings,EDIT');

            Route::post('/bookings/{booking}/check-in', [QueueController::class, 'checkIn'])
                ->middleware(['permission:bookings,EDIT', 'permission:queue,CREATE']);

            Route::get('/queue/bootstrap', [QueueController::class, 'bootstrap'])
                ->middleware('permission:queue,VIEW');
            Route::get('/queue', [QueueController::class, 'index'])
                ->middleware('permission:queue,VIEW');
            Route::get('/queue/{queueEntry}', [QueueController::class, 'show'])
                ->middleware('permission:queue,VIEW');
            Route::post('/queue/walk-ins', [QueueController::class, 'createWalkIn'])
                ->middleware(['permission:queue,CREATE', 'permission:bookings,CREATE']);
            Route::post('/queue/{queueEntry}/cancel', [QueueController::class, 'cancel'])
                ->middleware('permission:queue,EDIT');
            Route::post('/queue/{queueEntry}/start-servicing', [ServicingController::class, 'startFromQueue'])
                ->middleware('permission:queue,EDIT');

            Route::get('/servicing/bootstrap', [ServicingController::class, 'bootstrap'])
                ->middleware('permission:servicing,VIEW');
            Route::get('/servicing', [ServicingController::class, 'index'])
                ->middleware('permission:servicing,VIEW');
            Route::get('/servicing/{visit}', [ServicingController::class, 'show'])
                ->middleware('permission:servicing,VIEW');
            Route::post('/servicing/{visit}/services', [ServicingController::class, 'addService'])
                ->middleware('permission:servicing,EDIT');
            Route::put('/servicing/{visit}/retail-items', [ServicingController::class, 'updateRetailItems'])
                ->middleware('permission:servicing,EDIT');
            Route::post('/servicing/{visit}/complete-services', [ServicingController::class, 'completeServices'])
                ->middleware('permission:servicing,COMPLETE');
            Route::post('/visit-services/{visitServiceLine}/start', [ServicingController::class, 'startService'])
                ->middleware('permission:servicing,EDIT');
            Route::patch('/visit-services/{visitServiceLine}/staff', [ServicingController::class, 'assignStaff'])
                ->middleware('permission:servicing,EDIT');
            Route::post('/visit-services/{visitServiceLine}/complete', [ServicingController::class, 'completeService'])
                ->middleware('permission:servicing,COMPLETE');
            Route::post('/visit-services/{visitServiceLine}/cancel', [ServicingController::class, 'cancelService'])
                ->middleware('permission:servicing,EDIT');
            Route::post('/servicing/{visit}/complete', [ServicingController::class, 'completeVisit'])
                ->middleware('permission:servicing,COMPLETE');
        });

        Route::get('/service-balances', [ServiceBalanceController::class, 'index'])
            ->middleware('permission:service-balances,VIEW');
        Route::get('/package-enrollments/{enrollment}/financials', [ServiceBalanceController::class, 'financials'])
            ->middleware('permission:service-balances,VIEW');

        Route::prefix('inventory')->group(function () {
            Route::get('/dashboard', [InventoryOperationsController::class, 'dashboard'])
                ->middleware('permission:inventory-dashboard,VIEW');
            Route::get('/dashboard/products/{retailProduct}/movements', [InventoryOperationsController::class, 'productMovements'])
                ->middleware('permission:inventory-dashboard,VIEW');
            Route::get('/movements', [InventoryOperationsController::class, 'movements'])
                ->middleware('permission:inventory-movements,VIEW');
            Route::get('/adjustments', [InventoryOperationsController::class, 'adjustments'])
                ->middleware('permission:inventory-adjustments,VIEW');
            Route::get('/reorder', [InventoryOperationsController::class, 'reorder'])
                ->middleware('permission:reorder,VIEW');
            Route::get('/suppliers', [SupplierController::class, 'index'])
                ->middleware('permission:supplier-master,VIEW');
            Route::post('/suppliers', [SupplierController::class, 'store'])
                ->middleware('permission:supplier-master,CREATE');
            Route::put('/suppliers/{supplier}', [SupplierController::class, 'update'])
                ->middleware('permission:supplier-master,EDIT');
            Route::patch('/suppliers/{supplier}/toggle', [SupplierController::class, 'toggle'])
                ->middleware('permission:supplier-master,EDIT');
            Route::get('/receipts', [InventoryControlController::class, 'receipts'])
                ->middleware('permission:stock-received,VIEW');
            Route::post('/receipts', [InventoryControlController::class, 'receive'])
                ->middleware('permission:stock-received,CREATE');
            Route::post('/receipts/{inventoryReceipt}/reversal-requests', [InventoryReceiptActionController::class, 'requestReversal'])
                ->middleware('permission:stock-received,REQUEST_REVERSAL');
            Route::post('/receipts/{inventoryReceipt}/lines/{inventoryReceiptLine}/correction-requests', [InventoryReceiptActionController::class, 'requestCorrection'])
                ->middleware('permission:stock-received,REQUEST_CORRECTION');
            Route::post('/receipt-action-requests/{inventoryReceiptActionRequest}/approve-correction', [InventoryReceiptActionController::class, 'approveCorrection'])
                ->middleware('permission:stock-received,APPROVE_CORRECTION');
            Route::post('/receipt-action-requests/{inventoryReceiptActionRequest}/reject-correction', [InventoryReceiptActionController::class, 'rejectCorrection'])
                ->middleware('permission:stock-received,APPROVE_CORRECTION');
            Route::post('/receipt-action-requests/{inventoryReceiptActionRequest}/approve-reversal', [InventoryReceiptActionController::class, 'approveReversal'])
                ->middleware('permission:stock-received,APPROVE_REVERSAL');
            Route::post('/receipt-action-requests/{inventoryReceiptActionRequest}/reject-reversal', [InventoryReceiptActionController::class, 'rejectReversal'])
                ->middleware('permission:stock-received,APPROVE_REVERSAL');
            Route::post('/receipt-action-requests/{inventoryReceiptActionRequest}/refunds', [InventoryReceiptActionController::class, 'receiveRefund'])
                ->middleware('permission:stock-received,RECEIVE_REFUND');
            Route::get('/stock-returns', [StockReturnController::class, 'index'])
                ->middleware('permission:stock-returns,VIEW');
            Route::post('/stock-returns', [StockReturnController::class, 'store'])
                ->middleware('permission:stock-returns,CREATE');
            Route::post('/stock-returns/{inventoryStockReturn}/approve', [StockReturnController::class, 'approve'])
                ->middleware('permission:stock-returns,APPROVE');
            Route::post('/stock-returns/{inventoryStockReturn}/reject', [StockReturnController::class, 'reject'])
                ->middleware('permission:stock-returns,APPROVE');
            Route::post('/stock-returns/{inventoryStockReturn}/refunds', [StockReturnController::class, 'receiveRefund'])
                ->middleware('permission:stock-returns,RECEIVE_REFUND');
            Route::get('/counts', [InventoryControlController::class, 'counts'])
                ->middleware('permission:inventory-count,VIEW');
            Route::post('/counts', [InventoryControlController::class, 'startCount'])
                ->middleware('permission:inventory-count,CREATE');
            Route::get('/counts/{inventoryCount}', [InventoryControlController::class, 'countDetail'])
                ->middleware('permission:inventory-count,VIEW');
            Route::put('/counts/{inventoryCount}', [InventoryControlController::class, 'saveCount'])
                ->middleware('permission:inventory-count,EDIT');
            Route::post('/counts/{inventoryCount}/submit', [InventoryControlController::class, 'submitCount'])
                ->middleware('permission:inventory-count,EDIT');
            Route::post('/counts/{inventoryCount}/approve', [InventoryControlController::class, 'approveCount'])
                ->middleware('permission:inventory-count,APPROVE');
            Route::post('/counts/{inventoryCount}/cancel', [InventoryControlController::class, 'cancelCount'])
                ->middleware('permission:inventory-count,EDIT');
            Route::get('/products', [RetailProductController::class, 'index'])
                ->middleware('permission:inventory-master,VIEW');
            Route::post('/products', [RetailProductController::class, 'store'])
                ->middleware('permission:inventory-master,CREATE');
            Route::put('/products/{retailProduct}', [RetailProductController::class, 'update'])
                ->middleware('permission:inventory-master,EDIT');
            Route::post('/products/{retailProduct}/approve', [RetailProductController::class, 'approve'])
                ->middleware('permission:inventory-master,APPROVE');
            Route::patch('/products/{retailProduct}/toggle', [RetailProductController::class, 'toggle'])
                ->middleware('permission:inventory-master,EDIT');
            Route::post('/products/{retailProduct}/adjust-stock', [RetailProductController::class, 'adjustStock'])
                ->middleware('permission:inventory-adjustments,CREATE');
            Route::post('/adjustment-requests', [InventoryAdjustmentController::class, 'store'])
                ->middleware('permission:inventory-adjustments,CREATE');
            Route::post('/adjustment-requests/{inventoryAdjustmentRequest}/reversal-requests', [InventoryAdjustmentController::class, 'requestReversal'])
                ->middleware('permission:inventory-adjustments,REVERSE');
            Route::post('/adjustment-requests/{inventoryAdjustmentRequest}/approve', [InventoryAdjustmentController::class, 'approve'])
                ->middleware('permission:inventory-adjustments,APPROVE');
            Route::post('/adjustment-requests/{inventoryAdjustmentRequest}/reject', [InventoryAdjustmentController::class, 'reject'])
                ->middleware('permission:inventory-adjustments,APPROVE');
            Route::get('/service-consumption/recipes', [ServiceConsumptionController::class, 'index'])
                ->middleware('permission:service-consumption,VIEW');
            Route::post('/service-consumption/recipes', [ServiceConsumptionController::class, 'store'])
                ->middleware('permission:service-consumption,CREATE');
            Route::put('/service-consumption/recipes/{serviceConsumptionRecipe}', [ServiceConsumptionController::class, 'update'])
                ->middleware('permission:service-consumption,EDIT');
        });

        Route::prefix('sales')->group(function () {
            Route::get('/retail-pos/options', [RetailPosController::class, 'options'])
                ->middleware('permission:retail-pos,VIEW');
            Route::get('/retail-pos', [RetailPosController::class, 'index'])
                ->middleware('permission:retail-pos,VIEW');
            Route::post('/retail-pos/preview', [RetailPosController::class, 'preview'])
                ->middleware('permission:retail-pos,VIEW');
            Route::post('/retail-pos', [RetailPosController::class, 'store'])
                ->middleware('permission:retail-pos,CREATE');

            Route::get('/transactions/pending', [TransactionsController::class, 'pending'])
                ->middleware('permission:sales,VIEW');
            Route::get('/transactions/reversal-options', [TransactionsController::class, 'reversalOptions'])
                ->middleware('permission:sales,VIEW');
            Route::get('/transactions/{sale}/reversal-preview', [TransactionsController::class, 'reversalPreview'])
                ->middleware('permission:sales,VIEW');
            Route::get('/transactions/{sale}/checkout', [TransactionsController::class, 'checkout'])
                ->middleware('permission:sales,VIEW');

            Route::get('/transaction-action-requests/pending', [TransactionActionController::class, 'pending'])
                ->middleware('permission:sales,VIEW');
            Route::get('/transactions/{sale}/action-context', [TransactionActionController::class, 'context'])
                ->middleware('permission:sales,VIEW');
            Route::post('/transactions/{sale}/action-requests', [TransactionActionController::class, 'store'])
                ->middleware('permission:sales,VIEW');
            Route::get('/transaction-action-requests/{transactionActionRequest}', [TransactionActionController::class, 'show'])
                ->middleware('permission:sales,VIEW');
            Route::post('/transaction-action-requests/{transactionActionRequest}/approve', [TransactionActionController::class, 'approve'])
                ->middleware('permission:sales,APPROVE_ACTION');
            Route::post('/transaction-action-requests/{transactionActionRequest}/reject', [TransactionActionController::class, 'reject'])
                ->middleware('permission:sales,APPROVE_ACTION');

            Route::post('/transactions/{sale}/discount-preview', [TransactionsController::class, 'discountPreview'])
                ->middleware('permission:sales,EDIT');

            Route::post('/transactions/{sale}/finalize-checkout', [TransactionsController::class, 'finalize'])
                ->middleware('permission:sales,EDIT');
            Route::get('/transactions/{sale}/collection', [TransactionsController::class, 'collection'])
                ->middleware('permission:sales,VIEW');
            Route::post('/transactions/{sale}/collections', [TransactionsController::class, 'collect'])
                ->middleware('permission:payments,CREATE');
            Route::get('/transactions/settlement-lines', [TransactionsController::class, 'settlementLines'])
                ->middleware('permission:sales,VIEW');
            Route::get('/on-account/customers', [TransactionsController::class, 'onAccountCustomers'])
                ->middleware('permission:payments,CREATE');
            Route::get('/on-account/customers/{customer}', [TransactionsController::class, 'customerOnAccount'])
                ->middleware('permission:payments,CREATE');
        });

        Route::prefix('expenses')->group(function () {
            Route::get('/', [ExpenseController::class, 'index'])
                ->middleware('permission:expenses,VIEW');
            Route::post('/', [ExpenseController::class, 'store'])
                ->middleware('permission:expenses,CREATE');
            Route::get('/{expense}', [ExpenseController::class, 'show'])
                ->middleware('permission:expenses,VIEW');
            Route::put('/{expense}', [ExpenseController::class, 'update'])
                ->middleware('permission:expenses,EDIT');
            Route::post('/{expense}/post', [ExpenseController::class, 'post'])
                ->middleware('permission:expenses,POST');
            Route::post('/{expense}/mark-paid', [ExpenseController::class, 'markPaid'])
                ->middleware('permission:expenses,MARK_PAID');
            Route::post('/{expense}/reverse', [ExpenseController::class, 'reverse'])
                ->middleware('permission:expenses,REVERSE');
            Route::post('/reversal-requests/{expenseReversalRequest}/approve', [ExpenseController::class, 'approveReversal'])
                ->middleware('permission:expenses,APPROVE_REVERSAL');
            Route::post('/reversal-requests/{expenseReversalRequest}/reject', [ExpenseController::class, 'rejectReversal'])
                ->middleware('permission:expenses,APPROVE_REVERSAL');
            Route::post('/{expense}/attachment', [ExpenseController::class, 'uploadAttachment'])
                ->middleware('permission:expenses,EDIT');
            Route::get('/{expense}/attachment', [ExpenseController::class, 'attachment'])
                ->middleware('permission:expenses,VIEW');
        });

        Route::prefix('petty-cash')->group(function () {
            Route::get('/', [PettyCashController::class, 'index'])->middleware('permission:petty-cash,VIEW');
            Route::get('/liquidation-preview', [PettyCashController::class, 'liquidationPreview'])->middleware('permission:petty-cash,VIEW');
            Route::post('/liquidations', [PettyCashController::class, 'liquidate'])->middleware('permission:petty-cash,CREATE');
            Route::post('/', [PettyCashController::class, 'store'])->middleware('permission:petty-cash,CREATE');
            Route::post('/{pettyCashTransaction}/reverse', [PettyCashController::class, 'reverse'])->middleware('permission:petty-cash,REVERSE');
            Route::post('/reversal-requests/{reversalApprovalRequest}/approve', [PettyCashController::class, 'approveReversal'])->middleware('permission:petty-cash,APPROVE_REVERSAL');
            Route::post('/reversal-requests/{reversalApprovalRequest}/reject', [PettyCashController::class, 'rejectReversal'])->middleware('permission:petty-cash,APPROVE_REVERSAL');
        });

        Route::prefix('accounting')->group(function () {
            Route::get('/general-ledger', [AccountingReportsController::class, 'generalLedger'])->middleware('permission:general-ledger,VIEW');
            Route::get('/trial-balance', [AccountingReportsController::class, 'trialBalance'])->middleware('permission:trial-balance,VIEW');
            Route::get('/cash-management', [AccountingReportsController::class, 'cashManagement'])->middleware('permission:cash-management,VIEW');
            Route::get('/business-funding', [BusinessFundingController::class, 'index'])->middleware('permission:cash-management,VIEW');
            Route::post('/business-funding', [BusinessFundingController::class, 'store'])->middleware('permission:cash-management,CREATE');
            Route::post('/business-funding/{businessFundingTransaction}/reverse', [BusinessFundingController::class, 'reverse'])->middleware('permission:cash-management,REVERSE');
            Route::post('/business-funding/reversal-requests/{reversalApprovalRequest}/approve', [BusinessFundingController::class, 'approveReversal'])->middleware('permission:cash-management,APPROVE_REVERSAL');
            Route::post('/business-funding/reversal-requests/{reversalApprovalRequest}/reject', [BusinessFundingController::class, 'rejectReversal'])->middleware('permission:cash-management,APPROVE_REVERSAL');
            Route::get('/cash-handling/calendar', [CashHandlingController::class, 'calendar'])->middleware('permission:cash-deposits,VIEW');
            Route::get('/cash-remittances', [CashRemittanceController::class, 'index'])->middleware('permission:cash-deposits,VIEW');
            Route::get('/cash-remittances/day-summary', [CashRemittanceController::class, 'daySummary'])->middleware('permission:cash-deposits,VIEW');
            Route::post('/cash-remittances', [CashRemittanceController::class, 'store'])->middleware('permission:cash-deposits,CREATE');
            Route::post('/cash-remittances/{cashRemittance}/reverse', [CashRemittanceController::class, 'reverse'])->middleware('permission:cash-deposits,REVERSE');
            Route::post('/cash-remittances/reversal-requests/{reversalApprovalRequest}/approve', [CashRemittanceController::class, 'approveReversal'])->middleware('permission:cash-deposits,APPROVE_REVERSAL');
            Route::post('/cash-remittances/reversal-requests/{reversalApprovalRequest}/reject', [CashRemittanceController::class, 'rejectReversal'])->middleware('permission:cash-deposits,APPROVE_REVERSAL');
            Route::get('/cash-deposits', [CashDepositController::class, 'index'])->middleware('permission:cash-deposits,VIEW');
            Route::get('/cash-deposits/day-summary', [CashDepositController::class, 'daySummary'])->middleware('permission:cash-deposits,VIEW');
            Route::post('/cash-deposits', [CashDepositController::class, 'store'])->middleware('permission:cash-deposits,CREATE');
            Route::post('/cash-deposits/{cashDeposit}/reverse', [CashDepositController::class, 'reverse'])->middleware('permission:cash-deposits,REVERSE');
            Route::post('/cash-deposits/reversal-requests/{reversalApprovalRequest}/approve', [CashDepositController::class, 'approveReversal'])->middleware('permission:cash-deposits,APPROVE_REVERSAL');
            Route::post('/cash-deposits/reversal-requests/{reversalApprovalRequest}/reject', [CashDepositController::class, 'rejectReversal'])->middleware('permission:cash-deposits,APPROVE_REVERSAL');
            Route::get('/staff-tips', [StaffTipController::class, 'index'])->middleware('permission:cash-deposits,VIEW');
            Route::post('/staff-tips/{staffTip}/payout', [StaffTipController::class, 'payout'])->middleware('permission:cash-deposits,CREATE');
            Route::get('/cash-flow', [AccountingReportsController::class, 'cashFlow'])->middleware('permission:cash-flow,VIEW');
            Route::get('/financial-statements', [AccountingReportsController::class, 'financialStatements'])->middleware('permission:financial-statements,VIEW');
            Route::get('/daily-closing', [AccountingReportsController::class, 'dailyClosings'])->middleware('permission:daily-closing,VIEW');
            Route::post('/daily-closing', [AccountingReportsController::class, 'closeDay'])->middleware('permission:daily-closing,CLOSE');
            Route::post('/daily-closing/{accountingDailyClosing}/reopen', [AccountingReportsController::class, 'reopenDay'])->middleware('permission:daily-closing,REOPEN');
            Route::get('/chart-of-accounts', [ChartOfAccountsController::class, 'index'])
                ->middleware('permission:coa,VIEW');
            Route::post('/chart-of-accounts', [ChartOfAccountsController::class, 'store'])
                ->middleware('permission:coa,CREATE');
            Route::put('/chart-of-accounts/{chartOfAccount}', [ChartOfAccountsController::class, 'update'])
                ->middleware('permission:coa,EDIT');
            Route::patch('/chart-of-accounts/{chartOfAccount}/toggle', [ChartOfAccountsController::class, 'toggle'])
                ->middleware('permission:coa,TOGGLE');

            Route::get('/accounts-receivable', [AccountingOperationsController::class, 'accountsReceivable'])->middleware('permission:ar,VIEW');
            Route::get('/accounts-payable', [AccountsPayableController::class, 'index'])->middleware('permission:ap,VIEW');
            Route::post('/accounts-payable/{sourceType}/{sourceId}/pay', [AccountsPayableController::class, 'pay'])->middleware('permission:ap,PAY');
            Route::get('/collections', [AccountingOperationsController::class, 'collections'])->middleware('permission:collections,VIEW');

            Route::get('/bank-reconciliation', [AccountingOperationsController::class, 'bankReconciliations'])->middleware('permission:bank-reconciliation,VIEW');
            Route::post('/bank-reconciliation', [AccountingOperationsController::class, 'saveBankReconciliation'])->middleware('permission:bank-reconciliation,CREATE');
            Route::put('/bank-reconciliation/{bankReconciliation}', [AccountingOperationsController::class, 'saveBankReconciliation'])->middleware('permission:bank-reconciliation,EDIT');
            Route::post('/bank-reconciliation/{bankReconciliation}/reconcile', [AccountingOperationsController::class, 'reconcileBank'])->middleware('permission:bank-reconciliation,RECONCILE');

            Route::get('/profit-sharing', [AccountingOperationsController::class, 'profitSharingRuns'])->middleware('permission:profit-sharing,VIEW');
            Route::post('/profit-sharing', [AccountingOperationsController::class, 'createProfitSharing'])->middleware('permission:profit-sharing,CREATE');
            Route::post('/profit-sharing/{profitSharingRun}/recalculate', [AccountingOperationsController::class, 'recalculateProfitSharing'])->middleware('permission:profit-sharing,EDIT');
            Route::post('/profit-sharing/{profitSharingRun}/approve', [AccountingOperationsController::class, 'approveProfitSharing'])->middleware('permission:profit-sharing,APPROVE');
            Route::post('/profit-sharing/{profitSharingRun}/pay', [AccountingOperationsController::class, 'payProfitSharing'])->middleware('permission:profit-sharing,MARK_PAID');
            Route::get('/my-profit', [AccountingOperationsController::class, 'myProfit'])->middleware('permission:my-profit,VIEW');

            Route::get('/mapping', [AccountingMappingController::class, 'index'])->middleware('permission:accounting-mapping,VIEW');
            Route::put('/mapping/products/{productId}', [AccountingMappingController::class, 'updateProduct'])->middleware('permission:accounting-mapping,EDIT');
            Route::put('/mapping/payment-methods/{methodId}', [AccountingMappingController::class, 'updatePaymentMethod'])->middleware('permission:accounting-mapping,EDIT');
        });

        Route::prefix('admin')->group(function () {
            Route::get('/development-reset', [DevelopmentResetController::class, 'status']);
            Route::post('/development-reset/arm', [DevelopmentResetController::class, 'arm']);
            Route::post('/development-reset/execute', [DevelopmentResetController::class, 'execute']);
            Route::post('/development-reset/cancel', [DevelopmentResetController::class, 'cancel']);

            Route::get('/opening-balances', [OpeningBalanceController::class, 'index']);
            Route::post('/opening-balances', [OpeningBalanceController::class, 'store']);

            Route::get('/business-setup', [BusinessSetupController::class, 'show'])
                ->middleware('permission:business-setup,VIEW');
            Route::put('/business-setup', [BusinessSetupController::class, 'update'])
                ->middleware('permission:business-setup,EDIT');
            Route::post('/business-setup/branches', [BusinessSetupController::class, 'storeBranch'])
                ->middleware('permission:business-setup,MANAGE_BRANCHES');
            Route::put('/business-setup/branches/{branch}', [BusinessSetupController::class, 'updateBranch'])
                ->middleware('permission:business-setup,MANAGE_BRANCHES');
            Route::patch('/business-setup/branches/{branch}/toggle', [BusinessSetupController::class, 'toggleBranch'])
                ->middleware('permission:business-setup,MANAGE_BRANCHES');

            Route::get('/branding', [BrandingController::class, 'show'])
                ->middleware('permission:branding,VIEW');
            Route::put('/branding', [BrandingController::class, 'update'])
                ->middleware('permission:branding,EDIT');
            Route::post('/branding/logo', [BrandingController::class, 'uploadLogo'])
                ->middleware('permission:branding,EDIT');
            Route::get('/users', [UserAdministrationController::class, 'index'])
                ->middleware('permission:users,VIEW');
            Route::post('/users', [UserAdministrationController::class, 'store'])
                ->middleware('permission:users,CREATE');
            Route::put('/users/{user}', [UserAdministrationController::class, 'update'])
                ->middleware('permission:users,EDIT');
            Route::patch('/users/{user}/toggle', [UserAdministrationController::class, 'toggle'])
                ->middleware('permission:users,EDIT');
            Route::post('/users/{user}/reset-access-code', [UserAdministrationController::class, 'resetAccessCode'])
                ->middleware('permission:users,RESET_CODE');

            Route::get('/roles', [RolePermissionController::class, 'index'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');
            Route::post('/roles', [RolePermissionController::class, 'store'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');
            Route::put('/roles/{role}', [RolePermissionController::class, 'save'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');
            Route::patch('/roles/{role}/toggle', [RolePermissionController::class, 'toggle'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');
            Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])
                ->middleware('permission:users,MANAGE_PERMISSIONS');

            Route::get('/references/categories', [ReferenceDataController::class, 'categories'])
                ->middleware('permission:references,VIEW');
            Route::get('/references', [ReferenceDataController::class, 'index'])
                ->middleware('permission:references,VIEW');
            Route::post('/references', [ReferenceDataController::class, 'store'])
                ->middleware('permission:references,CREATE');
            Route::put('/references/{referenceValue}', [ReferenceDataController::class, 'update'])
                ->middleware('permission:references,EDIT');
            Route::patch('/references/{referenceValue}/toggle', [ReferenceDataController::class, 'toggle'])
                ->middleware('permission:references,TOGGLE');

            Route::get('/services', [ServicesPricingController::class, 'index'])
                ->middleware('permission:services-pricing,VIEW');
            Route::get('/services/{serviceRecord}', [ServicesPricingController::class, 'show'])
                ->middleware('permission:services-pricing,VIEW');
            Route::post('/services', [ServicesPricingController::class, 'store'])
                ->middleware('permission:services-pricing,CREATE');
            Route::put('/services/{serviceRecord}', [ServicesPricingController::class, 'update'])
                ->middleware('permission:services-pricing,EDIT');
            Route::post('/services/{serviceRecord}/prices', [ServicesPricingController::class, 'changePrice'])
                ->middleware('permission:services-pricing,CHANGE_PRICE');


            Route::get('/commission-rules', [CommissionRuleController::class, 'index'])
                ->middleware('permission:commission-rules,VIEW');
            Route::post('/commission-rules', [CommissionRuleController::class, 'store'])
                ->middleware('permission:commission-rules,CREATE');
            Route::put('/commission-rules/{commissionRuleId}', [CommissionRuleController::class, 'update'])
                ->middleware('permission:commission-rules,EDIT');
            Route::patch('/commission-rules/{commissionRuleId}/toggle', [CommissionRuleController::class, 'toggle'])
                ->middleware('permission:commission-rules,TOGGLE');


            Route::get('/discount-rules', [DiscountRuleController::class, 'index'])
                ->middleware('permission:discount-rules,VIEW');
            Route::post('/discount-rules', [DiscountRuleController::class, 'store'])
                ->middleware('permission:discount-rules,CREATE');
            Route::put('/discount-rules/{discountRuleId}', [DiscountRuleController::class, 'update'])
                ->middleware('permission:discount-rules,EDIT');
            Route::patch('/discount-rules/{discountRuleId}/toggle', [DiscountRuleController::class, 'toggle'])
                ->middleware('permission:discount-rules,TOGGLE');


            Route::get('/incentive-rules', [IncentiveRuleController::class, 'index'])
                ->middleware('permission:incentive-rules,VIEW');
            Route::post('/incentive-rules', [IncentiveRuleController::class, 'store'])
                ->middleware('permission:incentive-rules,CREATE');
            Route::put('/incentive-rules/{incentiveRuleId}', [IncentiveRuleController::class, 'update'])
                ->middleware('permission:incentive-rules,EDIT');
            Route::patch('/incentive-rules/{incentiveRuleId}/toggle', [IncentiveRuleController::class, 'toggle'])
                ->middleware('permission:incentive-rules,TOGGLE');
        });

        Route::get('/sales-dashboard', SalesDashboardController::class)
            ->middleware('permission:sales-dashboard,VIEW');
        Route::get('/sales-dashboard/trend', SalesDashboardTrendController::class)
            ->middleware('permission:sales-dashboard,VIEW');
        Route::get('/z-readings/preview', [ZReadingController::class, 'preview'])
            ->middleware('permission:sales-dashboard,VIEW');
        Route::post('/z-readings/close', [ZReadingController::class, 'close'])
            ->middleware('permission:sales,EDIT');
        Route::post('/z-readings/{zReading}/reopen', [ZReadingController::class, 'reopen'])
            ->middleware('permission:sales,EDIT');

        Route::prefix('hr')->group(function () {
            Route::get('/personnel', [PersonnelController::class, 'index'])
                ->middleware('permission:employees,VIEW');
            Route::post('/personnel', [PersonnelController::class, 'store'])
                ->middleware('permission:employees,CREATE');
            Route::put('/personnel/{personnel}', [PersonnelController::class, 'update'])
                ->middleware('permission:employees,EDIT');

            Route::get('/personnel/{personnel}/work-schedule', [WorkScheduleController::class, 'show'])
                ->middleware('permission:employees,VIEW');
            Route::put('/personnel/{personnel}/work-schedule', [WorkScheduleController::class, 'update'])
                ->middleware('permission:employees,EDIT');

            Route::get('/attendance', [AttendanceController::class, 'index'])
                ->middleware('permission:attendance,VIEW');
            Route::post('/attendance/{attendance}/approve', [AttendanceController::class, 'approve'])
                ->middleware('permission:attendance,EDIT');
            Route::put('/attendance/{attendance}/adjust', [AttendanceController::class, 'adjust'])
                ->middleware('permission:attendance,EDIT');
            Route::get('/attendance/{attendance}/proof/{type}', [AttendanceController::class, 'proof'])
                ->middleware('permission:attendance,VIEW');

            Route::get('/my-attendance', [MyAttendanceController::class, 'index'])
                ->middleware('permission:my-attendance,VIEW');
            Route::get('/my-attendance/calendar', [MyAttendanceController::class, 'calendar'])
                ->middleware('permission:my-attendance,VIEW');
            Route::post('/my-attendance/time-in', [MyAttendanceController::class, 'timeIn'])
                ->middleware('permission:my-attendance,VIEW');
            Route::post('/my-attendance/time-out', [MyAttendanceController::class, 'timeOut'])
                ->middleware('permission:my-attendance,VIEW');

            Route::get('/my-leave', [MyLeaveController::class, 'index'])
                ->middleware('permission:my-attendance,VIEW');
            Route::post('/my-leave', [MyLeaveController::class, 'store'])
                ->middleware('permission:my-attendance,VIEW');
            Route::patch('/my-leave/{leaveRequest}/cancel', [MyLeaveController::class, 'cancel'])
                ->middleware('permission:my-attendance,VIEW');

            Route::get('/my-payroll', [MyPayrollController::class, 'index'])
                ->middleware('permission:my-payroll,VIEW');

            Route::get('/payroll', [PayrollController::class, 'index'])
                ->middleware('permission:payroll,VIEW');
            Route::post('/payroll', [PayrollController::class, 'store'])
                ->middleware('permission:payroll,CREATE');
            Route::get('/payroll/{payrollRun}', [PayrollController::class, 'show'])
                ->middleware('permission:payroll,VIEW');
            Route::post('/payroll/{payrollRun}/recalculate', [PayrollController::class, 'recalculate'])
                ->middleware('permission:payroll,CREATE');
            Route::post('/payroll/{payrollRun}/approve', [PayrollController::class, 'approve'])
                ->middleware('permission:payroll,APPROVE');
            Route::post('/payroll/{payrollRun}/mark-paid', [PayrollController::class, 'markPaid'])
                ->middleware('permission:payroll,MARK_PAID');
            Route::post('/payroll/{payrollRun}/cancel', [PayrollController::class, 'cancel'])
                ->middleware('permission:payroll,CREATE');
            Route::post('/payroll/{payrollRun}/cash-advances/{cashAdvance}/waive', [PayrollController::class, 'waiveCashAdvance'])
                ->middleware('permission:payroll,APPROVE');
            Route::post('/payroll/{payrollRun}/cash-advances/{cashAdvance}/restore', [PayrollController::class, 'restoreCashAdvance'])
                ->middleware('permission:payroll,APPROVE');
            Route::post('/payroll-adjustments', [PayrollController::class, 'adjustment'])
                ->middleware('permission:payroll,CREATE');
            Route::post('/payroll-adjustments/{payrollAdjustment}/reverse', [PayrollController::class, 'reverseAdjustment'])
                ->middleware('permission:payroll,CREATE');
            Route::post('/payroll-adjustment-reversal-requests/{reversalApprovalRequest}/approve', [PayrollController::class, 'approveAdjustmentReversal'])
                ->middleware('permission:payroll,APPROVE_REVERSAL');
            Route::post('/payroll-adjustment-reversal-requests/{reversalApprovalRequest}/reject', [PayrollController::class, 'rejectAdjustmentReversal'])
                ->middleware('permission:payroll,APPROVE_REVERSAL');

            Route::get('/payslips', [PayslipController::class, 'index'])
                ->middleware('permission:payslips,VIEW');

            Route::get('/commissions', [CommissionSummaryController::class, 'index'])
                ->middleware('permission:commissions,VIEW');

            Route::get('/daily-compensation', [DailyCompensationController::class, 'index'])
                ->middleware('permission:daily-compensation,VIEW');

            Route::get('/cash-advances', [CashAdvanceController::class, 'index'])
                ->middleware('permission:cash-advances,VIEW');
            Route::post('/cash-advances', [CashAdvanceController::class, 'store'])
                ->middleware('permission:cash-advances,CREATE');
            Route::post('/cash-advances/{cashAdvance}/approve', [CashAdvanceController::class, 'approve'])
                ->middleware('permission:cash-advances,APPROVE');
            Route::post('/cash-advances/{cashAdvance}/repayments', [CashAdvanceController::class, 'repayment'])
                ->middleware('permission:cash-advances,EDIT');
            Route::post('/cash-advances/{cashAdvance}/write-off', [CashAdvanceController::class, 'writeOff'])
                ->middleware('permission:cash-advances,APPROVE');
            Route::post('/daily-compensation/rebuild', [DailyCompensationController::class, 'rebuild'])
                ->middleware('permission:daily-compensation,EDIT');

            Route::get('/leave-requests', [LeaveManagementController::class, 'index'])
                ->middleware('permission:leave,VIEW');
            Route::get('/leave-balances/{personnel}', [LeaveManagementController::class, 'balances'])
                ->middleware('permission:leave,VIEW');
            Route::post('/leave-requests/{leaveRequest}/approve', [LeaveManagementController::class, 'approve'])
                ->middleware('permission:leave,APPROVE');
            Route::post('/leave-requests/{leaveRequest}/reject', [LeaveManagementController::class, 'reject'])
                ->middleware('permission:leave,APPROVE');
            Route::put('/leave-balances/{personnel}/{leaveType}/{year}', [LeaveManagementController::class, 'saveBalance'])
                ->middleware('permission:leave,EDIT');
        });
    });
});
