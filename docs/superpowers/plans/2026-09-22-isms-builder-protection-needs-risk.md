# Slice 6 Protection Needs & Risk Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add reproducible CIA protection-needs assessment, dependency propagation, versioned BSI 200-3 risk analysis, treatment, and management acceptance to each ISMS project.

**Architecture:** Extend the modular Laravel monolith with five relational modules: versioned methodology, versioned threat catalog/project snapshot, protection needs, risk analysis, and risk decisions. Domain services own calculations, locking, invalidation, and audit; thin controllers expose the workflows to Vue/Inertia pages, while explicit foreign keys preserve project and tenant boundaries.

**Tech Stack:** PHP 8.4, Laravel 13, PostgreSQL 18, PHPUnit 12, Vue 3, TypeScript, Inertia 3, Tailwind 4, Vite 8.

**Spec:** `docs/superpowers/specs/2026-09-22-isms-builder-protection-needs-risk-design.md`

## Global Constraints

- Work only on `feat/slice-06-protection-needs-risk`; never commit product changes directly to `main`.
- Published methodology and threat-catalog versions, completed analysis cycles, and historical decisions are immutable.
- CIA protection levels are exactly `normal`, `high`, and `very_high`.
- Frequency levels are `rare`, `medium`, `frequent`, and `very_frequent`; impact levels are `negligible`, `limited`, `considerable`, and `existential`.
- Risk levels are `low`, `medium`, `high`, and `very_high`; the server derives them from the pinned 4 x 4 matrix.
- Active dependency direction is `source -> target`, meaning source depends on target; maximum effective CIA values propagate from source to target.
- A reduction below inherited `high` or `very_high` remains ineffective until a valid second-person or documented external decision exists.
- For the `reduce` treatment, only completed linked measures can support reduced residual risk.
- Protection-needs CSV is UTF-8, comma/semicolon only, at most 5 MiB and 10,000 data rows, formula-safe, previewed, revalidated, and applied atomically.
- Writes require an active customer and a `draft` or `active` project; completed/archived projects and inactive customers are read-only.
- Cross-organization/project substitutions return `404`; known but forbidden operations return `403`.
- Audit context excludes rationales, scenarios, names, email addresses, evidence metadata/content, finding/measure text, CSV values, hashes, and dependency reasons.
- No external API, AI risk decisions, monetary risk model, aggregate risk target, graphical network editor, notification system, or new queue is introduced.
- Keep verification focused: use data providers and service tests for calculation variants instead of duplicating every case through controllers.

## Review Focus

- A diamond dependency graph with equal maximum sources must preserve deterministic provenance and never lower an effective value during recalculation; Task 3 pins this with a focused propagation test.
- A stale CSV preview followed by a direct-rating or dependency change must reject the whole confirmation without partial writes; Task 4 owns this concurrency test.
- A cross-project ID inserted into any threat, work-item, evidence, target, or decision link must be rejected both by services and database constraints; Tasks 2, 5, 7, and 8 exercise representative links.
- Planned, blocked, cancelled, or cross-project measures and rejected evidence must never reduce residual risk; Task 7 covers every prerequisite with a data provider.
- A high/very-high cycle whose decision, protection need, graph provenance, threat, measure credit, or evidence changes must become stale and block project completion; Tasks 6 and 7 cover the invalidation chain.

## File Map

The implementation uses these bounded areas:

- `app/Models/Risk*`, `app/Services/RiskMethodology/*`, and migration `060000`: published matrix versions and calculation.
- `app/Models/Threat*`, `app/Models/ProjectRiskContext*`, `app/Services/Threats/*`, and migration `061000`: baseline catalog, project snapshots, custom threats, and explicit migrations.
- `app/Models/ProtectionNeed*`, `app/Services/ProtectionNeeds/*`, and migration `062000`: direct/inherited/effective CIA values, propagation, approvals, and invalidation.
- Existing `app/Services/Imports/*`: the fourth import kind and atomic protection-needs application.
- `app/Models/RiskAnalysisTrigger.php`, `app/Models/RiskTargetThreatReview.php`, `app/Services/Risks/RiskTriggerEvaluator.php`, and migration `063000`: analysis triggers, finding-target coverage, and threat overview.
- `app/Models/RiskAnalysis*.php`, `app/Models/RiskTreatment.php`, `app/Services/Risks/*`, and migrations `064000`/`065000`: cycles, inherent/residual assessments, treatment, links, decisions, and staleness.
- `app/Http/Controllers/*Risk*`, `app/Http/Requests/Risks/*`, `app/Policies/*Risk*`, and `routes/web.php`: authorized transport layer.
- `resources/js/pages/protection-needs`, `resources/js/pages/risks`, `resources/js/components/risk`, and `resources/js/types/risk.ts`: project workspace.

---

### Task 1: Versioned Risk Methodology and Matrix

