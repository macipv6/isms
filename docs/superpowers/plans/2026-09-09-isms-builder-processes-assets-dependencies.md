# Processes, Assets & Dependencies Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add project-scoped business-process and asset registers, atomic CSV preview/import workflows, and a cycle-safe typed dependency graph for later risk and BCM slices.

**Architecture:** Processes, assets, dependency edges, and short-lived import batches are separate relational models behind focused policies and services. PostgreSQL enforces project ownership and allowed edge shapes; domain services enforce normalization, lifecycle, graph acyclicity, transactional imports, and redacted audits; Vue/Inertia exposes manual registers, import previews, and table-based traversal.

**Tech Stack:** Laravel 13, PHP 8.4, Vue 3, TypeScript, Inertia 3, PostgreSQL 18, PHPUnit, Larastan, Pint, Vite Plus, Docker Compose.

**Spec:** `docs/superpowers/specs/2026-09-09-isms-builder-processes-assets-dependencies-design.md`

## Global Constraints

- Only active internal `admin` and `consultant` users can access Slice 5 resources.
- Writes require an active customer and a project in `draft` or `active`; completed, archived, and inactive-customer contexts are read-only.
- Stable register keys are upper-case project-local identifiers matching `^[A-Z0-9][A-Z0-9._-]{1,63}$` and never change after creation.
- Allowed active dependency shapes are process-to-process, process-to-asset, and asset-to-asset; self-edges, duplicates, asset-to-process edges, and cycles are forbidden.
- CSV is UTF-8, at most 5 MiB and 10,000 data rows; imports use a 30-minute, single-use preview batch and apply atomically.
- Missing CSV rows never deactivate or delete existing records.
- Original CSV bytes are never retained; normalized preview payloads never appear in URLs or audit context.
- Security-sensitive writes and customer-owned redacted audit events share one transaction.
- Database constraints defend project boundaries even when application checks fail.
- All migrations have complete `down()` behavior, including explicit removal of partial indexes and check constraints created with raw SQL.
- No workflow decision depends on AI or UI-only authorization.
- Use the user's lean cadence: tests are still committed before product code, but run one combined remote CI gate per completed task unless a real failure requires a focused correction.

---

### Task 1: Register, dependency, and import-batch schema

**Files:**
- Create: `app/Enums/AssetType.php`
- Create: `app/Enums/DependencyImportance.php`
- Create: `app/Enums/DependencyNodeType.php`
- Create: `app/Enums/RegisterImportKind.php`
- Create: `app/Enums/RegisterImportStatus.php`
- Create: `app/Models/BusinessProcess.php`
- Create: `app/Models/Asset.php`
- Create: `app/Models/DependencyEdge.php`
- Create: `app/Models/RegisterImportBatch.php`
- Create: `database/factories/BusinessProcessFactory.php`
- Create: `database/factories/AssetFactory.php`
- Create: `database/factories/DependencyEdgeFactory.php`
- Create: `database/factories/RegisterImportBatchFactory.php`
- Create: `database/migrations/2026_09_09_050000_create_processes_and_assets_tables.php`
- Create: `database/migrations/2026_09_09_051000_create_dependency_edges_table.php`
- Create: `database/migrations/2026_09_09_052000_create_register_import_batches_table.php`
- Modify: `app/Models/IsmsProject.php`
- Test: `tests/Feature/Registers/RegisterSchemaTest.php`

**Interfaces:**
- Consumes: existing UUID projects and users.
- Produces: enum-cast models and project relationships consumed by Tasks 2–8.

- [ ] **Step 1: Write failing schema and database-integrity tests**

Use `RefreshDatabase`. Assert enum casts, required columns, normalized-key uniqueness per project, same key allowed in another project, composite endpoint ownership, allowed edge shapes, disallowed asset-to-process and empty/multiple endpoints, partial active-edge uniqueness, and import-batch JSON/date/status casts.

```php
public function test_dependency_endpoint_cannot_cross_project_boundary(): void
{
    $first = IsmsProject::factory()->create();
    $second = IsmsProject::factory()->create();
    $source = BusinessProcess::factory()->for($first)->create();
    $target = Asset::factory()->for($second)->create();

    $this->expectException(QueryException::class);
    DependencyEdge::factory()->create([
        'project_id' => $first->id,
        'source_process_id' => $source->id,
        'target_asset_id' => $target->id,
    ]);
}
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/Registers/RegisterSchemaTest.php`

