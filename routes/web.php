<?php

use App\Http\Controllers\Auth\EntraAuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [EntraAuthController::class, 'login'])->name('login');
    Route::get('/auth/microsoft/redirect', [EntraAuthController::class, 'redirect'])->middleware('throttle:entra-auth')->name('auth.microsoft.redirect');
    Route::get('/auth/microsoft/callback', [EntraAuthController::class, 'callback'])->middleware('throttle:entra-auth')->name('auth.microsoft.callback');
});

Route::middleware(['auth', 'active-user'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/logout', [EntraAuthController::class, 'logout'])->name('logout');
});