**Files:**
- Create: `database/migrations/2026_09_22_060000_create_risk_methodology_tables.php`
- Create: `app/Enums/PublicationStatus.php`
- Create: `app/Models/RiskMethodology.php`
- Create: `app/Models/RiskMethodologyVersion.php`
- Create: `app/Models/RiskFrequencyLevel.php`
- Create: `app/Models/RiskImpactLevel.php`
- Create: `app/Models/RiskLevel.php`
- Create: `app/Models/RiskMatrixCell.php`
- Create: `app/Services/RiskMethodology/RiskMatrix.php`
- Create: `app/Services/RiskMethodology/RiskMethodologyPublisher.php`
- Create: `database/seeders/RiskMethodologySeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `database/factories/RiskMethodologyVersionFactory.php`
- Test: `tests/Feature/Risks/RiskMethodologySchemaTest.php`
- Test: `tests/Feature/Risks/RiskMethodologyWorkflowTest.php`

**Interfaces:**
- Consumes: `AuditLogger::record(string $eventType, ?User $actor, array $context, ?string $customerOrganizationId): AuditEvent`.
- Produces: `RiskMatrix::category(RiskMethodologyVersion $version, string $frequencyKey, string $impactKey): RiskLevel`, `RiskMethodologyPublisher::clonePublished(RiskMethodologyVersion $source, string $description, User $actor): RiskMethodologyVersion`, `replaceDraftMatrix(RiskMethodologyVersion $draft, array $levels, array $cells, User $actor): RiskMethodologyVersion`, and `publish(RiskMethodologyVersion $version, User $actor): RiskMethodologyVersion`.

- [ ] **Step 1: Write schema and default-matrix tests**

```php
public function test_default_methodology_contains_the_exact_bsi_matrix(): void
{
    $this->seed(RiskMethodologySeeder::class);
    $version = RiskMethodologyVersion::query()->where('version', 1)->sole();

    $expected = [
        'negligible' => ['rare' => 'low', 'medium' => 'low', 'frequent' => 'low', 'very_frequent' => 'medium'],
        'limited' => ['rare' => 'low', 'medium' => 'low', 'frequent' => 'high', 'very_frequent' => 'high'],
        'considerable' => ['rare' => 'medium', 'medium' => 'high', 'frequent' => 'very_high', 'very_frequent' => 'very_high'],
        'existential' => ['rare' => 'high', 'medium' => 'very_high', 'frequent' => 'very_high', 'very_frequent' => 'very_high'],
    ];

    foreach ($expected as $impact => $row) {
        foreach ($row as $frequency => $risk) {
            $this->assertSame($risk, app(RiskMatrix::class)->category($version, $frequency, $impact)->key);
        }
    }
}
```

Also assert UUID keys, unique `(methodology_id, version)`, unique ordered level keys per version, unique matrix coordinates, all foreign keys, and reversible migration rollback.

- [ ] **Step 2: Run the focused tests and verify RED**

Run: `php artisan test tests/Feature/Risks/RiskMethodologySchemaTest.php tests/Feature/Risks/RiskMethodologyWorkflowTest.php`

Expected: FAIL because the tables, models, seeder, and services do not exist.

- [ ] **Step 3: Implement the relational methodology schema and models**

Create family/version tables plus frequency, impact, risk-level, and matrix-cell tables. Use UUIDs, composite uniqueness, order checks greater than zero, publication metadata, and a PostgreSQL trigger that rejects update/delete of a published version and its children. Cast `status` to:

```php
enum PublicationStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
}
```

The publisher must lock the version, require an active internal admin, require exactly four uniquely ordered values on both axes and 16 distinct cells, set publication metadata, and record `risk_methodology.published` with IDs only. `clonePublished()` creates the next draft version with copied children; `replaceDraftMatrix()` validates and replaces only a locked draft and audits changed structural field names without descriptions.

- [ ] **Step 4: Seed the immutable BSI default and implement lookup**

Seed one family key `bsi-200-3`, version `1`, the exact labels/descriptions and 16-cell matrix from the spec. `RiskMatrix::category()` must query only children of the supplied version and reject missing keys with a domain validation error rather than accepting a client category.

- [ ] **Step 5: Cover publication failures and immutable rows**

```php
#[DataProvider('incompleteVersionCases')]
public function test_incomplete_or_duplicate_versions_cannot_be_published(Closure $breakVersion): void
{
    $version = RiskMethodologyVersion::factory()->draftWithCompleteMatrix()->create();
    $breakVersion($version);

    $this->expectException(ValidationException::class);
    app(RiskMethodologyPublisher::class)->publish($version, $this->admin());
}
```

Test missing cell, duplicate key/order, non-admin publication, published child mutation through raw query, redacted audit context, and transaction rollback when audit fails.

- [ ] **Step 6: Run focused tests and commit**

Run: `php artisan test tests/Feature/Risks/RiskMethodologySchemaTest.php tests/Feature/Risks/RiskMethodologyWorkflowTest.php`

Expected: PASS.

```bash
git add app/Enums/PublicationStatus.php app/Models/RiskMethodology.php app/Models/RiskMethodologyVersion.php app/Models/RiskFrequencyLevel.php app/Models/RiskImpactLevel.php app/Models/RiskLevel.php app/Models/RiskMatrixCell.php app/Services/RiskMethodology database/migrations/2026_09_22_060000_create_risk_methodology_tables.php database/factories/RiskMethodologyVersionFactory.php database/seeders tests/Feature/Risks/RiskMethodologySchemaTest.php tests/Feature/Risks/RiskMethodologyWorkflowTest.php
git commit -m "feat: add versioned risk methodology"
```

### Task 2: Threat Catalog and Project Risk Context

**Files:**
- Create: `database/migrations/2026_09_22_061000_create_threat_catalog_and_risk_context_tables.php`
- Create: `app/Models/ThreatCatalog.php`
- Create: `app/Models/ThreatCatalogVersion.php`
- Create: `app/Models/ThreatCatalogEntry.php`
- Create: `app/Models/ProjectRiskContext.php`
- Create: `app/Models/ProjectRiskContextChange.php`
- Create: `app/Models/ProjectThreat.php`
- Create: `app/Services/Threats/ThreatCatalogPublisher.php`
- Create: `app/Services/Threats/ProjectRiskContextService.php`
- Create: `database/seeders/data/bsi-elemental-threats-2023.php`
- Create: `database/seeders/ThreatCatalogSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Modify: `app/Models/IsmsProject.php`
- Create: `database/factories/ThreatCatalogVersionFactory.php`
- Test: `tests/Feature/Risks/ThreatCatalogTest.php`
- Test: `tests/Feature/Risks/ProjectRiskContextTest.php`

**Interfaces:**
- Consumes: published `RiskMethodologyVersion`, `AuditLogger`.
- Produces: `ThreatCatalogPublisher::clonePublished(ThreatCatalogVersion $source, User $actor): ThreatCatalogVersion`, `upsertDraftEntry(ThreatCatalogVersion $draft, array $data, User $actor): ThreatCatalogEntry`, `publish(ThreatCatalogVersion $version, User $actor): ThreatCatalogVersion`, `ProjectRiskContextService::initialize(IsmsProject $project, RiskMethodologyVersion $methodology, ThreatCatalogVersion $catalog, User $actor): ProjectRiskContext`, and `migrate(ProjectRiskContext $context, RiskMethodologyVersion $methodology, ThreatCatalogVersion $catalog, User $actor): ProjectRiskContext`.

- [ ] **Step 1: Write catalog, snapshot, and tenant-integrity tests**

```php
public function test_initialization_pins_versions_and_copies_all_47_baseline_threats(): void
{
    $this->seed([RiskMethodologySeeder::class, ThreatCatalogSeeder::class]);
    $project = IsmsProject::factory()->create();

    $context = app(ProjectRiskContextService::class)->initialize(
        $project,
        RiskMethodologyVersion::query()->where('status', 'published')->sole(),
        ThreatCatalogVersion::query()->where('status', 'published')->sole(),
        $this->consultant(),
    );

    $this->assertCount(47, $context->projectThreats);
    $this->assertSame('G 0.1', $context->projectThreats->first()->code);
    $this->assertSame('G 0.47', $context->projectThreats->last()->code);
}
```

Assert source edition `2023`, stable unique codes, at least one CIA flag, one context per project, composite project constraints, immutable published rows, isolated project snapshots, idempotent initialization, and `404`/validation rejection for mismatched projects.

- [ ] **Step 2: Run the focused tests and verify RED**

Run: `php artisan test tests/Feature/Risks/ThreatCatalogTest.php tests/Feature/Risks/ProjectRiskContextTest.php`

Expected: FAIL because the catalog/context domain does not exist.

- [ ] **Step 3: Implement catalog and context tables**

Use explicit FKs. Project threats carry `project_id`, `project_risk_context_id`, optional `threat_catalog_entry_id`, code/title/description, CIA booleans, source edition/reference, custom rationale/responsible fields, active/retired metadata, and creator. Add composite unique support so a context cannot copy a threat from another version or project.

`project_risk_context_changes` must persist old/new methodology and catalog IDs for `initialized` and `migrated` actions. Published global catalog rows use the same database immutability strategy as Task 1.

- [ ] **Step 4: Add the official 47-entry seed data and publisher**

Populate `database/seeders/data/bsi-elemental-threats-2023.php` with codes `G 0.1` through `G 0.47`, exact German titles from the linked official Edition 2023 source, bounded descriptions/source references, and explicit CIA flags. The test must reject missing codes, duplicate codes, gaps, and entries with all CIA flags false.

`clonePublished()` creates the next editable version and copies its entries. `upsertDraftEntry()` accepts only draft entries and enforces code/title/description/CIA rules. `publish()` requires a non-empty, internally consistent catalog, sets publication metadata, and makes the version and entries immutable.

- [ ] **Step 5: Implement initialization and explicit migration**

Both methods lock the project, require a writable customer project and internal admin/consultant, and reject draft versions. Initialization copies the catalog once. Migration creates a change row, copies only the new snapshot entries, retains old project threats, changes the current pointers, and records redacted `risk_context.initialized` or `risk_context.migrated` audit events.