Expected: FAIL because enums, models, factories, and tables do not exist.

- [ ] **Step 3: Add enums and models**

Use these exact backed values:

```php
enum AssetType: string
{
    case Information = 'information';
    case Application = 'application';
    case ItSystem = 'it_system';
    case Service = 'service';
    case Facility = 'facility';
}

enum DependencyImportance: string { case Critical = 'critical'; case Supporting = 'supporting'; }
enum DependencyNodeType: string { case Process = 'process'; case Asset = 'asset'; }
enum RegisterImportKind: string { case Processes = 'processes'; case Assets = 'assets'; case Dependencies = 'dependencies'; }
enum RegisterImportStatus: string { case Pending = 'pending'; case Applied = 'applied'; case Rejected = 'rejected'; case Expired = 'expired'; }
```

Models use `HasUuids`, guarded fillable fields, enum/date/JSON/boolean casts, typed relations, and PHPDoc properties needed by Larastan. Add `businessProcesses`, `assets`, `dependencyEdges`, and `registerImportBatches` relations to `IsmsProject`.

- [ ] **Step 4: Implement reversible PostgreSQL schema**

Both register tables include `(id, project_id)` unique constraints and
`unique(['project_id', 'key'])`. Add database checks for the key regex and
owner-email length. `dependency_edges` has four nullable endpoint UUIDs, an
importance check, and composite foreign keys from every `(endpoint_id,
project_id)` pair. Add a shape check equivalent to:

```sql
(
  source_process_id IS NOT NULL AND source_asset_id IS NULL
  AND (
    (target_process_id IS NOT NULL AND target_asset_id IS NULL)
    OR (target_process_id IS NULL AND target_asset_id IS NOT NULL)
  )
)
OR
(
  source_process_id IS NULL AND source_asset_id IS NOT NULL
  AND target_process_id IS NULL AND target_asset_id IS NOT NULL
)
```

Add three partial unique indexes over the endpoint pairs where `is_active =
true`. `register_import_batches` stores `payload` and `summary` as JSONB,
`expires_at`, nullable `applied_at`, and creator/project foreign keys. Index
`(project_id, kind, status)` and `expires_at`.

- [ ] **Step 5: Verify schema and reversible migrations**

Run:

```bash
php artisan test tests/Feature/Registers/RegisterSchemaTest.php
php artisan migrate:rollback --force
php artisan migrate --force
git diff --check
```

Expected: PASS and all three Slice 5 migrations recreate cleanly.

- [ ] **Step 6: Commit Task 1**

```bash
git add app/Enums app/Models app/Models/IsmsProject.php database/factories database/migrations tests/Feature/Registers/RegisterSchemaTest.php
git commit -m "feat: add process asset and dependency schema"
```

---

### Task 2: Manual process and asset workflows with authorization and audit

**Files:**
- Create: `app/Services/Registers/RegisterKey.php`
- Create: `app/Services/Registers/BusinessProcessService.php`
- Create: `app/Services/Registers/AssetService.php`
- Create: `app/Policies/BusinessProcessPolicy.php`
- Create: `app/Policies/AssetPolicy.php`
- Create: `app/Http/Requests/Processes/StoreBusinessProcessRequest.php`
- Create: `app/Http/Requests/Processes/UpdateBusinessProcessRequest.php`
- Create: `app/Http/Requests/Processes/ChangeBusinessProcessStatusRequest.php`
- Create: `app/Http/Requests/Assets/StoreAssetRequest.php`
- Create: `app/Http/Requests/Assets/UpdateAssetRequest.php`
- Create: `app/Http/Requests/Assets/ChangeAssetStatusRequest.php`
- Create: `app/Http/Controllers/BusinessProcessController.php`
- Create: `app/Http/Controllers/AssetController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Registers/RegisterWorkflowTest.php`
- Test: `tests/Feature/Registers/RegisterAuthorizationTest.php`
- Test: `tests/Feature/Registers/RegisterAuditTest.php`

**Interfaces:**
- Produces: `RegisterKey::normalize(string): string`, `BusinessProcessService::create/update/changeStatus`, and corresponding `AssetService` methods. Later imports call these services through batch-aware methods that accept normalized data and an audit-suppression flag.

- [ ] **Step 1: Write failing normalization and workflow tests**

Cover trimming/upper-casing, invalid keys, project-local duplicates, immutable
keys, all field limits, validated owner email, stale `updated_at`, create,
update, deactivate, reactivate, and no-op repetition.

