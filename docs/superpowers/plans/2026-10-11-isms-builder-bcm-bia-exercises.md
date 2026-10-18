# Slice 7 — BIA, Notfallplanung und Übungen Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Projektweite BIA, nachvollziehbare Wiederanlaufziele, versionierte Notfallpläne und Übungen mit Feststellungsintegration liefern.

**Architecture:** Laravel-Modul unter `Services/Bcm` mit relationalen, projektgebundenen Revisionen und serverseitigen Freigaben. Drei aufeinander aufbauende Lieferabschnitte: BIA, Planung, Übungen. Bestehende Graph-/Evidence-/Finding-Services werden gezielt integriert, nicht kopiert.

**Tech Stack:** Bestehendes Laravel/PHP/PostgreSQL, Vue/TypeScript/Inertia und Docker Compose; keine neue Abhängigkeit.

**Spec:** `docs/superpowers/specs/2026-10-11-isms-builder-bcm-bia-exercises-design.md`

## Global Constraints

- Modul in jedem Projekt; `Aufbau-BCMS` sperrt keinen Zugang.
- Standardzeiten in Minuten: `[60, 240, 480, 1440, 4320, 10080]`; 2–12 strikt steigende verschiedene positive Ganzzahlen bis 525600.
- Bereiche `financial`, `operational`, `legal`, `reputation`; Stufen gering=1, mittel=2, hoch=3, kritisch=4; keine frei konfigurierbaren Skalen.
- RTO/MTPD positive Minuten; RTO < MTPD. RPO >= 0 oder begründet nicht anwendbar. Keine automatische Zielberechnung/Interpolation.
- Höchstens ein Entwurf je stabiler Identität; freigegebene Revisionen/abgeschlossene Ergebnisse unveränderlich; keine Löschung historischer Quellen.
- Aktive interne Admins/Consultants; Schreiben nur aktive Kundenorganisation und Draft/Active-Projekt. Frische Actor-/Projektprüfung in jedem Schreibservice.
- Projektzugehörigkeit auch per zusammengesetztem FK sichern. Fremde verschachtelte Ressourcen: 404. Veraltete lock_version: 409; Formulareingaben bleiben erhalten.
- Projektlock vor Kind-/Quelllocks; Audit und Änderungen in einer Transaktion. Audit ausschließlich IDs, Status und Feldnamen, keine Kontakte/Freitexte.
- Private Evidence-Downloads wiederverwenden; verknüpfte Nachweise vor Freigabe/Abschluss frisch auf Verifikation prüfen.
- Selbstfreigabe intern erlaubt; Name/E-Mail/Telefon eines Kontakts verleiht keine Rechte.
- Kein BCM-CSV, keine Alarmierung, kein Rendering, keine KI; keine Änderung bestehender Risikoakzeptanzen oder pauschaler Projektabschlussregeln.
- Gezieltes RED/GREEN je Task, keine volle Suite nach jedem Kleinschritt. Gemeinsamer vollständiger Abschlussgate vor PR.
- Feature-Branch `feat/slice-07-bcm-bia-exercises` basiert auf Merge `277f2d5`; niemals direkt auf main implementieren.

## Review Focus

1. Kürzerer unterstützender und längerer kritischer Pfad zum gleichen Asset: Task 3 muss beide korrekt unterscheiden.
2. Identische Werte, aber neue Quellrevision: Task 4 muss alte Konfliktakzeptanz ungültig machen.
3. Zwischen Formularladen und Freigabe deaktivierter Actor/entzogene Evidence-Verifikation: Tasks 2/5/7 müssen frisch prüfen.
4. Übungsfeststellung ohne Assessment: Task 6 muss Evidence-, Maßnahmen- und Risikoabläufe ohne Null-Dereferenz weiterführen.
5. Historische Quelle nachträglich verändert und Browser-409: Tasks 7/10 bewahren Ergebnisse/Eingaben statt falscher Bereitschaft.