- [ ] **Step 6: Run focused tests and commit**

Run: `php artisan test tests/Feature/Risks/ThreatCatalogTest.php tests/Feature/Risks/ProjectRiskContextTest.php`

Expected: PASS.

```bash
git add app/Models/ThreatCatalog.php app/Models/ThreatCatalogVersion.php app/Models/ThreatCatalogEntry.php app/Models/ProjectRiskContext.php app/Models/ProjectRiskContextChange.php app/Models/ProjectThreat.php app/Models/IsmsProject.php app/Services/Threats database/migrations/2026_09_22_061000_create_threat_catalog_and_risk_context_tables.php database/factories/ThreatCatalogVersionFactory.php database/seeders tests/Feature/Risks/ThreatCatalogTest.php tests/Feature/Risks/ProjectRiskContextTest.php
git commit -m "feat: add project threat snapshots"
```

### Task 3: Protection Needs, Propagation, and Downgrade Control

**Files:**
- Create: `database/migrations/2026_09_22_062000_create_protection_needs_tables.php`
- Create: `app/Enums/ProtectionObjective.php`
- Create: `app/Enums/ProtectionLevel.php`
- Create: `app/Enums/ProtectionNeedStatus.php`
- Create: `app/Enums/DowngradeRequestStatus.php`
- Create: `app/Models/ProtectionNeed.php`
- Create: `app/Models/ProtectionNeedSource.php`
- Create: `app/Models/ProtectionNeedDowngradeRequest.php`
- Create: `app/Data/ProtectionNeeds/ProtectionRating.php`
- Create: `app/Services/ProtectionNeeds/ProtectionNeedInitializer.php`
- Create: `app/Services/ProtectionNeeds/ProtectionNeedService.php`
- Create: `app/Services/ProtectionNeeds/ProtectionNeedPropagator.php`
- Create: `app/Services/ProtectionNeeds/DowngradeWorkflow.php`
- Create: `app/Services/ProtectionNeeds/ProtectionNeedInvalidator.php`
- Modify: `app/Services/Threats/ProjectRiskContextService.php`
- Modify: `app/Services/Dependencies/DependencyService.php`
- Modify: `app/Services/Registers/BusinessProcessService.php`
- Modify: `app/Services/Registers/AssetService.php`
- Modify: `app/Models/IsmsProject.php`
- Create: `database/factories/ProtectionNeedFactory.php`
- Test: `tests/Feature/ProtectionNeeds/ProtectionNeedSchemaTest.php`
- Test: `tests/Feature/ProtectionNeeds/ProtectionNeedWorkflowTest.php`
- Test: `tests/Feature/ProtectionNeeds/ProtectionNeedPropagationTest.php`
- Test: `tests/Feature/ProtectionNeeds/DowngradeWorkflowTest.php`

**Interfaces:**
- Consumes: `DependencyNode`, active `DependencyEdge` rows, `AuditLogger`, project context.
- Produces: `ProtectionNeedService::rateTarget(IsmsProject $project, DependencyNode $target, array $ratings, User $actor, array $expectedVersions): Collection`, `approveTarget(IsmsProject $project, DependencyNode $target, User $actor, array $expectedVersions): void`, `ProtectionNeedPropagator::recalculate(IsmsProject $project, array $roots): Collection`, `DowngradeWorkflow::request(ProtectionNeed $need, ProtectionLevel $requested, string $rationale, User $actor): ProtectionNeedDowngradeRequest`, `decideInternal(ProtectionNeedDowngradeRequest $request, bool $approved, string $rationale, User $decider): ProtectionNeedDowngradeRequest`, and `recordExternal(ProtectionNeedDowngradeRequest $request, bool $approved, string $name, string $function, string $decisionDate, string $rationale, ?EvidenceFile $evidence, User $recorder): ProtectionNeedDowngradeRequest`.

- [ ] **Step 1: Write explicit-target and direct-rating tests**

```php
public function test_a_target_has_exactly_three_unique_cia_rows(): void
{
    $process = BusinessProcess::factory()->create();
    app(ProtectionNeedInitializer::class)->ensureTarget(DependencyNode::process($process));

    $this->assertDatabaseCount('protection_needs', 3);
    $this->assertEqualsCanonicalizing(
        ['confidentiality', 'integrity', 'availability'],
        ProtectionNeed::query()->pluck('objective')->map->value()->all(),
    );
}
```

Assert exactly one process/asset FK, project-target composite integrity, rationale required per direct value, optimistic version conflict, `draft -> assessed -> approved`, and inactive/read-only rejection.

- [ ] **Step 2: Write propagation and four-eyes tests**

Build `P1 -> A1`, `P2 -> A1`, `A1 -> A2` plus a diamond with equal `very_high` sources. Assert maximum per objective, transitive propagation, deterministic tied provenance, inactive-edge exclusion, and recalculation restricted to reachable supporting nodes.

```php
public function test_inherited_high_cannot_be_lowered_until_a_valid_decision_exists(): void
{
    [$source, $target, $need, $requester, $secondUser] = $this->highInheritanceContext();
    $request = app(DowngradeWorkflow::class)->request($need, ProtectionLevel::Normal, 'Documented compensating context', $requester);

    $this->assertSame(ProtectionLevel::High, $need->fresh()->effective_level);
    app(DowngradeWorkflow::class)->decideInternal($request, true, 'Approved after review', $secondUser);
    $this->assertSame(ProtectionLevel::Normal, $need->fresh()->effective_level);
}
```

Cover requester self-approval rejection, external name/function/date/rationale, optional verified same-project evidence, cross-project evidence rejection, and invalidation after a graph/direct/provenance change.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/ProtectionNeeds`

Expected: FAIL because the protection-needs domain does not exist.

- [ ] **Step 4: Implement schema, enums, models, and initialization**

Use enum ordering helpers:

```php
enum ProtectionLevel: string
{
    case Normal = 'normal';
    case High = 'high';
    case VeryHigh = 'very_high';