```php
$process = app(BusinessProcessService::class)->create($project, [
    'key' => ' sales.eu ',
    'name' => 'Vertrieb Europa',
    'active' => true,
], $actor);

$this->assertSame('SALES.EU', $process->key);
```

- [ ] **Step 2: Write failing authorization and tenant-substitution tests**

Table-drive internal admin/consultant access, inactive internal users,
disallowed roles, customer users, inactive customers, completed/archived
projects, cross-organization project substitution, and cross-project record
substitution. Assert `404` for wrong nested ownership and `403` for a known but
forbidden operation.

- [ ] **Step 3: Write failing redacted-audit and rollback tests**

Assert customer-owned event types from the spec, changed-field names only,
stable key and IDs allowed, owner/name/description/email contents absent, one
event per meaningful mutation, no event for no-op status, and domain rollback
when a substituted `AuditLogger` throws.

- [ ] **Step 4: Run the focused tests and verify RED**

Run: `php artisan test tests/Feature/Registers/RegisterWorkflowTest.php tests/Feature/Registers/RegisterAuthorizationTest.php tests/Feature/Registers/RegisterAuditTest.php`

Expected: FAIL because policies, services, requests, routes, and controllers do not exist.

- [ ] **Step 5: Implement policies and services**

Reuse the writable-project rules from Slice 4. Lock the project and record in
the same transaction, compare the submitted ISO-8601 `updated_at` value before
updates, normalize keys only on create, and pass the customer organization ID
explicitly to `AuditLogger::record()`.

Service signatures:

```php
public function create(IsmsProject $project, array $attributes, User $actor, bool $audit = true): BusinessProcess;
public function update(BusinessProcess $process, array $attributes, User $actor, string $expectedUpdatedAt, bool $audit = true): BusinessProcess;
public function changeStatus(BusinessProcess $process, bool $active, User $actor, bool $audit = true): BusinessProcess;
```

`AssetService` mirrors them with `Asset` return types. Requests use enum rules,
field limits, `nullable|email:rfc|max:254`, and never accept project ID,
creator, key on update, or audit fields.

- [ ] **Step 6: Add nested routes and thin controllers**

Add named routes beneath `/organizations/{organization}/projects/{project}`:

```text
POST   /processes                         processes.store
PUT    /processes/{process}               processes.update
PATCH  /processes/{process}/status        processes.status
POST   /assets                            assets.store
PUT    /assets/{asset}                    assets.update
PATCH  /assets/{asset}/status             assets.status
```

Every controller method checks organization/project ownership first, then
record/project ownership, then policy authorization, and delegates mutation.

- [ ] **Step 7: Run focused tests and commit**

Run the three Task 2 test files plus `git diff --check`. Expected: PASS.

```bash
git add app/Services/Registers app/Policies app/Http/Requests/Processes app/Http/Requests/Assets app/Http/Controllers routes/web.php tests/Feature/Registers
git commit -m "feat: manage project processes and assets"
```

---

### Task 3: Bounded secure CSV reader and canonical row validation

**Files:**
- Create: `app/Data/Imports/ParsedRegisterCsv.php`
- Create: `app/Data/Imports/RegisterCsvRow.php`
- Create: `app/Services/Imports/RegisterCsvReader.php`
- Create: `app/Services/Imports/RegisterRowValidator.php`
- Test: `tests/Unit/Imports/RegisterCsvReaderTest.php`
- Test: `tests/Unit/Imports/RegisterRowValidatorTest.php`

**Interfaces:**
- Produces: `RegisterCsvReader::read(UploadedFile $file, RegisterImportKind $kind): ParsedRegisterCsv` and `RegisterRowValidator::validate(RegisterImportKind $kind, array $row, int $line): RegisterCsvRow`. Rejections use `ValidationException` with stable `file` or `rows.<line>.<field>` keys.

- [ ] **Step 1: Write failing parser boundary tests**

Use temporary real CSV fixtures. Cover UTF-8 and BOM, comma/semicolon,
arbitrary header order, quoted delimiter, embedded newline, exact headers,
unknown/duplicate/missing header, ambiguous delimiter, malformed quote, invalid
UTF-8, NUL, blank body, trailing blanks, 10,000/10,001 rows, 5 MiB/one byte
over, and formula-prefix rejection in every populated cell.