## Gemeinsame Verträge und Ausführung

Alle mutierenden Service-Signaturen enden mit `User $actor`; Updates/Freigaben erhalten außerdem `int $expectedLockVersion`. `lock_version` startet bei 1 und steigt bei tatsächlicher Mutation. UUIDs, Zeitwerte und enum-Werte werden serverseitig validiert. Zeitstempel sind `timestampTz` mit offset-erhaltender Persistenz, immutable casts.

`BcmProjectAccess::view(IsmsProject, User): bool`, `lockedWritable(IsmsProject, User): IsmsProject` und `assertVersion(Model, int): void` leben in `app/Services/Bcm/BcmProjectAccess.php`. Frische Actorprüfung entspricht `ProjectRiskPolicy`, kein bloßes `loadMissing`. HTTP-Policy: `app/Policies/ProjectBcmPolicy.php`. Jede Serviceaktion prüft selbst, nicht nur Controller.

Modelle aus Tasks 1/2/5/7 leben unter `app/Models`; Factories unter `database/factories`; Enums unter `app/Enums`. Revisionen halten `project_id`, Herkunfts-ID, fortlaufende `version`, `lock_version`, `status`, `created_by`, `approved_by/at`. Ein gemeinsamer `BcmRevisionStatus` hat `draft`, `approved`; Aktualität separat.

Tests benutzen `tests/Feature/Bcm/InteractsWithBcm.php` mit `bcmProject(bool $withBcms=false): array{IsmsProject, User}`, `completeBiaData(BiaConfigurationVersion): array` und `approvedPlan(IsmsProject, User): ContinuityPlanRevision` (letztere ab Task 5). Fixture-Helfer dürfen erst die durch Tests bestätigten Services verwenden.

Testpräfix im lokalen Setup: `/Applications/Docker.app/Contents/Resources/bin/docker compose run --rm app php artisan test --compact --no-ansi`. Docker ist gegebenenfalls eskalationspflichtig. Nie gleichzeitig zwei PostgreSQL-Testsuiten ausführen. Jede Task endet mit fokussiertem GREEN, Diffprüfung, Commit ausschließlich eigener Pfade und Review gemäß gewählter Methode.

### Task 1: BIA-Konfiguration und Zugriffsgrundlage

**Files:** Create `app/Models/BiaConfigurationVersion.php`, `app/Enums/BcmRevisionStatus.php`, `app/Services/Bcm/BcmProjectAccess.php`, `app/Policies/ProjectBcmPolicy.php`, `app/Services/Bcm/BiaConfigurationWorkflow.php`, `database/migrations/2026_10_11_070100_create_bia_configuration_versions.php`, `tests/Feature/Bcm/InteractsWithBcm.php`, `tests/Feature/Bcm/BiaConfigurationTest.php`.

**Interfaces:** Produces `BiaConfigurationWorkflow::initialize(IsmsProject, User): BiaConfigurationVersion`, `activate(IsmsProject, array $minutes, int $expectedLockVersion, User): BiaConfigurationVersion`, `current(IsmsProject): ?BiaConfigurationVersion`. Project configuration serializes versions through locked project row; one active version, immutable previous versions. `initialize` is idempotent. No global seed or project BCMS-flag dependency.

- [ ] Write `test_defaults_without_bcms_flag`: initialize project with flag false; assert minutes exactly `[60,240,480,1440,4320,10080]`, repeated initialization same ID, one audit event. Providers reject duplicate/descending/0/fractional/13-value/>525600 input and inactive actor; assert zero writes/audits.
- [ ] Run `... tests/Feature/Bcm/BiaConfigurationTest.php`; confirm RED from missing schema/services.
- [ ] Implement contracts, database uniqueness and immutable published configuration; initialize draft-free default configuration on explicit BCM first-use POST, never mutate on GET.
- [ ] Run same test, scoped Pint and PHPStan on touched code; expect GREEN. Commit `feat: add versioned project BIA configuration`.

