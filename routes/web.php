<?php

use App\Http\Controllers\AssessmentAnswerController;
use App\Http\Controllers\AssessmentCatalogController;
use App\Http\Controllers\AssessmentController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\AssetRegisterController;
use App\Http\Controllers\Auth\EntraAuthController;
use App\Http\Controllers\BusinessProcessController;
use App\Http\Controllers\BusinessProcessRegisterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DependencyController;
use App\Http\Controllers\DependencyRegisterController;
use App\Http\Controllers\EvidenceController;
use App\Http\Controllers\EvidenceRegisterController;
use App\Http\Controllers\FindingController;
use App\Http\Controllers\FindingRegisterController;
use App\Http\Controllers\FindingRiskTargetController;
use App\Http\Controllers\IsmsProjectController;
use App\Http\Controllers\MeasureController;
use App\Http\Controllers\MeasureRegisterController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\ProjectRiskContextController;
use App\Http\Controllers\ProjectThreatController;
use App\Http\Controllers\ProtectionNeedController;
use App\Http\Controllers\ProtectionNeedDowngradeController;
use App\Http\Controllers\ProtectionNeedRegisterController;
use App\Http\Controllers\RegisterImportController;
use App\Http\Controllers\RiskAnalysisController;
use App\Http\Controllers\RiskDecisionController;
use App\Http\Controllers\RiskMethodologyController;
use App\Http\Controllers\RiskRegisterController;
use App\Http\Controllers\RiskTreatmentController;
use App\Http\Controllers\RiskTriggerController;
use App\Http\Controllers\ThreatCatalogController;
use App\Http\Controllers\ThreatReviewController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [EntraAuthController::class, 'login'])->name('login');
    Route::get('/auth/microsoft/redirect', [EntraAuthController::class, 'redirect'])->middleware('throttle:entra-auth')->name('auth.microsoft.redirect');
    Route::get('/auth/microsoft/callback', [EntraAuthController::class, 'callback'])->middleware('throttle:entra-auth')->name('auth.microsoft.callback');
});