```php
$parsed = app(RegisterCsvReader::class)->read(
    UploadedFile::fake()->createWithContent('assets.csv', "key;name;type;description;owner_name;owner_email;active\nAPP-1;ERP;application;;;;true\n"),
    RegisterImportKind::Assets,
);

$this->assertSame('APP-1', $parsed->rows[0]->values['key']);
$this->assertSame(2, $parsed->rows[0]->line);
```

- [ ] **Step 2: Write failing canonical validation tests**

Assert exact process/asset/dependency columns, trimmed/null optional values,
normalized keys, exact booleans, asset type, node types, importance, length and
email rules, duplicate normalized register keys, duplicate normalized endpoint
pairs, forbidden self-edge, and asset-to-process shape.

- [ ] **Step 3: Run unit tests and verify RED**

Run: `php artisan test tests/Unit/Imports`

Expected: FAIL because reader, DTOs, and validator do not exist.

- [ ] **Step 4: Implement streaming bounded parsing**

Reject invalid `UploadedFile`, check reported size and copy no more than 5 MiB
plus one byte into `tmpfile()`. Validate UTF-8 before parsing. Detect delimiter
by parsing the first logical record under comma and semicolon and accepting
exactly one exact header set. Use `fgetcsv()` with explicit separator, quote,
and escape arguments; never use spreadsheet evaluation or shell tools.

`ParsedRegisterCsv` contains `kind`, `sha256`, `headers`, and a list of typed
rows. `RegisterCsvRow` contains original one-based line number and canonical
values only. Raw file paths and raw row strings are never retained.

- [ ] **Step 5: Implement row validation and duplicate detection**

Keep header sets in `RegisterImportKind` helper methods or one private map.
Apply the same `RegisterKey` and enum rules as manual workflows. Reject a
trimmed value starting with `=`, `+`, `-`, or `@` before domain conversion.
Collect all row errors up to a fixed display cap of 200 while still scanning
the entire bounded input for row count and duplicates.

- [ ] **Step 6: Run unit tests and commit**

Run: `php artisan test tests/Unit/Imports && git diff --check`. Expected: PASS.

```bash
git add app/Data/Imports app/Services/Imports tests/Unit/Imports
git commit -m "feat: parse bounded register csv imports"
```

---

### Task 4: Import preview batches, expiry, ownership, and cleanup

**Files:**
- Create: `app/Data/Imports/RegisterImportPreview.php`
- Create: `app/Services/Dependencies/DependencyCycleDetector.php`
- Create: `app/Services/Imports/RegisterImportPreviewer.php`
- Create: `app/Policies/RegisterImportBatchPolicy.php`
- Create: `app/Http/Requests/Imports/PreviewRegisterImportRequest.php`
- Create: `app/Http/Controllers/RegisterImportController.php`
- Create: `app/Console/Commands/PurgeRegisterImportBatches.php`
- Modify: `routes/web.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Imports/RegisterImportPreviewTest.php`
- Test: `tests/Feature/Imports/RegisterImportAuthorizationTest.php`
- Test: `tests/Feature/Imports/PurgeRegisterImportBatchesTest.php`

**Interfaces:**
- Produces: `RegisterImportPreviewer::preview(IsmsProject $project, RegisterImportKind $kind, UploadedFile $file, User $actor): RegisterImportBatch`; route `register-imports.preview`; command `register-imports:purge`.

- [ ] **Step 1: Write failing preview categorization tests**

For process and asset files, assert new/changed/unchanged/invalid categories,
normalized payload, summary counts, digest, 30-minute expiry, no original file,
no register writes, and at most 200 preview/error row props. For dependencies,
assert unknown endpoint, inactive endpoint, invalid shape, duplicate, and cycle
are invalid while preview still writes no edge.

- [ ] **Step 2: Write failing ownership, read-only, expiry, and audit tests**

Cover user ownership, project substitution, another internal user, customer
user, inactive internal user, inactive customer, completed project, expired
batch, and redacted `register_import.previewed`/`rejected` context. Assert
preview-batch persistence rolls back if audit fails.

- [ ] **Step 3: Write failing purge-command tests**

Freeze time and assert the command deletes normalized payloads for expired and
old applied/rejected batches, leaves live pending batches intact, marks expired
pending batches once, and creates no raw-row audit content.