    public function rank(): int
    {
        return match ($this) { self::Normal => 1, self::High => 2, self::VeryHigh => 3 };
    }
}
```

Store one row per target/objective, direct/inherited/effective values, rationale, status, `needs_review`, rating metadata, and integer lock version. Source rows store originating target, originating value, and deterministic first edge. Add partial unique indexes for target/objective and one pending downgrade per need. A downgrade request's optional `external_evidence_id` uses `(external_evidence_id, project_id)` composite integrity so evidence cannot cross project boundaries.

- [ ] **Step 5: Implement propagation and invalidation**

Load active project nodes/edges once, topologically propagate each direct/effective CIA maximum from dependent source to supporting target, sort equal sources by typed stable key, and replace only changed source sets. Keep the higher inherited value while a current approval is absent. Lock the project for writes and guard against cycles even if legacy data is corrupted.

Call recalculation from process/asset activation changes and dependency create/status changes after their existing validation and before audit transaction completion. At this task boundary, `ProtectionNeedInvalidator` invalidates affected target approvals and downgrade requests without deleting history; Task 6 extends the same interface to linked risk cycles after their schema exists.

- [ ] **Step 6: Implement downgrade decisions and audit redaction**

Internal approval requires `decider.id !== requester.id`. External approval stores recorder separately from external person fields. Reject obsolete requested/inherited values at decision time. Use `approved`, `rejected`, `superseded`, and `invalidated`; recalculate within the same transaction. Audit only IDs, objective, categorical values, statuses, and decision channel.

- [ ] **Step 7: Run focused and existing graph tests, then commit**

Run: `php artisan test tests/Feature/ProtectionNeeds tests/Feature/Dependencies/DependencyWorkflowTest.php tests/Feature/Registers/RegisterWorkflowTest.php`

Expected: PASS.

```bash
git add app/Enums/ProtectionObjective.php app/Enums/ProtectionLevel.php app/Enums/ProtectionNeedStatus.php app/Enums/DowngradeRequestStatus.php app/Models/ProtectionNeed.php app/Models/ProtectionNeedSource.php app/Models/ProtectionNeedDowngradeRequest.php app/Data/ProtectionNeeds app/Services/ProtectionNeeds app/Services/Threats/ProjectRiskContextService.php app/Services/Dependencies/DependencyService.php app/Services/Registers app/Models/IsmsProject.php database/migrations/2026_09_22_062000_create_protection_needs_tables.php database/factories/ProtectionNeedFactory.php tests/Feature/ProtectionNeeds
git commit -m "feat: add propagated protection needs"
```

### Task 4: Atomic Protection-Needs CSV Import

**Files:**
- Modify: `app/Enums/RegisterImportKind.php`
- Modify: `app/Services/Imports/RegisterRowValidator.php`
- Modify: `app/Services/Imports/RegisterImportPreviewer.php`
- Modify: `app/Services/Imports/RegisterImportConfirmer.php`
- Modify: `app/Services/Imports/RegisterImportStateFingerprint.php`
- Create: `app/Services/Imports/ProtectionNeedImportApplier.php`
- Modify: `app/Services/Registers/RegisterPresenter.php`
- Modify: `app/Http/Controllers/RegisterImportController.php`
- Modify: `resources/js/types/registers.ts`
- Test: `tests/Unit/Imports/RegisterRowValidatorTest.php`
- Test: `tests/Feature/Imports/ProtectionNeedImportTest.php`
- Test: `tests/Feature/Imports/ProtectionNeedImportAtomicityTest.php`

**Interfaces:**
- Consumes: `ProtectionNeedService::rateTarget()`, `ProtectionNeedPropagator::recalculate()`, existing import batch lifecycle.
- Produces: import kind `RegisterImportKind::ProtectionNeeds` and `ProtectionNeedImportApplier::apply(IsmsProject $project, array $payload, User $actor): void`.

- [ ] **Step 1: Write parser, preview, and stale-confirmation tests**

```php
public function test_protection_need_header_and_values_are_exact(): void
{
    $row = app(RegisterRowValidator::class)->validate(RegisterImportKind::ProtectionNeeds, [
        'object_type' => 'process', 'object_key' => 'P-01',
        'confidentiality' => 'high', 'confidentiality_rationale' => 'Customer records',
        'integrity' => 'very_high', 'integrity_rationale' => 'Binding calculations',
        'availability' => 'normal', 'availability_rationale' => 'Manual fallback',
    ], 2);

    $this->assertSame('P-01', $row->values['object_key']);
}
```

Test exact case-insensitive headers, process/asset lookup, three allowed values, required matching rationales, duplicate typed keys, formula protection, unknown/inactive target, 5 MiB/10,000-row inherited limits, downgrade-required preview marker, and no sensitive values in audit.

The concurrency test must preview a valid file, change a direct rating or dependency edge, confirm, and assert conflict plus no rating/import status changes.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Unit/Imports/RegisterRowValidatorTest.php tests/Feature/Imports/ProtectionNeedImportTest.php tests/Feature/Imports/ProtectionNeedImportAtomicityTest.php`

Expected: FAIL because the enum and import handlers know only three kinds.

- [ ] **Step 3: Extend validation, preview, and fingerprinting**

Add the exact eight headers from the spec. Normalize `object_key` with `RegisterKey`; treat `object_type` as `DependencyNodeType`; do not allow blank rationales. Preview compares only direct values/rationales and adds `requires_downgrade_approval: true|false` per bounded row. Fingerprinting includes project status, target IDs/versions, relevant direct values, and active dependency graph state.

- [ ] **Step 4: Implement atomic confirmation**

Within the existing batch/project locks, revalidate ownership, expiry, fingerprint, target state, and every normalized row. Apply rows without per-row audit, run one affected-subgraph recalculation, then record one `protection_needs_import.applied` aggregate event and mark the batch applied in the same transaction. Never import approvals, inherited values, risks, treatments, or decisions.

- [ ] **Step 5: Run all import tests and commit**

Run: `php artisan test tests/Unit/Imports tests/Feature/Imports`

Expected: PASS.

```bash
git add app/Enums/RegisterImportKind.php app/Services/Imports app/Services/Registers/RegisterPresenter.php app/Http/Controllers/RegisterImportController.php resources/js/types/registers.ts tests/Unit/Imports tests/Feature/Imports
git commit -m "feat: import direct protection needs"
```

### Task 5: Mandatory Triggers, Finding Coverage, and Threat Overview

**Files:**
- Create: `database/migrations/2026_09_22_063000_create_risk_trigger_and_threat_review_tables.php`
- Create: `app/Enums/RiskTriggerType.php`
- Create: `app/Enums/RiskTriggerStatus.php`
- Create: `app/Enums/ThreatReviewStatus.php`
- Create: `app/Models/RiskAnalysisTrigger.php`
- Create: `app/Models/RiskTargetThreatReview.php`
- Create: `app/Models/FindingProcessLink.php`
- Create: `app/Models/FindingAssetLink.php`
- Create: `app/Services/Risks/FindingRiskTargetService.php`
- Create: `app/Services/Risks/RiskTriggerEvaluator.php`
- Create: `app/Services/Threats/ProjectThreatService.php`
- Create: `app/Services/Threats/ThreatOverviewService.php`
- Modify: `app/Services/Findings/FindingWorkflow.php`
- Modify: `app/Services/ProtectionNeeds/ProtectionNeedInvalidator.php`
- Modify: `app/Services/Threats/ProjectRiskContextService.php`
- Test: `tests/Feature/Risks/RiskTriggerTest.php`
- Test: `tests/Feature/Risks/ThreatOverviewTest.php`
- Test: `tests/Feature/Risks/ProjectThreatWorkflowTest.php`

**Interfaces:**
- Consumes: effective protection needs, accepted/open findings, project threat snapshot.
- Produces: `RiskTriggerEvaluator::refreshTarget(IsmsProject $project, DependencyNode $target): Collection`, `FindingRiskTargetService::link(Finding $finding, DependencyNode $target, User $actor): void`, `ProjectThreatService::createCustom(IsmsProject $project, array $data, User $actor): ProjectThreat`, and `ThreatOverviewService::review(RiskTargetThreatReview $review, ThreatReviewStatus $status, ?string $rationale, User $actor): RiskTargetThreatReview`.

- [ ] **Step 1: Write trigger and threat-overview tests**

```php
public function test_elevated_effective_need_opens_a_mandatory_trigger_and_lowering_supersedes_it(): void
{
    [$project, $target] = $this->ratedTarget(ProtectionLevel::High);
    $triggers = app(RiskTriggerEvaluator::class)->refreshTarget($project, $target);
    $this->assertSame(RiskTriggerStatus::Open, $triggers->sole()->status);

    $this->rateAll($target, ProtectionLevel::Normal);
    app(RiskTriggerEvaluator::class)->refreshTarget($project, $target);
    $this->assertSame(RiskTriggerStatus::Superseded, $triggers->sole()->fresh()->status);
}
```