### Task 2: BIA und Assetziele mit historischer Freigabe

**Files:** Create models `BusinessImpactAnalysis.php`, `BiaRevision.php`, `BiaImpact.php`, `AssetRecoveryObjectiveRevision.php`; `app/Enums/BiaImpactArea.php`; services `app/Services/Bcm/BiaWorkflow.php`, `AssetRecoveryWorkflow.php`, `BiaReleaseValidator.php`; migrations `2026_10_11_070200_create_bia_revisions.php`, `2026_10_11_070210_create_asset_recovery_objectives.php`; tests `tests/Feature/Bcm/BiaWorkflowTest.php`, `AssetRecoveryTest.php`.

**Interfaces:** Consumes Task 1. Produces `BiaWorkflow::draft(BusinessProcess, User): BiaRevision`, `save(BiaRevision, array $data, int, User): BiaRevision`, `approve(BiaRevision, int, User): BiaRevision`; equivalent `AssetRecoveryWorkflow::draft(Asset, User)`, `save/approve(AssetRecoveryObjectiveRevision, array/int, User)`. Approve calls `BiaReleaseValidator::validate(BiaRevision): void`; Task 4 adds dependency checks to this validator. Approval validates own fields in this intermediate service-only task; no public endpoint yet.

- [ ] Write tests asserting partial draft saves, full 4x6 matrix required, 0 RPO valid, N/A requires reason, RTO= MTPD fails, inactive process/actor fails, selfapproval succeeds with actor/time. Assert direct update/delete approved revision or its impacts fails; next draft leaves original snapshot untouched. Config switch copies only exact time matches.
- [ ] Run both test files; confirm RED. Implement FK/enum/check/unique constraints and immutable guards; fresh configuration on draft, never interpolate. Stale expected version rejects with 409 and no audit.
- [ ] Run same files GREEN; add explicit stale actor reload and competing draft unique-index regression. Commit `feat: add versioned BIA and asset recovery objectives`.

### Task 3: Pfadbewusste Wiederanlaufprüfung

**Files:** Create `app/Data/Bcm/BcmDependencyPath.php`, `BcmWarning.php`, `app/Services/Bcm/BcmDependencyTraversal.php`, `BcmConsistencyChecker.php`; test `tests/Feature/Bcm/BcmConsistencyTest.php`. Read existing `app/Services/Dependencies/DependencyGraph.php`; do not alter its current return semantics.

**Interfaces:** `BcmDependencyTraversal::dependencies(IsmsProject, BusinessProcess): array<BcmDependencyPath>` (list; each DTO: target type/ID, `critical` bool, ordered edge IDs). `BcmConsistencyChecker::warnings(BiaRevision): array<BcmWarning>` (list; DTO: `code`, target type/ID, pinned revision ID, path edge IDs, `blocking` bool, deterministic `fingerprint`). BFS tracks `(node, critical_path)` separately, batches frontier queries, rejects foreign roots, remains cycle-safe. A canonical path per target/critical-class suffices; deterministic edge sorting. No exponential enumeration.

- [ ] Write cases: critical asset RTO 300 against process 240 warns; equal 240 does not; RPO 0 compares exactly; unknown/N/A distinct. Include direct supporting path and longer all-critical path to same target; assert blocking critical warning survives. Cycles terminate; inactive edges/nodes omitted; foreign target never returned.
- [ ] Run file RED; implement exact-path DTOs and source fingerprints (ordered canonical source identity/version/value/edge relevance, SHA-256). Include critical missing/stale target and impact/MTPD/descending-impact hints from spec.
- [ ] Run file GREEN and query-count test with 100 dependencies (queries scale with batched frontier, not one per node). Commit `feat: check dependency recovery targets with path provenance`.

### Task 4: Konfliktakzeptanz und Aktualität