- [ ] **Step 4: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Imports/RegisterImportPreviewTest.php tests/Feature/Imports/RegisterImportAuthorizationTest.php tests/Feature/Imports/PurgeRegisterImportBatchesTest.php`

Expected: FAIL because preview workflow, routes, policy, and command are absent.

- [ ] **Step 5: Implement preview workflow**

Parse and validate first, then compare canonical rows against project records.
Dependency preview resolves keys from project-local active records and delegates
acyclic simulation to `DependencyCycleDetector::assertAcyclic(array
$existingEdges, array $candidateEdges): void`. Represent each endpoint as the
canonical typed ID string `process:<uuid>` or `asset:<uuid>`. Task 6 reuses this
interface unchanged for manual graph writes.

Store only canonical payload rows, bounded error coordinates/codes, and counts.
Set `expires_at = now()->addMinutes(30)`. Invalid batches receive `rejected`
status and cannot confirm; valid batches receive `pending`.

- [ ] **Step 6: Add route/controller/policy and scheduled cleanup**

Add:

```text
POST /organizations/{organization}/projects/{project}/imports/{kind}/preview  register-imports.preview
GET  /organizations/{organization}/projects/{project}/imports/{batch}         register-imports.show
```

Bind `{kind}` through `RegisterImportKind::tryFrom` with 404 on invalid input.
The show route requires the creating user even for another internal admin. Add
the purge command to the existing scheduler daily; remove payload/summary by
deleting old batches rather than retaining imported row data indefinitely.

- [ ] **Step 7: Run focused tests and commit**

Run Task 4 tests and `git diff --check`. Expected: PASS.

```bash
git add app/Data/Imports app/Services/Imports app/Policies app/Http/Requests/Imports app/Http/Controllers app/Console/Commands routes tests/Feature/Imports
git commit -m "feat: preview atomic register imports"
```

---

### Task 5: Atomic process and asset import confirmation

**Files:**
- Create: `app/Services/Imports/RegisterImportConfirmer.php`
- Create: `app/Http/Requests/Imports/ConfirmRegisterImportRequest.php`
- Modify: `app/Http/Controllers/RegisterImportController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Imports/RegisterImportConfirmationTest.php`
- Test: `tests/Feature/Imports/RegisterImportAtomicityTest.php`
- Test: `tests/Feature/Imports/RegisterImportAuditTest.php`

**Interfaces:**
- Produces: `RegisterImportConfirmer::confirm(RegisterImportBatch $batch, User $actor): RegisterImportBatch` for process and asset batches; named route `register-imports.confirm`.

- [ ] **Step 1: Write failing confirmation and idempotency tests**

Assert new rows insert, existing stable keys update/reactivate/deactivate,
unchanged rows keep `updated_at`, missing CSV rows remain unchanged, keys never
change, applied batch records `applied_at`, repeated confirmation does not
reapply, and batches of another kind cannot call the wrong handler.

- [ ] **Step 2: Write failing atomicity and stale-preview tests**

After preview, create a conflicting key, make the project read-only, deactivate
the customer, expire the batch, or make one row invalid relative to current
state. Assert zero partial register writes and pending/rejected semantics from
the spec. Substitute a failing `AuditLogger` and assert all upserts and batch
state roll back.

- [ ] **Step 3: Write failing redacted summary-audit tests**

Assert one `register_import.applied` event with customer organization ID,
project/batch/kind/counts only. Assert no name, description, owner, email, CSV
row, hash, or validation value. Individual row services run with `$audit=false`
so one batch never emits thousands of per-record audit events.

- [ ] **Step 4: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Imports/RegisterImportConfirmationTest.php tests/Feature/Imports/RegisterImportAtomicityTest.php tests/Feature/Imports/RegisterImportAuditTest.php`

Expected: FAIL because confirmation is not implemented.

- [ ] **Step 5: Implement locked transactional confirmation**

Inside one transaction, lock batch then project; verify creator, pending status,
expiry, tenant, active customer, and writable project. Revalidate the stored
canonical payload against current rows. Upsert via `BusinessProcessService` or
`AssetService` with row-level audit disabled. Mark batch applied and write one
summary audit before commit.

Catch unique/stale conflicts as a stable domain conflict; do not partially
apply or silently regenerate the preview.

- [ ] **Step 6: Add confirm route and run focused tests**

```text
POST /organizations/{organization}/projects/{project}/imports/{batch}/confirm register-imports.confirm
```

Run Task 5 tests plus `git diff --check`. Expected: PASS.

- [ ] **Step 7: Commit Task 5**

```bash
git add app/Services/Imports app/Http/Requests/Imports app/Http/Controllers/RegisterImportController.php routes/web.php tests/Feature/Imports
git commit -m "feat: atomically import processes and assets"
```

---

