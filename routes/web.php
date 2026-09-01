<?php

use App\Http\Controllers\Auth\EntraAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\IsmsProjectController;
use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [EntraAuthController::class, 'login'])->name('login');
    Route::get('/auth/microsoft/redirect', [EntraAuthController::class, 'redirect'])->middleware('throttle:entra-auth')->name('auth.microsoft.redirect');
    Route::get('/auth/microsoft/callback', [EntraAuthController::class, 'callback'])->middleware('throttle:entra-auth')->name('auth.microsoft.callback');
});

Route::middleware(['auth', 'active-user'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::patch('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->name('organizations.deactivate');

    Route::post('/organizations/{organization}/projects', [IsmsProjectController::class, 'store'])->name('projects.store');
    Route::put('/organizations/{organization}/projects/{project}', [IsmsProjectController::class, 'update'])->name('projects.update');

    Route::post('/logout', [EntraAuthController::class, 'logout'])->name('logout');
});