Cover accepted not-closed finding links, closing/rejecting history, mandatory nonstandard trigger, dismissible uncompleted manual trigger, 47 initialized threat reviews for mandatory targets, rationale required for not-applicable, applicable review remaining incomplete, and cross-project finding/target rejection at service and FK levels.

- [ ] **Step 2: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Risks/RiskTriggerTest.php tests/Feature/Risks/ThreatOverviewTest.php tests/Feature/Risks/ProjectThreatWorkflowTest.php`

Expected: FAIL because trigger and review tables do not exist.

- [ ] **Step 3: Implement schema and deterministic trigger refresh**

Use explicit process/asset columns and check constraints. Keep one active trigger per project/target/type/source identity. Elevated and finding-coverage triggers are derived; nonstandard/manual triggers store bounded rationale and creator. Mandatory triggers can only become `covered` or `superseded`, never user-dismissed.

- [ ] **Step 4: Implement custom threats and threat overview**

Require code/title/description/addition rationale, at least one CIA flag, responsible name, optional RFC email, and writable project. Once referenced, a custom threat update retires the old row and creates a replacement; baseline rows are never edited. For a mandatory target, initialize one review per active project threat. Reject blanket all-not-applicable transport operations by exposing only one reviewed threat per service call. Extend context migration so newly copied threats create unreviewed rows for existing mandatory targets without deleting old reviews.

- [ ] **Step 5: Wire finding/protection changes to trigger refresh**

When a finding-target link is added, finding acceptance/closure changes, or effective protection changes, refresh only that target. Keep the call in the existing transaction so trigger/audit failure rolls back the originating state change.

- [ ] **Step 6: Run focused tests and commit**

Run: `php artisan test tests/Feature/Risks/RiskTriggerTest.php tests/Feature/Risks/ThreatOverviewTest.php tests/Feature/Risks/ProjectThreatWorkflowTest.php tests/Feature/Findings/FindingWorkflowTest.php`

Expected: PASS.

```bash
git add app/Enums/RiskTriggerType.php app/Enums/RiskTriggerStatus.php app/Enums/ThreatReviewStatus.php app/Models/RiskAnalysisTrigger.php app/Models/RiskTargetThreatReview.php app/Models/FindingProcessLink.php app/Models/FindingAssetLink.php app/Services/Risks app/Services/Threats app/Services/Findings/FindingWorkflow.php app/Services/ProtectionNeeds/ProtectionNeedInvalidator.php database/migrations/2026_09_22_063000_create_risk_trigger_and_threat_review_tables.php tests/Feature/Risks tests/Feature/Findings/FindingWorkflowTest.php
git commit -m "feat: add risk triggers and threat overview"
```

### Task 6: Risk Analyses, Cycles, and Inherent Assessment

**Files:**
- Create: `database/migrations/2026_09_22_064000_create_risk_analysis_tables.php`
- Create: `app/Enums/RiskCycleStatus.php`
- Create: `app/Enums/RiskAssessmentPhase.php`
- Create: `app/Models/RiskAnalysis.php`
- Create: `app/Models/RiskAnalysisCycle.php`
- Create: `app/Models/RiskAssessment.php`
- Create: `app/Models/RiskAnalysisTriggerLink.php`
- Create: `app/Services/Risks/RiskAnalysisWorkflow.php`
- Create: `app/Services/Risks/RiskAssessmentService.php`
- Create: `app/Services/Risks/RiskChangeInvalidator.php`
- Modify: `app/Services/Threats/ProjectRiskContextService.php`
- Modify: `app/Services/Threats/ProjectThreatService.php`
- Modify: `app/Services/Threats/ThreatOverviewService.php`
- Modify: `app/Services/ProtectionNeeds/ProtectionNeedInvalidator.php`
- Create: `database/factories/RiskAnalysisFactory.php`
- Test: `tests/Feature/Risks/RiskAnalysisSchemaTest.php`
- Test: `tests/Feature/Risks/RiskAnalysisWorkflowTest.php`
- Test: `tests/Feature/Risks/RiskInvalidationTest.php`

**Interfaces:**
- Consumes: `RiskMatrix::category()`, current project target/threat/context, current triggers.
- Produces: `RiskAnalysisWorkflow::create(IsmsProject $project, DependencyNode $target, ProjectThreat $threat, array $scenario, array $triggerIds, User $actor): RiskAnalysisCycle`, `startNextCycle(RiskAnalysis $analysis, User $actor): RiskAnalysisCycle`, `RiskAssessmentService::assessInherent(RiskAnalysisCycle $cycle, string $frequencyKey, string $impactKey, string $rationale, User $actor, int $expectedVersion): RiskAssessment`, and `RiskChangeInvalidator::invalidateForTarget(IsmsProject $project, DependencyNode $target, string $reason): void`.

- [ ] **Step 1: Write identity, cycle, and matrix-derived assessment tests**

```php
public function test_inherent_risk_is_derived_from_the_pinned_matrix(): void
{
    $cycle = $this->draftCycle();
    $assessment = app(RiskAssessmentService::class)->assessInherent(
        $cycle, 'frequent', 'considerable', 'Observed exposure and material impact',
        $this->consultant(), $cycle->lock_version,
    );

    $this->assertSame('very_high', $assessment->riskLevel->key);
    $this->assertSame(RiskCycleStatus::InherentAssessed, $cycle->fresh()->status);
}
```

Assert one process/asset target, one current non-completed cycle, monotonic cycle number, unique case-insensitive scenario title per target/threat, multiple different scenarios allowed, trigger same-project constraint, affected CIA presence, client risk category ignored/rejected, optimistic conflicts, and completed-cycle mutation rejected by database.

- [ ] **Step 2: Write invalidation tests**

Complete a fixture cycle, then independently change effective protection level, tied provenance, dependency topology, context methodology, and threat replacement. Each case must retain the old cycle, set `needs_review`, stop it covering a mandatory trigger, and permit a new cycle numbered `old + 1`.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Risks/RiskAnalysisSchemaTest.php tests/Feature/Risks/RiskAnalysisWorkflowTest.php tests/Feature/Risks/RiskInvalidationTest.php`

Expected: FAIL because analysis tables and services do not exist.

- [ ] **Step 4: Implement schema, models, and cycle creation**

Store scenario title/cause/event/impact and CIA booleans on the cycle, not audit. Use partial unique indexes for current cycles and lower-cased scenario title identity. Trigger links carry project ID and composite FKs. `startNextCycle()` copies the prior fields into an editable draft while leaving all assessments/decisions behind.

- [ ] **Step 5: Implement inherent assessment and invalidation**

Lock project/cycle, verify pinned level ownership, call `RiskMatrix`, create exactly one inherent assessment, transition `draft -> inherent_assessed`, increment the lock version, and emit redacted audit. Central invalidation sets `needs_review`, invalidates coverage/decisions through service calls, and never edits completed scenario or assessment rows.

Extend project-context migration, project-threat replacement, and protection invalidation to call the central risk invalidator. Extend threat-overview completeness so each applicable threat requires at least one current non-stale analysis cycle before the overview can complete.

- [ ] **Step 6: Run focused tests and commit**

Run: `php artisan test tests/Feature/Risks/RiskAnalysisSchemaTest.php tests/Feature/Risks/RiskAnalysisWorkflowTest.php tests/Feature/Risks/RiskInvalidationTest.php`

Expected: PASS.