### Task 6: Manual dependency workflow, cycle prevention, and traversal

**Files:**
- Create: `app/Data/Dependencies/DependencyNode.php`
- Create: `app/Data/Dependencies/TraversalHit.php`
- Modify: `app/Services/Dependencies/DependencyCycleDetector.php`
- Create: `app/Services/Dependencies/DependencyGraph.php`
- Create: `app/Services/Dependencies/DependencyService.php`
- Create: `app/Policies/DependencyEdgePolicy.php`
- Create: `app/Http/Requests/Dependencies/StoreDependencyRequest.php`
- Create: `app/Http/Requests/Dependencies/UpdateDependencyRequest.php`
- Create: `app/Http/Requests/Dependencies/ChangeDependencyStatusRequest.php`
- Create: `app/Http/Controllers/DependencyController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Dependencies/DependencyWorkflowTest.php`
- Test: `tests/Feature/Dependencies/DependencyTraversalTest.php`
- Test: `tests/Feature/Dependencies/DependencyAuthorizationTest.php`
- Test: `tests/Feature/Dependencies/DependencyAuditTest.php`

**Interfaces:**
- Produces: `DependencyService::create/update/changeStatus`, `DependencyGraph::dependencies`, `dependents`, and `affectedProcesses` returning `TraversalHit[]`.

- [ ] **Step 1: Write failing workflow and graph-rule tests**

Cover all three allowed shapes, asset-to-process, self-edge, duplicate active
edge, inactive endpoints, endpoint edit rejection, metadata update, deactivate,
reactivate, and cycles of length 2, 3, and mixed process/asset paths. Include a
project-row lock assertion or concurrent transaction seam proving graph writes
serialize on the project.

- [ ] **Step 2: Write failing deterministic traversal tests**

Build branched graphs and assert direct/transitive dependencies and dependents,
shortest depth, breadth-first order then stable type/key order within depth,
deduplication, inactive exclusion, optional historical inclusion, affected
processes, project scoping, and termination when manually supplied legacy data
contains a cycle.

```php
$hits = app(DependencyGraph::class)->dependencies(
    $project,
    DependencyNode::process($root),
    transitive: true,
);

$this->assertSame(['APP-1:1', 'DB-1:2'], array_map(
    fn (TraversalHit $hit) => $hit->node->key.':'.$hit->depth,
    $hits,
));
```

- [ ] **Step 3: Write failing authorization and redacted-audit tests**

Use the established tenant matrix. Assert endpoint substitution returns 404,
read-only states return 403, graph reads remain available historically, audit
contains endpoint IDs/keys, importance and changed-field names only, reason and
names are absent, and audit failure rolls back.