Route::middleware(['auth', 'active-user'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/assessment-catalogs', [AssessmentCatalogController::class, 'index'])->name('assessment-catalogs.index');
    Route::get('/assessment-catalogs/template', [AssessmentCatalogController::class, 'template'])->name('assessment-catalogs.template');
    Route::post('/assessment-catalogs/preview', [AssessmentCatalogController::class, 'preview'])->middleware('throttle:10,1')->name('assessment-catalogs.preview');
    Route::get('/assessment-catalogs/previews/{batch}', [AssessmentCatalogController::class, 'show'])->whereUuid('batch')->name('assessment-catalogs.preview.show');
    Route::post('/assessment-catalogs/previews/{batch}/confirm', [AssessmentCatalogController::class, 'confirm'])->whereUuid('batch')->name('assessment-catalogs.confirm');

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

    Route::get('/organizations/{organization}/projects/{project}/evidence', EvidenceRegisterController::class)->name('evidence.index');
    Route::get('/organizations/{organization}/projects/{project}/findings', FindingRegisterController::class)->name('findings.index');
    Route::get('/organizations/{organization}/projects/{project}/measures', MeasureRegisterController::class)->name('measures.index');
    Route::get('/organizations/{organization}/projects/{project}/processes', BusinessProcessRegisterController::class)->name('processes.index');
    Route::get('/organizations/{organization}/projects/{project}/assets', AssetRegisterController::class)->name('assets.index');
    Route::get('/organizations/{organization}/projects/{project}/dependencies', DependencyRegisterController::class)->name('dependencies.index');
    Route::get('/organizations/{organization}/projects/{project}/protection-needs', ProtectionNeedRegisterController::class)->name('protection-needs.index');
    Route::get('/organizations/{organization}/projects/{project}/risks', RiskRegisterController::class)->name('risks.index');
    Route::get('/risk-methodologies', [RiskMethodologyController::class, 'index'])->name('risk-methodologies.index');
    Route::get('/threat-catalogs', [ThreatCatalogController::class, 'index'])->name('threat-catalogs.index');

    Route::put('/organizations/{organization}/projects/{project}/protection-needs/targets/{targetType}/{target}', [ProtectionNeedController::class, 'rate'])->name('protection-needs.rate');
    Route::post('/organizations/{organization}/projects/{project}/protection-needs/targets/{targetType}/{target}/approve', [ProtectionNeedController::class, 'approve'])->name('protection-needs.approve');
    Route::post('/organizations/{organization}/projects/{project}/protection-needs/{need}/downgrades', [ProtectionNeedDowngradeController::class, 'store'])->name('protection-needs.downgrades.store');
    Route::post('/organizations/{organization}/projects/{project}/downgrades/{downgrade}/decision', [ProtectionNeedDowngradeController::class, 'decide'])->name('protection-needs.downgrades.decide');

    Route::post('/organizations/{organization}/projects/{project}/risks/context', [ProjectRiskContextController::class, 'initialize'])->name('risks.context.initialize');
    Route::post('/organizations/{organization}/projects/{project}/risks/findings/{finding}/targets/{targetType}/{target}', [FindingRiskTargetController::class, 'store'])->name('risks.findings.targets.store');
    Route::post('/organizations/{organization}/projects/{project}/risks/context/{context}/migrate', [ProjectRiskContextController::class, 'migrate'])->name('risks.context.migrate');
    Route::post('/organizations/{organization}/projects/{project}/risks/targets/{targetType}/{target}/triggers', [RiskTriggerController::class, 'store'])->name('risks.triggers.store');
    Route::post('/organizations/{organization}/projects/{project}/risks/triggers/{trigger}/dismiss', [RiskTriggerController::class, 'dismiss'])->name('risks.triggers.dismiss');
    Route::post('/organizations/{organization}/projects/{project}/risks/threats', [ProjectThreatController::class, 'store'])->name('risks.threats.store');
    Route::put('/organizations/{organization}/projects/{project}/risks/threats/{threat}', [ProjectThreatController::class, 'update'])->name('risks.threats.update');
    Route::patch('/organizations/{organization}/projects/{project}/risks/reviews/{review}', [ThreatReviewController::class, 'update'])->name('risks.reviews.update');
    Route::post('/organizations/{organization}/projects/{project}/risks/targets/{targetType}/{target}/analyses', [RiskAnalysisController::class, 'store'])->name('risks.analyses.store');
    Route::post('/organizations/{organization}/projects/{project}/risks/analyses/{analysis}/cycles', [RiskAnalysisController::class, 'nextCycle'])->name('risks.cycles.next');
    Route::post('/organizations/{organization}/projects/{project}/risk-cycles/{cycle}/assessments', [RiskAnalysisController::class, 'assess'])->name('risks.assessments.store');
    Route::post('/organizations/{organization}/projects/{project}/risk-cycles/{cycle}/treatment', [RiskTreatmentController::class, 'select'])->name('risks.treatment.select');
    Route::post('/organizations/{organization}/projects/{project}/risk-cycles/{cycle}/links/{kind}/{resource}', [RiskTreatmentController::class, 'link'])->name('risks.links.store');
    Route::delete('/organizations/{organization}/projects/{project}/risk-cycles/{cycle}/links/{kind}/{resource}', [RiskTreatmentController::class, 'unlink'])->name('risks.links.destroy');
    Route::post('/organizations/{organization}/projects/{project}/risk-cycles/{cycle}/decisions', [RiskDecisionController::class, 'store'])->name('risks.decisions.store');
    Route::post('/organizations/{organization}/projects/{project}/risk-cycles/{cycle}/complete', [RiskAnalysisController::class, 'complete'])->name('risks.cycles.complete');

    Route::post('/risk-methodologies/{version}/clone', [RiskMethodologyController::class, 'clone'])->name('risk-methodologies.clone');
    Route::put('/risk-methodologies/{version}/draft', [RiskMethodologyController::class, 'update'])->name('risk-methodologies.draft.update');
    Route::post('/risk-methodologies/{version}/publish', [RiskMethodologyController::class, 'publish'])->name('risk-methodologies.publish');
    Route::post('/threat-catalogs/{version}/clone', [ThreatCatalogController::class, 'clone'])->name('threat-catalogs.clone');
    Route::put('/threat-catalogs/{version}/draft', [ThreatCatalogController::class, 'update'])->name('threat-catalogs.draft.update');
    Route::post('/threat-catalogs/{version}/publish', [ThreatCatalogController::class, 'publish'])->name('threat-catalogs.publish');

    Route::post('/organizations/{organization}/projects/{project}/processes', [BusinessProcessController::class, 'store'])->name('processes.store');
    Route::put('/organizations/{organization}/projects/{project}/processes/{process}', [BusinessProcessController::class, 'update'])->name('processes.update');
    Route::patch('/organizations/{organization}/projects/{project}/processes/{process}/status', [BusinessProcessController::class, 'status'])->name('processes.status');
    Route::post('/organizations/{organization}/projects/{project}/assets', [AssetController::class, 'store'])->name('assets.store');
    Route::put('/organizations/{organization}/projects/{project}/assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
    Route::patch('/organizations/{organization}/projects/{project}/assets/{asset}/status', [AssetController::class, 'status'])->name('assets.status');
    Route::post('/organizations/{organization}/projects/{project}/dependencies', [DependencyController::class, 'store'])->name('dependencies.store');
    Route::put('/organizations/{organization}/projects/{project}/dependencies/{dependency}', [DependencyController::class, 'update'])->name('dependencies.update');
    Route::patch('/organizations/{organization}/projects/{project}/dependencies/{dependency}/status', [DependencyController::class, 'status'])->name('dependencies.status');
    Route::get('/organizations/{organization}/projects/{project}/dependencies/{type}/{key}/graph', [DependencyController::class, 'graph'])->name('dependencies.graph');
    Route::post('/organizations/{organization}/projects/{project}/imports/{kind}/preview', [RegisterImportController::class, 'preview'])->name('register-imports.preview');
    Route::get('/organizations/{organization}/projects/{project}/imports/{batch}', [RegisterImportController::class, 'show'])->name('register-imports.show');
    Route::post('/organizations/{organization}/projects/{project}/imports/{batch}/confirm', [RegisterImportController::class, 'confirm'])->name('register-imports.confirm');

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
