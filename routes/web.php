<?php

use App\Http\Controllers\AssessmentAnswerController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\Auth\EntraAuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\IsmsProjectController;
use App\Http\Controllers\MeasureController;
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
    Route::get('/organizations/create', [OrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->name('organizations.show');
    Route::get('/organizations/{organization}/edit', [OrganizationController::class, 'edit'])->name('organizations.edit');
    Route::put('/organizations/{organization}', [OrganizationController::class, 'update'])->name('organizations.update');
    Route::patch('/organizations/{organization}/deactivate', [OrganizationController::class, 'deactivate'])->name('organizations.deactivate');

    Route::get('/organizations/{organization}/projects/create', [IsmsProjectController::class, 'create'])->name('projects.create');
    Route::post('/organizations/{organization}/projects', [IsmsProjectController::class, 'store'])->name('projects.store');
    Route::get('/organizations/{organization}/projects/{project}/edit', [IsmsProjectController::class, 'edit'])->name('projects.edit');
    Route::put('/organizations/{organization}/projects/{project}', [IsmsProjectController::class, 'update'])->name('projects.update');

    Route::post('/organizations/{organization}/projects/{project}/assessment', [AssessmentController::class, 'start'])->name('assessments.start');
    Route::get('/organizations/{organization}/projects/{project}/assessment', [AssessmentController::class, 'show'])->name('assessments.show');
    Route::put('/organizations/{organization}/projects/{project}/assessment/questions/{question}', [AssessmentAnswerController::class, 'update'])->name('assessments.answers.update');

    Route::post('/organizations/{organization}/projects/{project}/assessment/questions/{question}/evidence', [EvidenceController::class, 'store']);
    Route::post('/organizations/{organization}/projects/{project}/evidence/{evidence}/questions/{question}', [EvidenceController::class, 'linkQuestion']);
    Route::post('/organizations/{organization}/projects/{project}/findings/{finding}/evidence/{evidence}', [EvidenceController::class, 'linkFinding']);
    Route::patch('/organizations/{organization}/projects/{project}/evidence/{evidence}/review', [EvidenceController::class, 'review']);
    Route::get('/organizations/{organization}/projects/{project}/evidence/{evidence}/download', [EvidenceController::class, 'download']);

    Route::post('/organizations/{organization}/projects/{project}/assessment/questions/{question}/findings', [FindingController::class, 'store']);
    Route::put('/organizations/{organization}/projects/{project}/findings/{finding}', [FindingController::class, 'update']);
    Route::patch('/organizations/{organization}/projects/{project}/findings/{finding}/decision', [FindingController::class, 'decide']);
    Route::patch('/organizations/{organization}/projects/{project}/findings/{finding}/close', [FindingController::class, 'close']);

    Route::post('/organizations/{organization}/projects/{project}/findings/{finding}/measures', [MeasureController::class, 'store']);
    Route::put('/organizations/{organization}/projects/{project}/measures/{measure}', [MeasureController::class, 'update']);
    Route::patch('/organizations/{organization}/projects/{project}/measures/{measure}/status', [MeasureController::class, 'transition']);

    Route::post('/logout', [EntraAuthController::class, 'logout'])->name('logout');
});