- [ ] **Step 4: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Dependencies`

Expected: FAIL because graph services, policy, requests, and routes do not exist.

- [ ] **Step 5: Implement typed nodes, cycle detection, workflow, and traversal**

`DependencyNode` is a readonly DTO with `type`, `id`, `projectId`, and `key`.
`TraversalHit` adds `depth` and `importance`. Validate endpoint types before
queries. Lock project before checking reachability and writing. Fetch adjacency
in bounded batched queries by frontier rather than one query per node. Track
visited typed IDs and cap depth/nodes at the project's actual active node count.

Creating an already inactive endpoint pair may reactivate that exact edge after
cycle validation; it must not create a second historical duplicate. Endpoint
changes are rejected by update requests.

- [ ] **Step 6: Add nested routes and controller**

```text
POST  /dependencies                     dependencies.store
PUT   /dependencies/{dependency}        dependencies.update
PATCH /dependencies/{dependency}/status dependencies.status
GET   /dependencies/{type}/{key}/graph  dependencies.graph
```

Graph query accepts `direction=dependencies|dependents|affected_processes`,
`transitive=true|false`, and `include_inactive=false|true`; validate every value
server-side and never place names, reasons, or owner data in the URL.

- [ ] **Step 7: Run focused tests and commit**

Run Task 6 tests and `git diff --check`. Expected: PASS.

```bash
git add app/Data/Dependencies app/Services/Dependencies app/Policies app/Http/Requests/Dependencies app/Http/Controllers/DependencyController.php routes/web.php tests/Feature/Dependencies
git commit -m "feat: manage and traverse project dependencies"
```

---

### Task 7: Atomic dependency CSV confirmation

**Files:**
- Modify: `app/Services/Imports/RegisterImportPreviewer.php`
- Modify: `app/Services/Imports/RegisterImportConfirmer.php`
- Modify: `app/Services/Dependencies/DependencyCycleDetector.php`
- Test: `tests/Feature/Imports/DependencyImportTest.php`
- Test: `tests/Feature/Imports/DependencyImportAtomicityTest.php`

**Interfaces:**
- Extends the Task 5 `confirm()` interface to dependency batches without changing controller or route contracts.

- [ ] **Step 1: Write failing dependency-preview/confirm tests**

Cover all endpoint shapes, normalized key resolution, new/changed/unchanged
edges, metadata updates, deactivate/reactivate, missing CSV edge unchanged,
unknown/inactive endpoint, forbidden shape, duplicate normalized pair, self
edge, cycle formed within the file, cycle formed with existing edges, and a
valid topologically unordered file.

- [ ] **Step 2: Write failing atomicity and concurrency tests**

Assert one invalid edge rejects the whole batch, endpoint/project changes after
preview force revalidation failure, competing graph change cannot interleave
past the project lock, audit failure rolls back every edge, and repeated
confirmation remains idempotent.

- [ ] **Step 3: Run focused tests and verify RED**

Run: `php artisan test tests/Feature/Imports/DependencyImportTest.php tests/Feature/Imports/DependencyImportAtomicityTest.php`

Expected: FAIL because dependency confirmation is not connected.

- [ ] **Step 4: Implement one-shot graph validation and edge upsert**

Resolve all endpoints inside the locked project. Construct the final active
edge set after applying every CSV row while leaving omitted edges unchanged.
Run one cycle check over that complete set before any edge write. Then call
`DependencyService` batch methods with per-edge audit disabled, mark the batch
applied, and write one redacted summary audit in the same transaction.

- [ ] **Step 5: Run focused tests and commit**

Run Task 7 tests plus the Task 6 traversal tests and `git diff --check`.
Expected: PASS.

```bash
git add app/Services/Imports app/Services/Dependencies tests/Feature/Imports tests/Feature/Dependencies
git commit -m "feat: atomically import dependency graphs"
```

---

### Task 8: Project registers, CSV preview UI, and traversal UI

**Files:**
- Create: `app/Http/Controllers/BusinessProcessRegisterController.php`
- Create: `app/Http/Controllers/AssetRegisterController.php`
- Create: `app/Http/Controllers/DependencyRegisterController.php`
- Create: `app/Services/Registers/RegisterPresenter.php`
- Create: `resources/js/types/registers.ts`
- Create: `resources/js/components/RegisterImportPanel.vue`
- Create: `resources/js/components/RegisterImportPreview.vue`
- Create: `resources/js/components/DependencyTraversalPanel.vue`
- Create: `resources/js/pages/processes/Index.vue`
- Create: `resources/js/pages/assets/Index.vue`
- Create: `resources/js/pages/dependencies/Index.vue`
- Modify: `resources/js/components/ProjectWorkNavigation.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Registers/RegisterPagesTest.php`
- Test: `tests/Feature/Imports/RegisterImportPageTest.php`
- Test: `tests/Feature/Dependencies/DependencyPageTest.php`

**Interfaces:**
- Produces: named index routes `processes.index`, `assets.index`, `dependencies.index`; safe Inertia props consumed by typed Vue pages.

- [ ] **Step 1: Write failing safe-prop and page-contract tests**

Assert search/state/type/importance filters; pagination; stable URLs and nested
actions; create/edit/status capabilities; read-only history; import upload and
preview URLs; bounded preview rows/errors; expiry/confirmation capability;
dependency traversal hits; and nav links. Assert props omit owner email unless
needed in the authorized edit form and always omit raw payload, CSV hash,
creator IDs, server paths, audit data, and unrelated project records.

- [ ] **Step 2: Write failing UI authorization and substitution tests**

Cover the internal-role matrix, inactive customer/project states, cross-project
process/asset/edge/batch IDs, and a batch owned by another internal user.

- [ ] **Step 3: Run page tests and verify RED**

Run: `php artisan test tests/Feature/Registers/RegisterPagesTest.php tests/Feature/Imports/RegisterImportPageTest.php tests/Feature/Dependencies/DependencyPageTest.php`

Expected: FAIL because register pages and presenters do not exist.

- [ ] **Step 4: Implement register presenters and controllers**

Use server-side validated filters and paginated eager-loaded queries. Owner
name/email search is client-only so personal data never enters query strings;
server URLs may filter only stable key, state, asset type, endpoint type, and
importance. Serialize only explicit allowlisted fields and capability booleans.

- [ ] **Step 5: Implement typed Vue pages and isolated forms**

Each page uses separate `useForm` instances for manual create/edit/status and
CSV preview/confirmation. `RegisterImportPanel` accepts only `.csv`, displays
upload progress and field errors, then renders `RegisterImportPreview` with
new/changed/unchanged/invalid counts and at most 200 rows. It must not put file
or row values into GET parameters.

`DependencyTraversalPanel` displays a selected stable key, direction controls,
direct/transitive choice, and a table of type, key, name, shortest depth, and
importance. No visualization dependency is added. All controls disappear when
capabilities are false while history remains readable.

- [ ] **Step 6: Update project navigation and add index routes**

Add `Prozesse`, `Assets`, and `Abhängigkeiten` to
`ProjectWorkNavigation.vue`. Add GET index routes beside existing assessment,
evidence, finding, and measure registers.

- [ ] **Step 7: Run focused frontend and page checks**

Run:

```bash
php artisan test tests/Feature/Registers/RegisterPagesTest.php tests/Feature/Imports/RegisterImportPageTest.php tests/Feature/Dependencies/DependencyPageTest.php
npm run lint:check
npm run format:check
npm run types:check
git diff --check
```

Expected: PASS.

- [ ] **Step 8: Commit Task 8**

```bash
git add app/Http/Controllers app/Services/Registers resources/js/types resources/js/components resources/js/pages routes/web.php tests/Feature/Registers tests/Feature/Imports tests/Feature/Dependencies
git commit -m "feat: add process asset and dependency workspace"
```

---

### Task 9: Slice 5 security gate, operations, full CI, review, and PR

**Files:**
- Modify: `README.md`
- Modify: `.github/workflows/ci.yml` only if the existing gate misses a required command
- Test: all Slice 5 and existing tests.

**Interfaces:**
- Consumes: every prior task and the repository CI workflow.
- Produces: documented CSV contracts, operational cleanup instructions, complete verification evidence, reviewed branch, and a PR targeting `main`.

- [ ] **Step 1: Run a branch-wide security regression scan**

Review the complete diff from merge base `decbd3ed39869cfb77eb46730149ed7844c0e984`
for tenant substitution, raw payload/PII exposure, CSV injection, memory bounds,
batch replay, stale preview, partial writes, project locking, cycle races,
inactive traversal, audit ownership/redaction, and migration rollback. Add a
focused test first for every concrete missing behavior, observe the intended
failure, then make the smallest correction.

- [ ] **Step 2: Document CSV formats and operations**

Add README sections containing the three exact headers, UTF-8/delimiter/size/
row/formula rules, preview/confirm behavior, omission semantics, 30-minute
expiry, daily `register-imports:purge`, no original-file retention, and the
future API-ready service boundary. Do not imply that an external API exists.

- [ ] **Step 3: Run all locally available verification**

Run:

```bash
composer test
npm run lint:check
npm run format:check
npm run types:check
npm run build
docker compose config --quiet
git diff --check
```

Expected: PASS. If local PHP or Docker daemon is unavailable, record the exact
unavailable command and require its matching GitHub Actions step on final HEAD.

- [ ] **Step 4: Commit final documentation and gate fixes**

```bash
git add README.md .github/workflows/ci.yml app database resources routes tests
git commit -m "docs: finalize slice 5 register operations"
```

- [ ] **Step 5: Push and require fresh CI**

```bash
git push -u origin feat/slice-05-processes-assets-dependencies
```

Require backend formatting/static analysis/tests, reversible migrations and
starter catalog, frontend lint/format/types/build, and Docker configuration to
pass on final HEAD.

- [ ] **Step 6: Request independent whole-branch review and fix findings test-first**

Review `decbd3ed39869cfb77eb46730149ed7844c0e984..HEAD` against the Slice 5
spec. Classify findings as Critical, Important, or Minor. Fix every accepted
Critical/Important defect with a failing regression test and one bounded fix
wave; run fresh CI and request scoped re-review. Defer only explicitly recorded
non-security Minors under the user's lean-testing instruction.

- [ ] **Step 7: Create or update the pull request without merging**

Use title `Slice 5: Prozesse, Assets und Abhängigkeiten`. The body summarizes
manual registers, CSV preview/import, atomicity, graph constraints/traversal,
tenant boundaries, audit redaction, test counts, and the final green CI URL.
Target `main`; do not merge without an explicit user request.