**Files:** Create `app/Models/BcmConflictAcceptance.php`, `app/Services/Bcm/BcmConflictWorkflow.php`, `BcmFreshness.php`, migration `2026_10_11_070400_create_bcm_conflict_acceptances.php`; modify `BiaReleaseValidator.php`, `BiaWorkflow.php`, `AssetRecoveryWorkflow.php`; tests `tests/Feature/Bcm/BcmAcceptanceTest.php`, `BcmFreshnessTest.php`.

**Interfaces:** `BcmConflictWorkflow::accept(BiaRevision, string $fingerprint, string $reason, int, User): BcmConflictAcceptance`. `BcmFreshness::bia(BiaRevision): array{current:bool,reasons:list<string>}`; subsequent Tasks 5/7 add `plan(ContinuityPlanRevision)` and `exercise(BcmExercise)` with same return shape. Acceptance only for freshly recomputed warnings; no arbitrary fingerprint. Release validator blocks unresolved blocking warnings; hard invalid numbers cannot be accepted. Advisory warnings are shown and require reason when spec mandates it.

- [ ] Test warning acceptance permits release; blank reason rejected. New source revision with identical numbers invalidates old acceptance; changed critical path invalidates it; zero mutation on stale acceptance request. Config switch/deactivation/asset version change makes released BIA review_required without editing its approved content.
- [ ] Run files RED; implement fresh computed state and acceptance history, include checker fingerprint list in each released revision's source snapshot. Lock sources inside release transaction. Config activation stays usable while prior BIA remains historically approved.
- [ ] Run files GREEN. Test `assertSame('approved', $old->fresh()->status->value)` with `assertFalse($freshness->bia($old)['current'])`. Commit `feat: gate BIA approval with current conflict decisions`.

### Task 5: Notfallpläne, Rollen und Wiederanlaufschritte

**Files:** Create models `ContinuityPlan.php`, `ContinuityPlanRevision.php`, `ContinuityPlanProcess.php`, `ContinuityPlanStep.php`, `ContinuityPlanRole.php`, `ContinuityPlanEvidenceLink.php`, `ContinuityPlanRiskLink.php`; services `app/Services/Bcm/ContinuityPlanWorkflow.php`, `ContinuityPlanReleaseValidator.php`; migration `2026_10_11_070500_create_continuity_plans.php`; test `tests/Feature/Bcm/ContinuityPlanTest.php`; extend `BcmFreshness.php` and test helper.

**Interfaces:** `ContinuityPlanWorkflow::create(IsmsProject, array, User): ContinuityPlanRevision`, `revise(ContinuityPlanRevision, User): ContinuityPlanRevision`, `save(ContinuityPlanRevision, array, int, User): ContinuityPlanRevision`, `approve(ContinuityPlanRevision, int, User): ContinuityPlanRevision`. Payload keys: title, scenario, activation_conditions, strategy, minimum_operation, normal_operation_return, process_ids, steps (instruction/owner_name/expected_minutes), roles (role/name/email/phone/deputy_name), evidence_ids, risk_analysis_ids. Source BIA/asset revision IDs are resolved server-side, not trusted from request.

- [ ] Test complete plan approval and missing each required field/role/step/process. Reject foreign/inactive targets, stale BIA, foreign risk/Evidence, pending/rejected Evidence. Revoke verified Evidence between save and approve; assert refusal. Next revision never mutates released steps/roles/pivots.
- [ ] Run file RED; implement separate child models and composite FKs, locked snapshot release and `BcmFreshness::plan`. Linked evidence/risk history cannot be detached from approved revision. No email sending; no sum-of-step-duration claim.
- [ ] Run file GREEN, include unchanged baseline project completion (no blanket BIA requirement). Commit `feat: add versioned continuity plans and release prerequisites`.

### Task 6: Übungsschema und kompatible Finding-Herkunft

