<?php

use HalilCosdu\Slower\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Static routes are declared before the {log} wildcard so they can never be
// shadowed by it; {log} is additionally constrained to numeric ids.
Route::get('/', [DashboardController::class, 'index'])->name('index');
Route::post('/analyze-pending', [DashboardController::class, 'analyzePending'])->name('analyze-pending');
Route::delete('/clean', [DashboardController::class, 'clean'])->name('clean');

Route::get('/{log}', [DashboardController::class, 'show'])->whereNumber('log')->name('show');
Route::post('/{log}/analyze', [DashboardController::class, 'analyze'])->whereNumber('log')->name('analyze');
Route::delete('/{log}', [DashboardController::class, 'destroy'])->whereNumber('log')->name('destroy');
