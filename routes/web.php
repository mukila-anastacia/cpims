<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

// JSON API used by the React interface. It uses the normal session and CSRF token (design decision DD-06).
Route::prefix('api')->group(function () {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware(['auth', 'active', 'throttle:240,1'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('me/password', [AccountController::class, 'changePassword'])->middleware('throttle:5,1,password');

        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read', [NotificationController::class, 'readAll']);

        Route::get('lookups', [RequestController::class, 'lookups']);
        Route::get('budget/remaining', [RequestController::class, 'budget']);
        Route::get('requests', [RequestController::class, 'index']);
        Route::get('requests/{id}', [RequestController::class, 'show'])->whereNumber('id');
        Route::get('requests/{id}/checks', [RequestController::class, 'checks'])->whereNumber('id');

        Route::middleware('role:Requester')->group(function () {
            Route::post('requests', [RequestController::class, 'store']);
            Route::put('requests/{id}', [RequestController::class, 'update'])->whereNumber('id');
            Route::post('requests/{id}/submit', [RequestController::class, 'submit'])->whereNumber('id');
            Route::post('requests/{id}/cancel', [RequestController::class, 'cancel'])->whereNumber('id');
        });

        Route::middleware('role:Branch Manager,Procurement Administrator,Finance Officer,Head Office Manager')->group(function () {
            Route::get('approvals', [ApprovalController::class, 'index']);
            Route::post('requests/{id}/decision', [ApprovalController::class, 'decide'])->whereNumber('id');

            Route::get('reports/summary', [ReportController::class, 'summary']);
            Route::get('reports/export', [ReportController::class, 'export'])->middleware('throttle:20,1,export');
        });

        // Vendors, items, quotations and invoices: Procurement manages, Finance and Head Office can view
        Route::middleware('role:Procurement Administrator,Finance Officer,Head Office Manager')->group(function () {
            Route::get('vendors', [VendorController::class, 'index']);
            Route::get('items', [ItemController::class, 'index']);
            Route::get('quotation-queue', [QuotationController::class, 'queue']);
            Route::get('requests/{id}/quotations', [QuotationController::class, 'show'])->whereNumber('id');
            Route::get('invoices', [InvoiceController::class, 'index']);
            Route::get('invoices/{id}', [InvoiceController::class, 'show'])->whereNumber('id');
        });

        // Orders: viewed by branch staff for their own branch and by head office roles
        Route::middleware('role:Requester,Branch Manager,Procurement Administrator,Finance Officer,Head Office Manager')->group(function () {
            Route::get('orders', [OrderController::class, 'index']);
            Route::get('orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
        });
        Route::post('orders/{id}/receipts', [OrderController::class, 'receive'])->whereNumber('id')->middleware('role:Requester,Procurement Administrator');

        Route::get('budgets', [BudgetController::class, 'index'])->middleware('role:Finance Officer,Head Office Manager');
        Route::get('settings', [SettingsController::class, 'show'])->middleware('role:System Administrator,Head Office Manager');
        Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('role:System Administrator,Head Office Manager');

        Route::middleware('role:Finance Officer')->group(function () {
            Route::get('invoice-orders', [InvoiceController::class, 'orders']);
            Route::post('invoices', [InvoiceController::class, 'store']);
            Route::post('budgets', [BudgetController::class, 'store']);
            Route::put('budgets/{id}', [BudgetController::class, 'update'])->whereNumber('id');
        });

        Route::middleware('role:System Administrator')->group(function () {
            Route::put('settings', [SettingsController::class, 'update']);
            Route::put('approval-rules', [SettingsController::class, 'updateApproval']);
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{id}', [UserController::class, 'update'])->whereNumber('id');
        });

        Route::middleware('role:Procurement Administrator')->group(function () {
            Route::post('vendors', [VendorController::class, 'store']);
            Route::put('vendors/{id}', [VendorController::class, 'update'])->whereNumber('id');
            Route::post('items', [ItemController::class, 'store']);
            Route::put('items/{id}', [ItemController::class, 'update'])->whereNumber('id');

            Route::post('requests/{id}/quotations', [QuotationController::class, 'store'])->whereNumber('id');
            Route::put('requests/{id}/quotations/{qid}', [QuotationController::class, 'update'])->whereNumber('id')->whereNumber('qid');
            Route::delete('requests/{id}/quotations/{qid}', [QuotationController::class, 'destroy'])->whereNumber('id')->whereNumber('qid');
            Route::post('requests/{id}/quotations/{qid}/select', [QuotationController::class, 'select'])->whereNumber('id')->whereNumber('qid');
            Route::post('requests/{id}/order', [OrderController::class, 'store'])->whereNumber('id');
            Route::post('invoices/{id}/resolve', [InvoiceController::class, 'resolve'])->whereNumber('id');
        });
    });
});

// Everything else is the React application
Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');