**Files:** Create models `BcmExercise.php`, `BcmExerciseCriterion.php`, `BcmExerciseResult.php`, `BcmExerciseEvidenceLink.php`; enums `BcmExerciseStatus.php`, `BcmExerciseOutcome.php`, `FindingOrigin.php`; migrations `2026_10_11_070600_create_bcm_exercises.php`, `2026_10_11_070610_extend_finding_origins.php`; modify `app/Models/Finding.php`, `database/factories/FindingFactory.php`, `app/Services/Findings/FindingWorkflow.php`, `app/Services/Evidence/EvidenceLinkService.php`, `app/Models/EvidenceFindingLink.php`, `app/Http/Controllers/FindingRegisterController.php`, `resources/js/types/work-items.ts`; tests `tests/Feature/Bcm/FindingOriginTest.php`.

**Interfaces:** Finding gains `origin_type`, nullable `bcm_exercise_result_id`; existing `project_assessment_id`/`assessment_question_id` nullable only for BCM origin. `FindingWorkflow::proposeFromExercise(BcmExerciseResult, array $data, User): Finding` is introduced in Task 7. Exercise statuses: draft/planned/conducted/completed/cancelled; outcome passed/partial/failed/not_tested. Results have project_id and unique criterion ID.

- [ ] Write schema tests: old assessment Finding retains valid provenance; raw SQL with both origins, neither origin, or foreign-project exercise result fails. Preserve active-per-question index; active-per-result index prevents duplicate proposed/accepted findings. Exercise-origin Finding accepts measures and project-bound Evidence without assessment.
- [ ] Run file RED plus existing `tests/Feature/Findings`, `tests/Feature/Measures` and `tests/Feature/Evidence`. Implement XOR provenance constraints and composite FK to result; evidence-finding links must not depend on SQL NULL FK semantics for project isolation. Normalize provenance in existing register display rather than fake question.
- [ ] Update service checks: Assessment origin keeps existing eligibility; BCM origin uses same-project result eligibility. Scan all Finding consumers with `rg 'assessment_question_id|project_assessment_id|->question|->assessment' app resources/js`, update only assumptions affecting this new origin (including risk/measure paths) and list changed paths in task report.
- [ ] Run scoped new/existing suites GREEN; data-safe down migration explicitly refuses if BCM Findings remain rather than deleting them to restore NOT NULL. Verify clean rollback before BCM data and transactional refusal with populated provenance. Commit `feat: support exercise finding provenance without assessment coupling`.

### Task 7: Übungen und ausdrückliche Abweichungsbehandlung

**Files:** Create `app/Services/Bcm/BcmExerciseWorkflow.php`, `BcmExerciseReleaseValidator.php`; modify `BcmFreshness.php`, `FindingWorkflow.php`; tests `tests/Feature/Bcm/BcmExerciseTest.php`, `BcmExerciseFindingTest.php`.

**Interfaces:** `BcmExerciseWorkflow::create(ContinuityPlanRevision, array, User): BcmExercise`, `save(BcmExercise, array, int, User): BcmExercise`, `plan(BcmExercise, int, User): BcmExercise`, `record(BcmExercise, array, int, User): BcmExercise`, `waiveFinding(BcmExerciseResult, string $reason, int, User): BcmExerciseResult`, `complete(BcmExercise, int, User): BcmExercise`, `cancel(BcmExercise, string, int, User): BcmExercise`. Task 6 Finding interface implemented here; repeated equivalent proposal returns existing active Finding, conflicting payload rejects. Completed results are immutable through services/models/database guards.

- [ ] Write state-transition table tests; fresh plan required at planning/start. Actual date and all criterion observations required; not_tested never passes. Every nonpassed result requires proposal or explicit waiver. Linked Evidence pending/foreign/revoked blocks completion. Passed criteria don't force artificial Findings.
- [ ] Run files RED; implement complete atomically, pin actual plan source history. Changed source blocks starting planned exercises; already conducted results stay attached to original plan and can be documented/completed with explicit source-change acknowledgement (stored actor/time/reason), not silently repinned. Historical completed exercise later shows review_required without reopening.
- [ ] Verify assessment Finding eligibility unchanged; exercise proposals accept/reject/close with several measures. No automatic acceptance/closure of Finding when exercise completes. Run focused files GREEN; commit `feat: record and review continuity exercises with corrective findings`.