```bash
git add app/Enums/RiskCycleStatus.php app/Enums/RiskAssessmentPhase.php app/Models/RiskAnalysis.php app/Models/RiskAnalysisCycle.php app/Models/RiskAssessment.php app/Models/RiskAnalysisTriggerLink.php app/Services/Risks app/Services/ProtectionNeeds app/Services/Threats database/migrations/2026_09_22_064000_create_risk_analysis_tables.php database/factories/RiskAnalysisFactory.php tests/Feature/Risks
git commit -m "feat: add risk analysis cycles"
```

### Task 7: Treatment, Residual Risk, Decisions, and Completion Guard

**Files:**
- Create: `database/migrations/2026_09_22_065000_create_risk_treatment_and_decision_tables.php`
- Create: `app/Enums/RiskTreatmentAction.php`
- Create: `app/Enums/RiskDecisionStatus.php`
- Create: `app/Enums/RiskDecisionChannel.php`
- Create: `app/Models/RiskTreatment.php`
- Create: `app/Models/RiskAnalysisFindingLink.php`
- Create: `app/Models/RiskAnalysisMeasureLink.php`
- Create: `app/Models/RiskAnalysisEvidenceLink.php`
- Create: `app/Models/RiskDecision.php`
- Create: `app/Models/RiskDecisionEvidenceLink.php`
- Create: `app/Services/Risks/RiskTreatmentWorkflow.php`
- Create: `app/Services/Risks/RiskDecisionWorkflow.php`
- Create: `app/Services/Risks/RiskCycleCompleter.php`
- Create: `app/Services/Projects/ProjectWorkflow.php`
- Modify: `app/Services/Risks/RiskAssessmentService.php`
- Modify: `app/Services/Risks/RiskChangeInvalidator.php`
- Modify: `app/Services/Measures/MeasureWorkflow.php`
- Modify: `app/Services/Evidence/EvidenceReviewService.php`
- Modify: `app/Services/Findings/FindingWorkflow.php`
- Modify: `app/Http/Controllers/IsmsProjectController.php`
- Test: `tests/Feature/Risks/RiskTreatmentWorkflowTest.php`
- Test: `tests/Feature/Risks/RiskDecisionWorkflowTest.php`
- Test: `tests/Feature/Risks/ProjectCompletionGuardTest.php`
- Test: `tests/Feature/Risks/RiskAuditTest.php`

**Interfaces:**
- Consumes: current cycle/inherent assessment, findings, measures, verified evidence, `RiskMatrix`, open mandatory triggers.
- Produces: `RiskTreatmentWorkflow::select(RiskAnalysisCycle $cycle, RiskTreatmentAction $action, array $details, User $actor): RiskTreatment`; `linkFinding(RiskAnalysisCycle $cycle, Finding $finding, User $actor): void`; `linkMeasure(RiskAnalysisCycle $cycle, Measure $measure, User $actor): void`; `linkEvidence(RiskAnalysisCycle $cycle, EvidenceFile $evidence, User $actor): void`; `RiskAssessmentService::assessResidual(RiskAnalysisCycle $cycle, string $frequencyKey, string $impactKey, string $rationale, User $actor, int $expectedVersion): RiskAssessment`; `RiskDecisionWorkflow::decideInternal(RiskAnalysisCycle $cycle, RiskDecisionStatus $status, string $rationale, string $decisionDate, User $decisionMaker): RiskDecision`; `recordExternal(RiskAnalysisCycle $cycle, RiskDecisionStatus $status, string $name, string $function, string $decisionDate, string $rationale, ?EvidenceFile $evidence, User $recorder): RiskDecision`; `RiskCycleCompleter::complete(RiskAnalysisCycle $cycle, User $actor, int $expectedVersion): RiskAnalysisCycle`; and `ProjectWorkflow::update(IsmsProject $project, array $data, User $actor): IsmsProject`.

- [ ] **Step 1: Write treatment and residual-prerequisite tests**

```php
#[DataProvider('nonCreditableMeasures')]
public function test_non_completed_measures_cannot_reduce_residual_risk(string $status): void
{
    $cycle = $this->reductionCycleWithMeasure($status);

    $this->expectException(ValidationException::class);
    app(RiskAssessmentService::class)->assessResidual(
        $cycle, 'rare', 'limited', 'Claimed reduction', $this->consultant(), $cycle->lock_version,
    );
}

public static function nonCreditableMeasures(): array
{
    return [['planned'], ['in_progress'], ['blocked'], ['cancelled']];
}
```

Also test completed same-project measure succeeds; cross-project measure fails at service/FK; transfer requires verified evidence and details; rejected evidence fails; avoid requires cessation statement; accept cannot lower frequency or impact; residual category is matrix-derived.

- [ ] **Step 2: Write management-decision and project-completion tests**

