<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

// JSON API used by the React interface. It uses the normal session and CSRF token (design decision DD-06).
Route::prefix('api')->group(function () {
    Route::get('csrf', fn () => response()->json(['ok' => true]));
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::middleware('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('admin/ping', fn () => response()->json(['ok' => true]))->middleware('role:System Administrator');

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
        });

        // Vendors, items and quotations: Procurement manages, Finance and Head Office can view (VQM-01 to 03)
        Route::middleware('role:Procurement Administrator,Finance Officer,Head Office Manager')->group(function () {
            Route::get('vendors', [VendorController::class, 'index']);
            Route::get('items', [ItemController::class, 'index']);
            Route::get('quotation-queue', [QuotationController::class, 'queue']);
            Route::get('requests/{id}/quotations', [QuotationController::class, 'show'])->whereNumber('id');
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
        });
    });
});

// Everything else is the React application
Route::view('/{path?}', 'app')->where('path', '^(?!api).*$');