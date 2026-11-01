<?php

use App\Modules\Accounting\Http\Controllers\AccountController;
use App\Modules\Accounting\Http\Controllers\BankReconciliationController;
use App\Modules\Accounting\Http\Controllers\FiscalPeriodController;
use App\Modules\Accounting\Http\Controllers\JournalController;
use App\Modules\Accounting\Http\Controllers\ReportController;
use App\Modules\Catalogue\Http\Controllers\PriceListController;
use App\Modules\Catalogue\Http\Controllers\ProductCategoryController;
use App\Modules\Catalogue\Http\Controllers\ProductController;
use App\Modules\Catalogue\Http\Controllers\RebateCategoryController;
use App\Modules\Catalogue\Http\Controllers\UnitController;
use App\Modules\Inventory\Http\Controllers\StockAdjustmentController;
use App\Modules\Inventory\Http\Controllers\ExpiryController;
use App\Modules\Fleet\Http\Controllers\FuelController;
use App\Modules\Fleet\Http\Controllers\VehicleController;
use App\Modules\Hr\Http\Controllers\EmployeeController;
use App\Modules\Hr\Http\Controllers\PayItemController;
use App\Modules\Hr\Http\Controllers\PayrollController;
use App\Modules\Hr\Http\Controllers\StaffChargeController;
use App\Modules\Fleet\Http\Controllers\ImprestController;
use App\Modules\Sales\Http\Controllers\MixLayoutController;
use App\Modules\Organization\Http\Controllers\BusinessController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockCountController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Organization\Http\Controllers\AuditLogController;
use App\Modules\Rebates\Http\Controllers\RebateAgreementController;
use App\Modules\Containers\Http\Controllers\ContainerController;
use App\Modules\Containers\Http\Controllers\ContainerTypeController;
use App\Modules\Expenses\Http\Controllers\ExpenseCategoryController;
use App\Modules\Expenses\Http\Controllers\ExpenseController;
use App\Modules\FieldSales\Http\Controllers\FieldSyncController;
use App\Modules\FieldSales\Http\Controllers\RepController;
use App\Modules\FieldSales\Http\Controllers\SyncIssueController;
use App\Modules\FieldSales\Http\Controllers\TripController;
use App\Modules\Sales\Http\Controllers\CustomerController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\PosTerminalController;
use App\Modules\Sales\Http\Controllers\SaleController;
use App\Modules\Rebates\Http\Controllers\RebateCalculationController;
use App\Modules\Procurement\Http\Controllers\GoodsReceiptController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\SupplierController;
use App\Modules\Organization\Http\Controllers\AuthController;
use App\Modules\Organization\Http\Controllers\BranchController;
use App\Modules\Organization\Http\Controllers\DashboardController;
use App\Modules\Organization\Http\Controllers\RoleController;
use App\Modules\Organization\Http\Controllers\StoreController;
use App\Modules\Organization\Http\Controllers\UserController;
use App\Modules\Organization\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public
    Route::prefix('auth')->group(function () {
        Route::post('register-store', [AuthController::class, 'registerStore'])->middleware('throttle:5,1');
        Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');
        Route::post('forgot-password', [\App\Modules\Organization\Http\Controllers\PasswordResetController::class, 'forgot'])->middleware('throttle:5,1');
        Route::post('reset-password', [\App\Modules\Organization\Http\Controllers\PasswordResetController::class, 'reset'])->middleware('throttle:10,1');
    });

    // Authenticated + tenant resolved
    Route::post('paystack/webhook', [\App\Modules\Organization\Http\Controllers\SubscriptionController::class, 'webhook']);

    Route::middleware(['auth:sanctum', 'tenant', 'subscription', 'menus', 'warehouse'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('auth/me', [AuthController::class, 'me']);

        Route::put('business', [BusinessController::class, 'update'])->middleware('can:business.manage');
        Route::get('business/logo', [BusinessController::class, 'logo']);
        Route::post('business/logo', [BusinessController::class, 'uploadLogo'])->middleware('can:business.manage');
        Route::delete('business/logo', [BusinessController::class, 'removeLogo'])->middleware('can:business.manage');

        Route::get('overview', [\App\Modules\Organization\Http\Controllers\OverviewController::class, 'show']);
        Route::get('notifications', [\App\Modules\Organization\Http\Controllers\NotificationController::class, 'index']);
        Route::get('notifications/email', [\App\Modules\Organization\Http\Controllers\NotificationController::class, 'emailSettings']);
        Route::put('notifications/email', [\App\Modules\Organization\Http\Controllers\NotificationController::class, 'saveEmailSettings']);
        Route::post('notifications/read', [\App\Modules\Organization\Http\Controllers\NotificationController::class, 'read']);
        Route::get('dashboard/summary', [DashboardController::class, 'summary']);

        Route::get('businesses', [\App\Modules\Organization\Http\Controllers\BusinessSwitchController::class, 'index']);
        Route::post('businesses', [\App\Modules\Organization\Http\Controllers\BusinessSwitchController::class, 'store'])->middleware('can:business.manage');
        Route::post('businesses/{id}/switch', [\App\Modules\Organization\Http\Controllers\BusinessSwitchController::class, 'switch']);
        Route::get('workspace/options', [\App\Modules\Organization\Http\Controllers\BusinessSwitchController::class, 'scopes']);

        Route::get('subscription', [\App\Modules\Organization\Http\Controllers\SubscriptionController::class, 'show'])->middleware('can:business.manage');
        Route::post('subscription/checkout', [\App\Modules\Organization\Http\Controllers\SubscriptionController::class, 'checkout'])->middleware('can:business.manage');
        Route::post('subscription/verify', [\App\Modules\Organization\Http\Controllers\SubscriptionController::class, 'verify'])->middleware('can:business.manage');

        // Platform only
        Route::middleware('can:platform')->group(function () {
            Route::get('stores', [StoreController::class, 'index']);
            Route::post('stores', [StoreController::class, 'store']);
            Route::get('stores/{store}', [StoreController::class, 'show']);
            Route::put('stores/{store}', [StoreController::class, 'update']);

            Route::get('platform/subscriptions', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'index']);
            Route::put('platform/subscription-settings', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'settings']);
            Route::post('platform/plans', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'savePlan']);
            Route::put('platform/plans/{id}', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'savePlan']);
            Route::get('platform/subscriptions/{storeId}/payments', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'payments']);
            Route::post('platform/subscriptions/{storeId}/trial', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'trial']);
            Route::post('platform/subscriptions/{storeId}/extend', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'extend']);
            Route::post('platform/subscriptions/{storeId}/payment', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'payment']);
            Route::put('platform/subscriptions/{storeId}/override', [\App\Modules\Organization\Http\Controllers\PlatformSubscriptionController::class, 'override']);
        });

        Route::get('branches', [BranchController::class, 'index'])->middleware('can:branches.view');
        Route::post('branches', [BranchController::class, 'store'])->middleware('can:branches.create');
        Route::get('branches/{branch}', [BranchController::class, 'show'])->middleware('can:branches.view');
        Route::put('branches/{branch}', [BranchController::class, 'update'])->middleware('can:branches.update');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->middleware('can:branches.delete');

        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('can:warehouses.view');
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware('can:warehouses.create');
        Route::get('warehouses/{warehouse}', [WarehouseController::class, 'show'])->middleware('can:warehouses.view');
        Route::put('warehouses/{warehouse}', [WarehouseController::class, 'update'])->middleware('can:warehouses.update');
        Route::delete('warehouses/{warehouse}', [WarehouseController::class, 'destroy'])->middleware('can:warehouses.delete');

        Route::get('users', [UserController::class, 'index'])->middleware('can:users.view');
        Route::post('users', [UserController::class, 'store'])->middleware('can:users.create');
        Route::get('users/{id}', [UserController::class, 'show'])->middleware('can:users.view');
        Route::put('users/{id}', [UserController::class, 'update'])->middleware('can:users.update');

        // The role list is also needed by the user form, so RoleController::index checks its own permissions.
        Route::get('roles', [RoleController::class, 'index']);
        Route::get('roles/permissions', [RoleController::class, 'permissions'])->middleware('can:roles.view');
        Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.manage');
        Route::put('roles/{id}', [RoleController::class, 'update'])->middleware('can:roles.manage');
        Route::delete('roles/{id}', [RoleController::class, 'destroy'])->middleware('can:roles.manage');

        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('can:audit.view');

        // Catalogue
        Route::get('product-categories', [ProductCategoryController::class, 'index'])->middleware('can:products.view');
        Route::post('product-categories', [ProductCategoryController::class, 'store'])->middleware('can:products.manage');
        Route::put('product-categories/{id}', [ProductCategoryController::class, 'update'])->middleware('can:products.manage');
        Route::delete('product-categories/{id}', [ProductCategoryController::class, 'destroy'])->middleware('can:products.manage');

        Route::get('rebate-categories', [RebateCategoryController::class, 'index'])->middleware('can:products.view');
        Route::post('rebate-categories', [RebateCategoryController::class, 'store'])->middleware('can:products.manage');
        Route::put('rebate-categories/{id}', [RebateCategoryController::class, 'update'])->middleware('can:products.manage');

        Route::get('units', [UnitController::class, 'index'])->middleware('can:products.view');
        Route::post('units', [UnitController::class, 'store'])->middleware('can:products.manage');
        Route::put('units/{id}', [UnitController::class, 'update'])->middleware('can:products.manage');
        Route::delete('units/{id}', [UnitController::class, 'destroy'])->middleware('can:products.manage');

        Route::get('mix-layouts', [MixLayoutController::class, 'index'])->middleware('can:products.view');
        Route::post('mix-layouts', [MixLayoutController::class, 'store'])->middleware('can:products.manage');
        Route::put('mix-layouts/{id}', [MixLayoutController::class, 'update'])->middleware('can:products.manage');
        Route::delete('mix-layouts/{id}', [MixLayoutController::class, 'destroy'])->middleware('can:products.manage');

        Route::get('products', [ProductController::class, 'index'])->middleware('can:products.view');
        Route::post('products', [ProductController::class, 'store'])->middleware('can:products.manage');
        Route::get('products/{id}', [ProductController::class, 'show'])->middleware('can:products.view');
        Route::put('products/{id}', [ProductController::class, 'update'])->middleware('can:products.manage');
        Route::delete('products/{id}', [ProductController::class, 'destroy'])->middleware('can:products.manage');
        Route::get('products/{id}/image', [ProductController::class, 'image'])->middleware('can:products.view');
        Route::post('products/{id}/image', [ProductController::class, 'uploadImage'])->middleware('can:products.manage');
        Route::delete('products/{id}/image', [ProductController::class, 'removeImage'])->middleware('can:products.manage');

        Route::get('pricing/effective', [PriceListController::class, 'effective'])->middleware('can:products.view');
        Route::get('price-lists', [PriceListController::class, 'index'])->middleware('can:pricing.view');
        Route::post('price-lists', [PriceListController::class, 'store'])->middleware('can:pricing.manage');
        Route::get('price-lists/{id}', [PriceListController::class, 'show'])->middleware('can:pricing.view');
        Route::put('price-lists/{id}', [PriceListController::class, 'update'])->middleware('can:pricing.manage');
        Route::delete('price-lists/{id}', [PriceListController::class, 'destroy'])->middleware('can:pricing.manage');
        Route::get('price-lists/{id}/prices', [PriceListController::class, 'prices'])->middleware('can:pricing.view');
        Route::put('price-lists/{id}/prices', [PriceListController::class, 'savePrices'])->middleware('can:pricing.manage');

        // Stock
        Route::get('stock/on-hand', [StockController::class, 'onHand'])->middleware('can:inventory.view');
        Route::get('stock/reorder-suggestions', [StockController::class, 'reorderSuggestions'])->middleware('can:inventory.view');
        Route::get('stock/low-stock', [StockController::class, 'lowStock'])->middleware('can:inventory.view');
        Route::get('stock/expiry', [ExpiryController::class, 'index'])->middleware('can:inventory.view');
        Route::post('stock/expiry/assign', [ExpiryController::class, 'assign'])->middleware('can:inventory.adjust');
        Route::post('stock/expiry/write-off', [ExpiryController::class, 'writeOff'])->middleware('can:inventory.adjust');
        Route::get('stock/movements', [StockController::class, 'movements'])->middleware('can:inventory.view');
        Route::put('stock/reorder-levels', [StockController::class, 'setReorderLevel'])->middleware('can:inventory.adjust');

        Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware('can:inventory.view');
        Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware('can:inventory.adjust');
        Route::get('stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])->middleware('can:inventory.view');
        Route::post('stock-adjustments/{id}/approve', [StockAdjustmentController::class, 'approve'])->middleware('can:inventory.approve');
        Route::post('stock-adjustments/{id}/reject', [StockAdjustmentController::class, 'reject'])->middleware('can:inventory.approve');

        Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware('can:inventory.view');
        Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware('can:inventory.transfer');
        Route::get('stock-transfers/{id}', [StockTransferController::class, 'show'])->middleware('can:inventory.view');

        Route::get('stock-counts', [StockCountController::class, 'index'])->middleware('can:inventory.view');
        Route::post('stock-counts', [StockCountController::class, 'store'])->middleware('can:inventory.count');
        Route::get('stock-counts/{id}', [StockCountController::class, 'show'])->middleware('can:inventory.view');
        Route::put('stock-counts/{id}/lines', [StockCountController::class, 'saveLines'])->middleware('can:inventory.count');
        Route::post('stock-counts/{id}/submit', [StockCountController::class, 'submit'])->middleware('can:inventory.count');
        Route::post('stock-counts/{id}/approve', [StockCountController::class, 'approve'])->middleware('can:inventory.approve');
        Route::post('stock-counts/{id}/cancel', [StockCountController::class, 'cancel'])->middleware('can:inventory.count');

        // Purchasing
        Route::get('suppliers', [SupplierController::class, 'index'])->middleware('can:purchasing.view');
        Route::post('suppliers', [SupplierController::class, 'store'])->middleware('can:purchasing.manage');
        Route::put('suppliers/{id}', [SupplierController::class, 'update'])->middleware('can:purchasing.manage');

        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('can:purchasing.view');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('can:purchasing.manage');
        Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('can:purchasing.view');
        Route::put('purchase-orders/{id}', [PurchaseOrderController::class, 'update'])->middleware('can:purchasing.manage');
        Route::post('purchase-orders/{id}/issue', [PurchaseOrderController::class, 'issue'])->middleware('can:purchasing.manage');
        Route::post('purchase-orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('can:purchasing.manage');
        Route::get('purchase-orders/{id}/receipt-template', [PurchaseOrderController::class, 'receiptTemplate'])->middleware('can:goods-receipts.record');

        // Goods receiving
        Route::get('goods-receipts/discrepancies', [GoodsReceiptController::class, 'discrepancies'])->middleware('can:goods-receipts.view');
        Route::get('goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('can:goods-receipts.view');
        Route::post('goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('can:goods-receipts.record');
        Route::get('goods-receipts/{id}', [GoodsReceiptController::class, 'show'])->middleware('can:goods-receipts.view');
        Route::put('goods-receipts/{id}', [GoodsReceiptController::class, 'update'])->middleware('can:goods-receipts.record');
        Route::delete('goods-receipts/{id}', [GoodsReceiptController::class, 'destroy'])->middleware('can:goods-receipts.record');
        Route::post('goods-receipts/{id}/post', [GoodsReceiptController::class, 'post'])->middleware('can:goods-receipts.post');
        Route::post('goods-receipts/{id}/reverse', [GoodsReceiptController::class, 'reverse'])->middleware('can:goods-receipts.reverse');

        // Sales
        Route::get('pos/catalogue', [PosController::class, 'catalogue'])->middleware('can:sales.create');

        Route::get('import-batches', [\App\Modules\Organization\Http\Controllers\ImportBatchController::class, 'index'])->middleware('can:audit.view');
        Route::post('import-batches/{id}/undo', [\App\Modules\Organization\Http\Controllers\ImportBatchController::class, 'undo'])->middleware('can:sales.import');
        Route::post('imports/customers', [\App\Modules\Organization\Http\Controllers\ImportController::class, 'customers'])->middleware('can:customers.manage');
        Route::post('imports/products', [\App\Modules\Organization\Http\Controllers\ImportController::class, 'products'])->middleware('can:products.manage');
        Route::post('imports/orders', [\App\Modules\Organization\Http\Controllers\ImportController::class, 'orders'])->middleware('can:sales.import');
        Route::get('customers/aging', [\App\Modules\Sales\Http\Controllers\CustomerFinanceController::class, 'aging'])->middleware('can:customers.view');
        Route::get('customers/{id}/statement', [\App\Modules\Sales\Http\Controllers\CustomerFinanceController::class, 'statement'])->middleware('can:customers.view');
        Route::get('customers/insights', [\App\Modules\Sales\Http\Controllers\CustomerInsightsController::class, 'insights'])->middleware('can:customers.view');
        Route::post('customer-groups', [\App\Modules\Sales\Http\Controllers\CustomerInsightsController::class, 'storeGroup'])->middleware('can:customers.manage');
        Route::put('customer-groups/{id}', [\App\Modules\Sales\Http\Controllers\CustomerInsightsController::class, 'updateGroup'])->middleware('can:customers.manage');
        Route::delete('customer-groups/{id}', [\App\Modules\Sales\Http\Controllers\CustomerInsightsController::class, 'deleteGroup'])->middleware('can:customers.manage');
        Route::post('customer-groups/{id}/members', [\App\Modules\Sales\Http\Controllers\CustomerInsightsController::class, 'members'])->middleware('can:customers.manage');
        Route::put('customers/{id}/groups', [\App\Modules\Sales\Http\Controllers\CustomerInsightsController::class, 'setCustomerGroups'])->middleware('can:customers.manage');
        Route::get('customers', [CustomerController::class, 'index'])->middleware('can:customers.view');
        Route::post('customers', [CustomerController::class, 'store'])->middleware('can:customers.manage');
        Route::get('customers/{id}', [CustomerController::class, 'show'])->middleware('can:customers.view');
        Route::put('customers/{id}', [CustomerController::class, 'update'])->middleware('can:customers.manage');

        Route::get('sales/summary', [SaleController::class, 'summary'])->middleware('can:sales.view');
        Route::get('sales', [SaleController::class, 'index'])->middleware('can:sales.view');
        Route::post('sales', [SaleController::class, 'store'])->middleware('can:sales.create');
        Route::get('sales/{id}', [SaleController::class, 'show'])->middleware('can:sales.view');
        Route::post('sales/{id}/payments', [SaleController::class, 'pay'])->middleware('can:sales.create');
        Route::get('returns', [\App\Modules\Sales\Http\Controllers\SaleReturnController::class, 'index'])->middleware('can:sales.view');
        Route::get('returns/{id}', [\App\Modules\Sales\Http\Controllers\SaleReturnController::class, 'show'])->middleware('can:sales.view');
        Route::get('sales/{id}/returnable', [\App\Modules\Sales\Http\Controllers\SaleReturnController::class, 'returnable'])->middleware('can:sales.refund');
        Route::post('sales/{id}/returns', [\App\Modules\Sales\Http\Controllers\SaleReturnController::class, 'store'])->middleware('can:sales.refund');
        Route::post('sales/{id}/void', [SaleController::class, 'void'])->middleware('can:sales.refund');

        // Field sales: salesperson accounts, issuing stock to their van, and checking it back in
        Route::get('field/me', [TripController::class, 'mine']);
        Route::get('imprests', [ImprestController::class, 'index'])->middleware('can:imprest.view');
        Route::post('trips/{tripId}/imprest', [ImprestController::class, 'issue'])->middleware('can:imprest.manage');
        Route::post('imprests/{id}/void', [ImprestController::class, 'void'])->middleware('can:imprest.manage');

        // HR: employees, configurable pay items, payroll runs and staff charges
        Route::get('employees', [EmployeeController::class, 'index'])->middleware('can:employees.view');
        Route::post('employees/from-user', [EmployeeController::class, 'fromUser'])->middleware('can:employees.manage');
        Route::get('employees/{id}', [EmployeeController::class, 'show'])->middleware('can:employees.view');
        Route::post('employees', [EmployeeController::class, 'store'])->middleware('can:employees.manage');
        Route::put('employees/{id}', [EmployeeController::class, 'update'])->middleware('can:employees.manage');

        Route::get('pay-items', [PayItemController::class, 'index'])->middleware('can:employees.view');
        Route::post('pay-items', [PayItemController::class, 'store'])->middleware('can:payroll.manage');
        Route::put('pay-items/{id}', [PayItemController::class, 'update'])->middleware('can:payroll.manage');
        Route::delete('pay-items/{id}', [PayItemController::class, 'destroy'])->middleware('can:payroll.manage');

        Route::get('payroll-runs', [PayrollController::class, 'index'])->middleware('can:payroll.view');
        Route::get('payroll-runs/{id}', [PayrollController::class, 'show'])->middleware('can:payroll.view');
        Route::post('payroll-runs', [PayrollController::class, 'store'])->middleware('can:payroll.manage');
        Route::post('payroll-runs/{id}/recalculate', [PayrollController::class, 'recalculate'])->middleware('can:payroll.manage');
        Route::post('payroll-runs/{id}/approve', [PayrollController::class, 'approve'])->middleware('can:payroll.approve');
        Route::post('payroll-runs/{id}/pay', [PayrollController::class, 'pay'])->middleware('can:payroll.pay');
        Route::post('payroll-runs/{id}/void', [PayrollController::class, 'void'])->middleware('can:payroll.approve');
        Route::post('payslips/{id}/lines', [PayrollController::class, 'addLine'])->middleware('can:payroll.manage');
        Route::delete('payslip-lines/{id}', [PayrollController::class, 'removeLine'])->middleware('can:payroll.manage');

        Route::get('staff-charges', [StaffChargeController::class, 'index'])->middleware('can:staff-charges.view');
        Route::post('staff-charges', [StaffChargeController::class, 'store'])->middleware('can:staff-charges.manage');
        Route::post('staff-charges/{id}/approve', [StaffChargeController::class, 'approve'])->middleware('can:staff-charges.approve');
        Route::post('staff-charges/{id}/reject', [StaffChargeController::class, 'reject'])->middleware('can:staff-charges.approve');
        Route::post('staff-charges/{id}/waive', [StaffChargeController::class, 'waive'])->middleware('can:staff-charges.approve');

        Route::get('vehicles', [VehicleController::class, 'index'])->middleware('can:vehicles.view');
        Route::get('vehicles/{id}', [VehicleController::class, 'show'])->middleware('can:vehicles.view');
        Route::post('vehicles', [VehicleController::class, 'store'])->middleware('can:vehicles.manage');
        Route::put('vehicles/{id}', [VehicleController::class, 'update'])->middleware('can:vehicles.manage');
        Route::post('vehicles/{id}/documents', [VehicleController::class, 'addDocument'])->middleware('can:vehicles.manage');
        Route::put('vehicle-documents/{id}', [VehicleController::class, 'updateDocument'])->middleware('can:vehicles.manage');
        Route::delete('vehicle-documents/{id}', [VehicleController::class, 'deleteDocument'])->middleware('can:vehicles.manage');
        Route::post('vehicles/{id}/services', [VehicleController::class, 'addService'])->middleware('can:vehicles.manage');
        Route::delete('vehicle-services/{id}', [VehicleController::class, 'deleteService'])->middleware('can:vehicles.manage');

        Route::get('fuel-stations', [FuelController::class, 'stations'])->middleware('can:fuel.view');
        Route::post('fuel-stations', [FuelController::class, 'storeStation'])->middleware('can:fuel.manage');
        Route::put('fuel-stations/{id}', [FuelController::class, 'updateStation'])->middleware('can:fuel.manage');
        Route::post('fuel-stations/{id}/top-up', [FuelController::class, 'topUp'])->middleware('can:fuel.manage');
        Route::get('fuel-stations/{id}/entries', [FuelController::class, 'entries'])->middleware('can:fuel.view');
        Route::get('fuel-vouchers', [FuelController::class, 'vouchers'])->middleware('can:fuel.view');
        Route::post('fuel-vouchers', [FuelController::class, 'issue'])->middleware('can:fuel.manage');
        Route::post('fuel-vouchers/{id}/redeem', [FuelController::class, 'redeem'])->middleware('can:fuel.manage');
        Route::post('fuel-vouchers/{id}/cancel', [FuelController::class, 'cancel'])->middleware('can:fuel.manage');

        Route::get('reps', [RepController::class, 'index'])->middleware('can:trips.view');
        Route::post('reps', [RepController::class, 'store'])->middleware(['can:trips.manage', 'can:users.create']);
        Route::put('reps/{id}', [RepController::class, 'update'])->middleware('can:trips.manage');
        Route::get('trips', [TripController::class, 'index'])->middleware('can:trips.view');
        Route::post('trips/issue', [TripController::class, 'issue'])->middleware('can:trips.manage');
        Route::get('trips/{id}', [TripController::class, 'show'])->middleware('can:trips.view');
        Route::post('trips/{id}/check-in', [TripController::class, 'checkIn'])->middleware('can:trips.manage');

        // Offline: a salesperson's phone downloads the day's data, then sends back what it queued
        Route::get('field/bootstrap', [FieldSyncController::class, 'bootstrap'])->middleware('can:sales.create');
        Route::post('field/sync', [FieldSyncController::class, 'sync'])->middleware(['can:sales.create', 'throttle:120,1']);
        Route::post('field/ping', [FieldSyncController::class, 'ping'])->middleware(['can:sales.create', 'throttle:120,1']);
        Route::get('sync-issues', [SyncIssueController::class, 'index'])->middleware('can:trips.view');
        Route::post('sync-issues/retry-all', [SyncIssueController::class, 'retryAll'])->middleware('can:trips.manage');
        Route::post('sync-issues/{id}/retry', [SyncIssueController::class, 'retry'])->middleware('can:trips.manage');
        Route::post('sync-issues/{id}/dismiss', [SyncIssueController::class, 'dismiss'])->middleware('can:trips.manage');

        // Card terminals
        Route::get('pos-terminals', [PosTerminalController::class, 'index'])->middleware('can:terminals.view');
        Route::get('pos-terminals/available', [PosTerminalController::class, 'available'])->middleware('can:sales.create');
        Route::get('pos-terminals/summary', [PosTerminalController::class, 'summary'])->middleware('can:terminals.view');
        Route::post('pos-terminals', [PosTerminalController::class, 'store'])->middleware('can:terminals.manage');
        Route::get('pos-terminals/{id}', [PosTerminalController::class, 'show'])->middleware('can:terminals.view');
        Route::put('pos-terminals/{id}', [PosTerminalController::class, 'update'])->middleware('can:terminals.manage');
        Route::get('pos-terminals/{id}/transactions', [PosTerminalController::class, 'transactions'])->middleware('can:terminals.view');

        // Returnable containers
        Route::get('container-types', [ContainerTypeController::class, 'index'])->middleware('can:products.view');
        Route::post('container-types', [ContainerTypeController::class, 'store'])->middleware('can:containers.manage');
        Route::put('container-types/{id}', [ContainerTypeController::class, 'update'])->middleware('can:containers.manage');
        Route::get('containers/summary', [ContainerController::class, 'summary'])->middleware('can:containers.view');
        Route::get('containers/holdings', [ContainerController::class, 'holdings'])->middleware('can:containers.view');
        Route::get('containers/movements', [ContainerController::class, 'movements'])->middleware('can:containers.view');
        Route::post('containers/receive', [ContainerController::class, 'receive'])->middleware('can:containers.manage');
        Route::post('containers/write-off', [ContainerController::class, 'writeOff'])->middleware('can:containers.manage');
        Route::post('containers/adjust', [ContainerController::class, 'adjust'])->middleware('can:containers.manage');
        Route::post('containers/supplier', [ContainerController::class, 'supplier'])->middleware('can:containers.manage');

        // Expenses
        Route::get('expense-categories', [ExpenseCategoryController::class, 'index'])->middleware('can:expenses.create');
        Route::post('expense-categories', [ExpenseCategoryController::class, 'store'])->middleware('can:expenses.manage');
        Route::put('expense-categories/{id}', [ExpenseCategoryController::class, 'update'])->middleware('can:expenses.manage');
        Route::get('expenses', [ExpenseController::class, 'index'])->middleware('can:expenses.view');
        Route::post('expenses', [ExpenseController::class, 'store'])->middleware('can:expenses.create');
        Route::post('expenses/{id}/void', [ExpenseController::class, 'void'])->middleware('can:expenses.manage');

        // Accounting (store-level users only; enforced in the controllers)
        Route::get('accounts', [AccountController::class, 'index'])->middleware('can:accounts.view');
        Route::post('accounts', [AccountController::class, 'store'])->middleware('can:accounts.manage');
        Route::put('accounts/{id}', [AccountController::class, 'update'])->middleware('can:accounts.manage');

        Route::get('fiscal-periods', [FiscalPeriodController::class, 'index'])->middleware('can:accounts.view');
        Route::post('fiscal-periods/{id}/close', [FiscalPeriodController::class, 'close'])->middleware('can:periods.close');
        Route::post('fiscal-periods/{id}/reopen', [FiscalPeriodController::class, 'reopen'])->middleware('can:periods.close');

        Route::get('bank-statements/accounts', [BankReconciliationController::class, 'accounts'])->middleware('can:accounts.view');
        Route::get('bank-statements', [BankReconciliationController::class, 'index'])->middleware('can:accounts.view');
        Route::post('bank-statements', [BankReconciliationController::class, 'store'])->middleware('can:accounts.manage');
        Route::get('bank-statements/{id}', [BankReconciliationController::class, 'show'])->middleware('can:accounts.view');
        Route::delete('bank-statements/{id}', [BankReconciliationController::class, 'destroy'])->middleware('can:accounts.manage');
        Route::post('bank-statements/{id}/auto-match', [BankReconciliationController::class, 'auto'])->middleware('can:accounts.manage');
        Route::post('bank-statements/{id}/lines/{lineId}/match', [BankReconciliationController::class, 'match'])->middleware('can:accounts.manage');
        Route::post('bank-statements/{id}/lines/{lineId}/unmatch', [BankReconciliationController::class, 'unmatch'])->middleware('can:accounts.manage');
        Route::post('bank-statements/{id}/lines/{lineId}/ignore', [BankReconciliationController::class, 'ignore'])->middleware('can:accounts.manage');
        Route::post('bank-statements/{id}/lines/{lineId}/book', [BankReconciliationController::class, 'book'])->middleware('can:journals.create');
        Route::get('journals', [JournalController::class, 'index'])->middleware('can:accounts.view');
        Route::post('journals', [JournalController::class, 'store'])->middleware('can:journals.create');
        Route::get('journals/{id}', [JournalController::class, 'show'])->middleware('can:accounts.view');
        Route::delete('journals/{id}', [JournalController::class, 'destroy'])->middleware('can:journals.create');
        Route::post('journals/{id}/post', [JournalController::class, 'post'])->middleware('can:journals.post');
        Route::post('journals/{id}/reverse', [JournalController::class, 'reverse'])->middleware('can:journals.reverse');

        Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->middleware('can:reports.view');
        Route::get('reports/general-ledger', [ReportController::class, 'generalLedger'])->middleware('can:reports.view');
        Route::get('reports/income-statement', [ReportController::class, 'incomeStatement'])->middleware('can:reports.view');
        Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->middleware('can:reports.view');
        Route::get('reports/inventory-reconciliation', [ReportController::class, 'inventoryReconciliation'])->middleware('can:reports.view');

        // Rebates (store-level users only; enforced in the controllers)
        Route::get('rebate-agreements/templates', [RebateAgreementController::class, 'templates'])->middleware('can:rebates.configure');
        Route::post('rebate-agreements/from-template', [RebateAgreementController::class, 'fromTemplate'])->middleware('can:rebates.configure');
        Route::get('rebate-agreements', [RebateAgreementController::class, 'index'])->middleware('can:rebates.view');
        Route::post('rebate-agreements', [RebateAgreementController::class, 'store'])->middleware('can:rebates.configure');
        Route::get('rebate-agreements/{id}', [RebateAgreementController::class, 'show'])->middleware('can:rebates.view');
        Route::put('rebate-agreements/{id}', [RebateAgreementController::class, 'update'])->middleware('can:rebates.configure');

        Route::get('rebates/progress', [RebateCalculationController::class, 'progress'])->middleware('can:rebates.view');
        Route::get('rebate-calculations', [RebateCalculationController::class, 'index'])->middleware('can:rebates.view');
        Route::post('rebate-calculations', [RebateCalculationController::class, 'store'])->middleware('can:rebates.calculate');
        Route::get('rebate-calculations/{id}', [RebateCalculationController::class, 'show'])->middleware('can:rebates.view');
        Route::get('rebate-calculations/{id}/sources', [RebateCalculationController::class, 'sources'])->middleware('can:rebates.view');
        Route::post('rebate-calculations/{id}/approve', [RebateCalculationController::class, 'approve'])->middleware('can:rebates.approve');
        Route::post('rebate-calculations/{id}/void', [RebateCalculationController::class, 'void'])->middleware('can:rebates.calculate');
        Route::post('rebate-calculations/{id}/settle', [RebateCalculationController::class, 'settle'])->middleware('can:rebates.settle');
    });
});