### Task 8: HTTP-Verträge und serverseitige Isolation

**Files:** Create `app/Http/BcmRouteScope.php`, controllers `BcmWorkspaceController.php`, `BiaConfigurationController.php`, `BiaController.php`, `AssetRecoveryController.php`, `BcmConflictController.php`, `ContinuityPlanController.php`, `BcmExerciseController.php`, `BcmExerciseFindingController.php`; services `app/Services/Bcm/BcmWorkspacePresenter.php`, `BiaPresenter.php`, `ContinuityPlanPresenter.php`, `BcmExercisePresenter.php`; modify `routes/web.php`; tests `tests/Feature/Bcm/BcmHttpTest.php`, `BcmAuthorizationTest.php`, `BcmPageContractTest.php`.

**Interfaces:** Base `/projects/{project}/bcm`; overview GET there; BIA GET/POST `/bia`, PUT `/bia/{revision}`, POST `/bia/{revision}/approve` and `/revise`; configuration POST `/configuration/initialize` and `/configuration/activate`; asset draft/save/approve under `/assets/{asset}/recovery`; conflict accept under `/bia/{revision}/conflicts`; plan index/create under `/plans`, save/approve/revise under `/plans/{revision}`; exercise index/create under `/exercises`, save/plan/record/complete/cancel under `/exercises/{exercise}`; result proposal/waiver under `/exercises/{exercise}/results/{result}/finding` or `/waiver`.

- [ ] Write negative table tests covering every action for customer/inactive Actor, inactive org, completed/archived project and cross-project nested UUID. assert status 403 or scoped 404, no writes/audits. GET must cause no initialization/audit. Service calls alone remain guarded.
- [ ] Run files RED; implement thin scoped controllers/validators and presenters. Props: project, capabilities, configuration, overview, paginated entries, sources, conflicts, history, lock_version. No raw storage_path, hash-secret payload, contact audit contents or unbounded result lists. Pages/components in Task 9/10 use exact prop names from contract tests.
- [ ] Run files GREEN; stale update returns 409, invalid data redirects with field errors; pagination bounded to 25 with explicit history revision IDs. Commit `feat: expose protected BCM project workflows`.

### Task 9: BIA-Oberfläche und Projektkonfiguration

**Files:** Create `resources/js/types/bcm.ts`, `resources/js/pages/bcm/Index.vue`, `resources/js/pages/bcm/bia/Index.vue`, `resources/js/pages/bcm/bia/Show.vue`, `resources/js/components/bcm/BiaImpactMatrix.vue`, `BiaTargetsForm.vue`, `BcmConflictPanel.vue`, `BiaConfigurationPanel.vue`, `AssetRecoveryPanel.vue`; modify `resources/js/components/ProjectWorkNavigation.vue`; test `tests/Frontend/BcmBiaStateTest.mjs`.

**Interfaces:** Consumes Task 8 page props. Typed enum unions match Tasks 1–7. Use existing Inertia forms and route helpers; `BcmConflictPanel` accepts warnings and accepted fingerprints without calculating authoritative acceptability. POST explicit initialize for not-yet-initialized project.

- [ ] Write state tests: numeric zero RPO preserved, custom times retained on validation failure, 409 keeps edits and locks stale submission until reload/rebase, read-only history does not bind live edit form. Verify display of critical path and unknown versus zero.
- [ ] Implement matrix, target forms, draft/approve/new revision and configuration actions, overview readiness categories. Draft incomplete state is distinct from released stale state. Navigation independent of BCMS flag.
- [ ] Run frontend test plus `npm run check` and `npm run types:check`; GREEN. Commit `feat: add BIA and recovery goal workspace`.