Test low/medium completion without decision, high/very-high requiring acceptance, internal assessor self-decision rejection, separate internal decision, documented external name/function/date/rationale with recorder separation, rejected decision returning to treatment, stale decision invalidation, and project completion blocked by open/stale mandatory triggers.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Risks/RiskTreatmentWorkflowTest.php tests/Feature/Risks/RiskDecisionWorkflowTest.php tests/Feature/Risks/ProjectCompletionGuardTest.php tests/Feature/Risks/RiskAuditTest.php`

Expected: FAIL because treatment/decision services and schema do not exist.

- [ ] **Step 4: Implement treatment and explicit relational links**

Use one treatment per current cycle and separate composite-FK link tables for finding, measure, evidence, trigger, and decision evidence. Validate same-project ownership in services before insert. Required details: reduction has at least one measure; transfer has counterparty plus verified evidence; avoid has verified cessation statement; accept records rationale without claiming reduction.

- [ ] **Step 5: Implement residual assessment, decision, and completion state machine**

Transitions are `inherent_assessed -> treatment_in_progress -> residual_assessed -> awaiting_decision|completed`. `assessResidual()` locks and rechecks every live prerequisite. High/very-high goes to `awaiting_decision`; accepted current decision permits completion. Rejected decision returns to `treatment_in_progress`. Complete cycles are immutable and cover their linked mandatory triggers only while not stale.

- [ ] **Step 6: Centralize project update and invalidation hooks**

Move project update/status audit from `IsmsProjectController` into `ProjectWorkflow` with a project lock. Before `completed`, call the completion guard and reject any open mandatory trigger or stale/missing current cycle. Measure status, evidence review, finding status/link, and treatment-link changes call `RiskChangeInvalidator` in the same transaction.

- [ ] **Step 7: Verify audit redaction and rollback**

Assert event count/type and that serialized context contains no scenario, rationale, external person, counterparty, email, evidence metadata, finding/measure text, or CSV value. Force `AuditLogger` failure and assert no treatment, assessment, decision, completion, or project-status write persists.

- [ ] **Step 8: Run focused plus affected existing tests and commit**

Run: `php artisan test tests/Feature/Risks tests/Feature/Measures tests/Feature/Evidence/EvidenceReviewTest.php tests/Feature/Findings tests/Feature/Projects`

Expected: PASS.

```bash
git add app/Enums/RiskTreatmentAction.php app/Enums/RiskDecisionStatus.php app/Enums/RiskDecisionChannel.php app/Models/RiskTreatment.php app/Models/RiskAnalysisFindingLink.php app/Models/RiskAnalysisMeasureLink.php app/Models/RiskAnalysisEvidenceLink.php app/Models/RiskDecision.php app/Models/RiskDecisionEvidenceLink.php app/Services/Risks app/Services/Projects app/Services/Measures/MeasureWorkflow.php app/Services/Evidence/EvidenceReviewService.php app/Services/Findings/FindingWorkflow.php app/Http/Controllers/IsmsProjectController.php database/migrations/2026_09_22_065000_create_risk_treatment_and_decision_tables.php tests/Feature/Risks tests/Feature/Measures tests/Feature/Evidence tests/Feature/Findings tests/Feature/Projects
git commit -m "feat: add risk treatment and acceptance"
```

### Task 8: Policies, Requests, Controllers, and Secure Routes

**Files:**
- Create: `app/Policies/ProjectRiskPolicy.php`
- Create: `app/Policies/ProtectionNeedPolicy.php`
- Create: `app/Policies/ProjectThreatPolicy.php`
- Create: `app/Policies/RiskAnalysisPolicy.php`
- Create: `app/Policies/RiskMethodologyVersionPolicy.php`
- Create: `app/Policies/ThreatCatalogVersionPolicy.php`
- Create: `app/Http/Requests/Risks/RateProtectionNeedRequest.php`
- Create: `app/Http/Requests/Risks/DecideDowngradeRequest.php`
- Create: `app/Http/Requests/Risks/InitializeRiskContextRequest.php`
- Create: `app/Http/Requests/Risks/MigrateRiskContextRequest.php`
- Create: `app/Http/Requests/Risks/StoreRiskTriggerRequest.php`
- Create: `app/Http/Requests/Risks/StoreProjectThreatRequest.php`
- Create: `app/Http/Requests/Risks/ReviewThreatRequest.php`
- Create: `app/Http/Requests/Risks/StoreRiskAnalysisRequest.php`
- Create: `app/Http/Requests/Risks/AssessRiskRequest.php`
- Create: `app/Http/Requests/Risks/StoreRiskTreatmentRequest.php`
- Create: `app/Http/Requests/Risks/StoreRiskDecisionRequest.php`
- Create: `app/Http/Requests/Risks/SaveRiskMethodologyDraftRequest.php`
- Create: `app/Http/Requests/Risks/SaveThreatCatalogDraftRequest.php`
- Create: `app/Http/Controllers/ProtectionNeedRegisterController.php`
- Create: `app/Http/Controllers/ProtectionNeedController.php`
- Create: `app/Http/Controllers/ProtectionNeedDowngradeController.php`
- Create: `app/Http/Controllers/RiskRegisterController.php`
- Create: `app/Http/Controllers/ProjectRiskContextController.php`
- Create: `app/Http/Controllers/RiskTriggerController.php`
- Create: `app/Http/Controllers/ProjectThreatController.php`
- Create: `app/Http/Controllers/ThreatReviewController.php`
- Create: `app/Http/Controllers/RiskAnalysisController.php`
- Create: `app/Http/Controllers/RiskTreatmentController.php`
- Create: `app/Http/Controllers/RiskDecisionController.php`
- Create: `app/Http/Controllers/RiskMethodologyController.php`
- Create: `app/Http/Controllers/ThreatCatalogController.php`
- Create: `app/Services/ProtectionNeeds/ProtectionNeedPresenter.php`
- Create: `app/Services/Risks/RiskRegisterPresenter.php`
- Modify: `routes/web.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/ProtectionNeeds/ProtectionNeedAuthorizationTest.php`
- Test: `tests/Feature/Risks/RiskAuthorizationTest.php`
- Test: `tests/Feature/Risks/RiskHttpWorkflowTest.php`
- Test: `tests/Feature/Risks/RiskPageContractTest.php`

**Interfaces:**
- Consumes: all Task 1-7 domain services.
- Produces: named Inertia GET routes `protection-needs.index`, `risks.index`, and `risk-methodologies.index`; context initialize/migrate, manual/nonstandard trigger, one-threat review, custom-threat, analysis/assessment/treatment/link/decision, methodology clone/update/publish, and threat-catalog clone/update/publish mutation routes that resolve every nested resource inside organization/project scope.

- [ ] **Step 1: Write negative authorization and nested-binding tests first**

```php
#[DataProvider('foreignResourceRoutes')]
public function test_foreign_nested_risk_resources_return_not_found(string $method, Closure $url, array $payload): void
{
    [$customer, $project, $actor] = $this->context();
    $foreign = $this->foreignRiskContext();

    $this->actingAs($actor)->call($method, $url($customer, $project, $foreign), $payload)->assertNotFound();
}
```

Cover foreign target, threat, trigger, cycle, finding, measure, evidence, downgrade, decision, and import batch; customer/inactive/non-internal user; consultant attempting global publish; completed/archived project writes; known unauthorized action `403`; read-only historical GET allowed.

- [ ] **Step 2: Write HTTP workflow and Inertia-prop contract tests**

Exercise one complete normal-risk path and one high-risk external-decision path through HTTP. Assert redirects/errors and that pages expose only IDs, display values, permissions, bounded rationales/scenarios for authorized users, and no private storage path/hash. Verify query strings contain only filters/IDs, not scenario/rationale/person/evidence content.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/ProtectionNeeds/ProtectionNeedAuthorizationTest.php tests/Feature/Risks/RiskAuthorizationTest.php tests/Feature/Risks/RiskHttpWorkflowTest.php tests/Feature/Risks/RiskPageContractTest.php`

Expected: FAIL because policies, routes, controllers, and presenters do not exist.

- [ ] **Step 4: Implement policies and validated request objects**

Policies require active internal admin/consultant for project reads/writes, writable project for mutations, admin for global publication, and actor separation for internal decisions. Requests use enum rules, exact date formats, bounded strings, email RFC validation, typed arrays of same-project IDs, and the existing `Iso8601ConcurrencyToken` or integer lock version.

- [ ] **Step 5: Implement thin controllers, presenters, and explicit routes**

Every action must call `ensureProjectBelongsToOrganization`-equivalent logic before policy authorization, then resolve child resources with `where('project_id', $project->id)`. Controllers pass validated values to one domain service and return redirect/validation responses. Presenters own eager loading, filters, pagination/bounds, permissions, and redacted UI props.

- [ ] **Step 6: Register policies, run HTTP tests, and commit**

Run: `php artisan test tests/Feature/ProtectionNeeds tests/Feature/Risks`

Expected: PASS.

```bash
git add app/Policies app/Http/Requests/Risks app/Http/Controllers app/Services/ProtectionNeeds/ProtectionNeedPresenter.php app/Services/Risks/RiskRegisterPresenter.php routes/web.php app/Providers/AppServiceProvider.php tests/Feature/ProtectionNeeds tests/Feature/Risks
git commit -m "feat: expose secure risk workflows"
```

### Task 9: Protection-Needs Vue Workspace

**Files:**
- Create: `resources/js/types/risk.ts`
- Create: `resources/js/pages/protection-needs/Index.vue`
- Create: `resources/js/components/risk/ProtectionNeedTable.vue`
- Create: `resources/js/components/risk/ProtectionNeedForm.vue`
- Create: `resources/js/components/risk/PropagationSources.vue`
- Create: `resources/js/components/risk/DowngradeDecisionForm.vue`
- Modify: `resources/js/components/ProjectWorkNavigation.vue`
- Modify: `resources/js/components/RegisterImportPanel.vue`
- Modify: `resources/js/components/RegisterImportPreview.vue`
- Modify: `resources/js/types/work-items.ts`
- Modify: `resources/js/types/registers.ts`
- Test: `tests/Feature/ProtectionNeeds/ProtectionNeedPageTest.php`

**Interfaces:**
- Consumes: `ProtectionNeedRegisterController` props and Task 8 mutation routes.
- Produces: accessible `Schutzbedarf` project tab with manual CIA assessment, propagation details, downgrade decisions, stale warnings, and protection-needs import.