### Task 10: Notfallplan- und Übungsoberfläche

**Files:** Create pages `resources/js/pages/bcm/plans/Index.vue`, `Show.vue`, `resources/js/pages/bcm/exercises/Index.vue`, `Show.vue`; components `resources/js/components/bcm/ContinuityPlanForm.vue`, `BcmRolesEditor.vue`, `BcmStepsEditor.vue`, `BcmExerciseResults.vue`, `BcmSourceHistory.vue`; modify `resources/js/types/bcm.ts`, `ProjectWorkNavigation.vue`, existing Finding-origin display from Task 6; test `tests/Frontend/BcmPlanExerciseStateTest.mjs`.

**Interfaces:** Consumes Task 8 plans/exercises props and Task 9 shared types. Evidence picker requests existing authorized endpoint, never direct storage URL. Finding proposal creation stays explicit; display proposal/decision/measure status separately from exercise completion.

- [ ] Write state tests: role/step edits survive 409; failed/not_tested outcomes require disposition; history points to actual revision not current replacement; closed exercise never exposes mutation controls. Foreign-source options absent; capability false means readable without write controls.
- [ ] Implement plans, ordered steps, roles, source snapshots, evidence/risk links, exercise criteria and results/disposition. Paginated history and warning views use server contract; no UI-only approval rules.
- [ ] Run frontend tests/check/types GREEN; production build at final Task 11 only. Commit `feat: add continuity plan and exercise workspaces`.

### Task 11: Gemeinsamer Integrations-, Sicherheits- und Abschlussgate

**Files:** Create `docs/slice-07-bcm-bia-exercises.md`, `tests/Feature/Bcm/BcmEndToEndTest.php`; update README module navigation/verification notes only. Add test fixtures solely under tests; no production example customer data.

- [ ] Write E2E: config -> complete BIA -> asset critical mismatch -> acceptance -> BIA approval -> plan approval -> exercise failed result -> Finding proposal -> acceptance -> two measures -> exercise completion; assert historical sources, actor/time, audit without contact/free-text. Revoke source and assert readiness changes without altering historical results.
- [ ] Run focused E2E GREEN. Review requirement mapping Tasks 1–10, no uncovered spec item. Obtain fresh whole-branch security/code review, fix concrete findings with targeted regressions, then rerun final gate once.
- [ ] Run `vendor/bin/pint --test`, `php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress`, full `php artisan test --compact --no-ansi` in Docker. Run `npm run check`, `npm run types:check`, frontend state tests and regular `npm run build` with PHP available to Wayfinder. Record counts/exits, do not reuse earlier slice counts.
- [ ] On a newly created disposable PostgreSQL DB only: migrate:fresh/seed, rollback all Slice-7 migrations before BCM data, migrate/seed again; verify repeat seeding. Separately test refusal of lossy finding-origin rollback with BCM data. Never reset production/current application DB.
- [ ] Document configuration, RTO/RPO limits, revision/freshness, exercise/Finding behavior and scheduler/runtime needs. Commit `docs: verify and document BCM BIA exercises`. Keep branch ready for PR; user approves publication/merge separately.

## Plan Self-Review and Handoff

Spec coverage: configuration/ratings Tasks 1–2; paths/goals/conflicts Tasks 3–4;
plans Task 5; Finding compatibility Task 6; exercises/history Task 7; security/
HTTP Task 8; surfaces Tasks 9–10; audit/integration/migration final checks Task 11.
Five Review Focus cases are explicitly assigned to tests above. Shared signatures
are defined before consumption; source actuality remains computed, not dependent
on unverified mutation hooks. No product implementation before plan approval.

Previously selected execution method: subagent-driven, task implementation followed
by independent review. Preserve this selection unless the user changes it. To limit
token costs, use task-local context, focused checks and one shared full gate rather
than repeated complete suites. Tasks are sequential due to shared contracts/DB.