- [ ] **Step 1: Extend the page-contract test with UI states**

Assert props for direct/inherited/effective values, rationale, status, `needs_review`, deterministic source list, permissions, pending downgrade, read-only project, import configuration, and bounded rows. Assert the rendered page component name is `protection-needs/Index`.

- [ ] **Step 2: Run page tests and type check to verify RED**

Run: `php artisan test tests/Feature/ProtectionNeeds/ProtectionNeedPageTest.php && npm run types:check`

Expected: FAIL because the Vue page/types do not exist and the tab union lacks `protection-needs`.

- [ ] **Step 3: Implement typed page and focused components**

Use separate `useForm` instances for rating, target approval, downgrade request, internal/external decision, and import. Show the three values side by side per objective; never imply a requested downgrade is effective before approval. Render source identity/path as text lists, not a graph. Disable mutations when `canWrite` is false and show stale/conflict feedback.

- [ ] **Step 4: Reuse the secure import UI**

Add `protection_needs` labels and preview marker rendering without loosening existing process/asset/dependency types. Display only the server-bounded preview and confirmation state. Do not copy row data into URL parameters.

- [ ] **Step 5: Run focused checks and commit**

Run: `php artisan test tests/Feature/ProtectionNeeds/ProtectionNeedPageTest.php && npm run check && npm run types:check`

Expected: PASS.

```bash
git add resources/js/types resources/js/pages/protection-needs resources/js/components/risk resources/js/components/ProjectWorkNavigation.vue resources/js/components/RegisterImportPanel.vue resources/js/components/RegisterImportPreview.vue tests/Feature/ProtectionNeeds/ProtectionNeedPageTest.php
git commit -m "feat: add protection needs workspace"
```

### Task 10: Risk and Methodology Vue Workspaces

**Files:**
- Create: `resources/js/pages/risks/Index.vue`
- Create: `resources/js/pages/risk-methodologies/Index.vue`
- Create: `resources/js/components/risk/RiskTriggerList.vue`
- Create: `resources/js/components/risk/ThreatOverview.vue`
- Create: `resources/js/components/risk/RiskAnalysisForm.vue`
- Create: `resources/js/components/risk/RiskAssessmentForm.vue`
- Create: `resources/js/components/risk/RiskTreatmentForm.vue`
- Create: `resources/js/components/risk/RiskDecisionForm.vue`
- Create: `resources/js/components/risk/RiskCycleHistory.vue`
- Modify: `resources/js/components/ProjectWorkNavigation.vue`
- Modify: `resources/js/layouts/AppLayout.vue`
- Modify: `resources/js/types/risk.ts`
- Modify: `resources/js/types/work-items.ts`
- Test: `tests/Feature/Risks/RiskWorkspacePageTest.php`
- Test: `tests/Feature/Risks/MethodologyPageTest.php`

**Interfaces:**
- Consumes: `RiskRegisterController` and `RiskMethodologyController` props/routes.
- Produces: project `Risiken` tab and admin methodology/catalog version screen.

- [ ] **Step 1: Write page contracts for workflow, read-only, and permission states**

Assert trigger groups, threat review status, analysis/cycle history, pinned version labels, graph impact context, treatment prerequisites, decision requirement, completion blockers, filters, consultant read-only publication control, admin publish control, and no sensitive query parameters.

- [ ] **Step 2: Run page tests and type check to verify RED**

Run: `php artisan test tests/Feature/Risks/RiskWorkspacePageTest.php tests/Feature/Risks/MethodologyPageTest.php && npm run types:check`

Expected: FAIL because pages/components/types do not exist.

- [ ] **Step 3: Implement the project risk workspace**

Use a table/filter overview and expandable target workspace. If the project has no risk context, show initialization with exact published versions; otherwise show pinned versions and an explicit migration preview/confirmation. Provide manual/nonstandard trigger creation, one-threat-at-a-time applicability review, custom-threat creation, scenario form, matrix selectors showing the server-derived category after save, treatment-specific fields, relational finding/measure/evidence selectors, residual prerequisite warnings, external/internal decision forms, stale banners, and immutable history. Keep each form's Inertia errors independent.

- [ ] **Step 4: Implement read/publish version workspace**

Show exact methodology/catalog version, publication state/date, source reference, 4 x 4 matrix, and threat count. Consultants can inspect only. Admin controls call draft/clone/publish endpoints and display validation failures; they cannot edit a published row.

- [ ] **Step 5: Run focused frontend/backend checks and commit**

Run: `php artisan test tests/Feature/Risks/RiskWorkspacePageTest.php tests/Feature/Risks/MethodologyPageTest.php && npm run check && npm run types:check && npm run build`

Expected: PASS.

```bash
git add resources/js/pages/risks resources/js/pages/risk-methodologies resources/js/components/risk resources/js/components/ProjectWorkNavigation.vue resources/js/layouts/AppLayout.vue resources/js/types tests/Feature/Risks/RiskWorkspacePageTest.php tests/Feature/Risks/MethodologyPageTest.php
git commit -m "feat: add risk management workspace"
```

### Task 11: Full Verification, Security Review, and Branch Handoff

**Files:**
- Modify only if verification exposes a defect: files owned by Tasks 1-10
- Create if useful for concise operator guidance: `docs/slice-06-protection-needs-risk.md`

**Interfaces:**
- Consumes: completed Slice 6 branch.
- Produces: verified feature branch ready for pull request into `main`.

- [ ] **Step 1: Run backend formatting, static analysis, and full test suite**

Run: `composer test`

Expected: PASS with no Pint, PHPStan, or PHPUnit failures.

- [ ] **Step 2: Run frontend checks and production build**

Run: `npm run lint:check && npm run format:check && npm run types:check && npm run build`

Expected: every command exits `0`.

- [ ] **Step 3: Verify migrations and seeders on PostgreSQL**

Run in the project's PostgreSQL test environment:

```bash
php artisan migrate:fresh --seed --force
php artisan migrate:rollback --step=6 --force
php artisan migrate --force
php artisan db:seed --force
```

Expected: all commands pass; re-seeding is idempotent; the BSI matrix has 16 cells and the Edition 2023 threat snapshot has 47 entries.

- [ ] **Step 4: Perform the focused security review**

Use `superpowers:requesting-code-review` for a whole-branch review after task-level reviews. The review must inspect: cross-tenant composite FKs and route substitution; published/history immutability; actor separation; audit redaction; CSV formula/stale/atomic behavior; residual prerequisites; trigger/completion blocking; and sensitive data in URLs/Inertia props.

Expected: no unresolved critical or important findings. Fix confirmed findings with a failing regression test first, rerun the affected focused suite, and commit each coherent fix.

- [ ] **Step 5: Re-run the final gate after review fixes**

Run: `composer test && npm run lint:check && npm run format:check && npm run types:check && npm run build`

Expected: PASS after all fixes.

- [ ] **Step 6: Verify branch cleanliness and commit any operator documentation**

Run: `git status --short --branch && git log --oneline main..HEAD`

Expected: clean working tree on `feat/slice-06-protection-needs-risk` with the spec, plan, task commits, and review fixes only.

If operator guidance was needed:

```bash
git add docs/slice-06-protection-needs-risk.md
git commit -m "docs: document protection needs and risk workflow"
```

- [ ] **Step 7: Hand off through the branch-finishing workflow**

Use `superpowers:verification-before-completion`, then `superpowers:finishing-a-development-branch`. Present the verified pull-request option targeting `main`; do not merge or modify `main` without the user's explicit instruction.
