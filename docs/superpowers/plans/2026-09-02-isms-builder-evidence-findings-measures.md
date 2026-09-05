# Evidence, Findings & Measures Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add secure immutable evidence storage, traceable finding decisions, and multi-measure remediation workflows to project assessments.

**Architecture:** Evidence, findings, and measures are separate relational modules behind focused services and policies. Evidence bytes live on a private Laravel disk while PostgreSQL stores immutable metadata, composite tenant constraints, workflow state, and redacted audit events; Vue/Inertia presents both question-context controls and project-wide registers.

**Tech Stack:** Laravel 13, PHP 8.4 with `ext-zip`, Vue 3, TypeScript, Inertia 3, PostgreSQL 18, Laravel Filesystem, Docker Compose, PHPUnit, Larastan, Pint, Vite Plus.

**Spec:** `docs/superpowers/specs/2026-09-02-isms-builder-evidence-findings-measures-design.md`

## Global Constraints

- Evidence uses the private `evidence` Laravel disk rooted at `storage/app/private/evidence`; never expose a public storage URL.
- Accept PDF, PNG, JPEG, TXT, CSV, DOCX, XLSX, and ZIP up to exactly 50 MiB; reject all other extension/MIME pairs.
- User-supplied ZIP files allow at most 200 regular files and 250 MiB uncompressed total and reject encryption, nesting, traversal, symlinks, executables, scripts, macro-enabled Office files, installers, and disk images.
- Evidence original bytes and immutable metadata are never overwritten or physically deleted by Slice 4.
- Only active internal `admin` and `consultant` users can access Slice 4 resources.
- Writes require an active customer and a project in `draft` or `active`; completed, archived, and inactive-customer contexts are read-only.
- Every nested resource is checked against organization, project, assessment, and parent ownership before file access or writes.
- Security-sensitive state changes and their redacted audit event share one database transaction; uploaded objects receive compensating cleanup on failure.
- Audit context never contains filenames, hashes, file content, answer content, descriptions, notes, names, email addresses, or cancellation reasons.
- All migrations have complete `down()` behavior, including removal of PostgreSQL triggers and partial/composite indexes.
- No workflow decision depends on AI or UI-only authorization.

---

### Task 1: Evidence, finding, measure, and link schema

**Files:**
- Create: `app/Enums/EvidenceReviewStatus.php`
- Create: `app/Enums/FindingSeverity.php`
- Create: `app/Enums/FindingStatus.php`
- Create: `app/Enums/MeasurePriority.php`
- Create: `app/Enums/MeasureStatus.php`
- Create: `app/Models/EvidenceFile.php`
- Create: `app/Models/Finding.php`
- Create: `app/Models/Measure.php`
- Create: `database/factories/EvidenceFileFactory.php`
- Create: `database/factories/FindingFactory.php`
- Create: `database/factories/MeasureFactory.php`
- Create: `database/migrations/2026_09_02_040000_create_evidence_files_table.php`
- Create: `database/migrations/2026_09_02_041000_create_findings_table.php`
- Create: `database/migrations/2026_09_02_042000_create_measures_table.php`
- Create: `database/migrations/2026_09_02_043000_create_evidence_links_tables.php`
- Modify: `app/Models/IsmsProject.php`
- Modify: `app/Models/ProjectAssessment.php`
- Modify: `app/Models/AssessmentQuestion.php`
- Test: `tests/Feature/WorkItems/WorkItemSchemaTest.php`

**Interfaces:**
- Consumes: existing UUID project, assessment, snapshot-question, and user primary keys.
- Produces: `EvidenceFile`, `Finding`, and `Measure` models; enum-backed `status`, `severity`, and `priority`; project-scoped relationships used by every later task.

- [ ] **Step 1: Write the failing schema and constraint tests**

Create `WorkItemSchemaTest` with `RefreshDatabase`. Assert every specified column exists, factories persist enum casts, `(project_id, sha256)` rejects duplicates in one project but permits the same digest in another, cross-project composite finding/measure/link inserts throw `QueryException`, and two active findings for one snapshot question violate the partial unique index while rejected history remains insertable.

```php
public function test_evidence_digest_is_unique_only_inside_one_project(): void
{
    $project = IsmsProject::factory()->create();
    EvidenceFile::factory()->for($project)->create(['sha256' => str_repeat('a', 64)]);
    EvidenceFile::factory()->for(IsmsProject::factory()->create())->create([
        'sha256' => str_repeat('a', 64),
    ]);

    $this->expectException(QueryException::class);
    EvidenceFile::factory()->for($project)->create(['sha256' => str_repeat('a', 64)]);
}
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `php artisan test tests/Feature/WorkItems/WorkItemSchemaTest.php`

Expected: FAIL because the enums, models, factories, and tables do not exist.

- [ ] **Step 3: Add enums, models, migrations, and relationships**

Use string-backed enums with these exact values:

```php
enum EvidenceReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Verified = 'verified';
    case Rejected = 'rejected';
}

enum FindingSeverity: string { case Low = 'low'; case Medium = 'medium'; case High = 'high'; case Critical = 'critical'; }
enum FindingStatus: string { case Proposed = 'proposed'; case Accepted = 'accepted'; case Rejected = 'rejected'; case Closed = 'closed'; }
enum MeasurePriority: string { case Low = 'low'; case Medium = 'medium'; case High = 'high'; case Critical = 'critical'; }
enum MeasureStatus: string { case Planned = 'planned'; case InProgress = 'in_progress'; case Blocked = 'blocked'; case Completed = 'completed'; case Cancelled = 'cancelled'; }
```

Create UUID tables with the fields and foreign keys from the spec. Add supporting unique constraints `(id, project_id)` to projects, assessments, evidence files, and findings and `(id, project_assessment_id)` to snapshot questions. Build composite foreign keys so `findings`, `measures`, `evidence_question_links`, and `evidence_finding_links` cannot cross project or assessment boundaries. Add this partial index:

```php
DB::statement("CREATE UNIQUE INDEX findings_one_active_per_question
    ON findings (assessment_question_id)
    WHERE status IN ('proposed', 'accepted')");
```

Add a PostgreSQL trigger that raises `evidence original metadata is immutable` when an update changes `storage_path`, `original_name`, `mime_type`, `file_kind`, `size_bytes`, `sha256`, `uploaded_by`, or `uploaded_at`. Drop the trigger function and partial index explicitly in `down()` before dropping tables.

- [ ] **Step 4: Run schema tests and migrations both ways**

Run:

```bash
php artisan test tests/Feature/WorkItems/WorkItemSchemaTest.php
php artisan migrate:rollback --force
php artisan migrate --force
```

Expected: PASS; rollback removes all four Slice 4 tables and migration recreates them.

- [ ] **Step 5: Commit the schema increment**

```bash
git add app/Enums app/Models app/Models/IsmsProject.php app/Models/ProjectAssessment.php app/Models/AssessmentQuestion.php database/factories database/migrations tests/Feature/WorkItems/WorkItemSchemaTest.php
git commit -m "feat: add evidence finding and measure domain schema"
```

---

### Task 2: Private disk, upload limits, file-type validation, and ZIP inspection

**Files:**
- Create: `app/Services/Evidence/ValidatedEvidenceFile.php`
- Create: `app/Services/Evidence/EvidenceFileValidator.php`
- Create: `app/Services/Evidence/ZipArchiveInspector.php`
- Create: `.docker/php/uploads.ini`
- Modify: `config/filesystems.php`
- Modify: `Dockerfile`
- Modify: `compose.yaml`
- Modify: `.docker/nginx/default.conf`
- Modify: `.github/workflows/ci.yml`
- Test: `tests/Unit/Evidence/EvidenceFileValidatorTest.php`
- Test: `tests/Unit/Evidence/ZipArchiveInspectorTest.php`

**Interfaces:**
- Consumes: `UploadedFile` and a readable local temporary path.
- Produces: `EvidenceFileValidator::validate(UploadedFile $file): ValidatedEvidenceFile` and `ZipArchiveInspector::assertSafe(string $path): void`; both throw `ValidationException` with a stable `file` error on rejection.

- [ ] **Step 1: Write failing table-driven file validation tests**

Generate real temporary fixtures in the tests: minimal PDF signature, PNG and JPEG images, UTF-8 TXT/CSV, ZIP-backed DOCX/XLSX packages, and user ZIP files. Assert each approved extension/MIME pair returns the detected MIME and normalized kind. Assert 50 MiB succeeds, 50 MiB plus one byte fails, mismatched extensions fail, and executable/archive aliases fail.

```php
#[DataProvider('approvedFiles')]
public function test_approved_file_is_normalized(string $name, string $contents, string $kind): void
{
    $file = UploadedFile::fake()->createWithContent($name, $contents);

    $validated = app(EvidenceFileValidator::class)->validate($file);

    $this->assertSame($kind, $validated->kind);
    $this->assertSame(strlen($contents), $validated->sizeBytes);
}
```

- [ ] **Step 2: Write failing ZIP safety tests**

Build archives with `ZipArchive` inside a temporary test directory. Cover 200 versus 201 entries, exactly 250 MiB metadata versus one byte more, encrypted entries, `../escape.txt`, absolute and drive-qualified names, Unix symlink attributes, `.zip` nesting, `.php`, `.sh`, `.exe`, `.msi`, `.iso`, `.docm`, and `.xlsm`. Test the NUL-path guard directly with the inspector's entry-name validation because libzip will not create such an entry. Assert ordinary directories and safe documents pass and no extraction directory is created.

```php
public function test_nested_archive_is_rejected_without_extraction(): void
{
    $path = $this->zip(['nested.zip' => 'PK\x03\x04']);

    $this->expectException(ValidationException::class);
    app(ZipArchiveInspector::class)->assertSafe($path);

    $this->assertDirectoryDoesNotExist($path.'.extracted');
}
```

- [ ] **Step 3: Run validator tests and verify RED**

Run: `php artisan test tests/Unit/Evidence`

Expected: FAIL because the validator, normalized data object, ZIP inspector, and ZIP extension configuration are absent.

- [ ] **Step 4: Implement normalized validation and central-directory ZIP checks**

`ValidatedEvidenceFile` is a readonly data object:

```php
final readonly class ValidatedEvidenceFile
{
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public string $kind,
        public int $sizeBytes,
        public string $temporaryPath,
    ) {}
}
```

In `EvidenceFileValidator`, use `UploadedFile::getSize()`, `getPathname()`, `getClientOriginalExtension()`, and Symfony/Laravel MIME detection. Keep the extension-to-MIME allowlist private and exact. Call `ZipArchiveInspector` only for normalized kind `zip`. Return German validation messages without detector or path details.

In `ZipArchiveInspector`, open with `ZipArchive::RDONLY`, iterate `numFiles`, use `statIndex()` sizes and encryption metadata, normalize separators, reject forbidden paths/extensions, and use `getExternalAttributesIndex()` Unix mode bits to reject symlinks and non-regular entries. Never call `extractTo()`.

- [ ] **Step 5: Align runtime limits and ZIP availability**

Create `.docker/php/uploads.ini`:

```ini
upload_max_filesize=50M
post_max_size=52M
max_file_uploads=20
```

Install `zip` beside `pdo_pgsql` in `Dockerfile`, mount the INI read-only into `/usr/local/etc/php/conf.d/uploads.ini`, set Nginx `client_max_body_size 52m`, and add `zip` to the CI PHP extensions list. Add the private disk:

```php
'evidence' => [
    'driver' => 'local',
    'root' => storage_path('app/private/evidence'),
    'visibility' => 'private',
    'throw' => true,
],
```

- [ ] **Step 6: Run focused tests and container configuration checks**

Run:

```bash
php artisan test tests/Unit/Evidence
docker compose config --quiet
```

Expected: PASS; Docker Compose resolves the read-only INI mount and validator tests pass.

- [ ] **Step 7: Commit the secure file boundary**

```bash
git add app/Services/Evidence config/filesystems.php Dockerfile compose.yaml .docker .github/workflows/ci.yml tests/Unit/Evidence
git commit -m "feat: validate private evidence uploads"
```

---

### Task 3: Atomic evidence upload, duplicate reuse, and question linking

**Files:**
- Create: `app/Services/Evidence/EvidenceUploadService.php`
- Create: `app/Services/Evidence/EvidenceLinkService.php`
- Modify: `app/Services/Audit/AuditLogger.php`
- Test: `tests/Feature/Evidence/EvidenceUploadServiceTest.php`
- Test: `tests/Feature/Evidence/EvidenceImmutabilityTest.php`
- Test: `tests/Feature/Evidence/EvidenceAuditTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 2 validator, Laravel disk `evidence`, `AuditLogger`, an applicable `AssessmentQuestion`, and an active internal actor.
- Produces: `EvidenceUploadService::uploadForQuestion(IsmsProject $project, AssessmentQuestion $question, UploadedFile $file, User $actor): EvidenceFile`; `EvidenceLinkService::linkToQuestion(...)` and `linkToFinding(...)` return the persisted evidence.

- [ ] **Step 1: Write failing upload workflow tests**

Use `Storage::fake('evidence')`. Assert a new upload creates one opaque object, exact metadata, SHA-256, one question link, and `evidence.uploaded`. Upload the same bytes for another applicable question and assert one evidence row, one object, two links, and an `evidence.linked` event. Assert a hidden question, foreign question, and storage failure create no metadata or link.

```php
$evidence = app(EvidenceUploadService::class)->uploadForQuestion(
    $project,
    $question,
    UploadedFile::fake()->createWithContent('policy.txt', 'approved policy'),
    $actor,
);

Storage::disk('evidence')->assertExists($evidence->storage_path);
$this->assertSame(hash('sha256', 'approved policy'), $evidence->sha256);
```

- [ ] **Step 2: Write failing cleanup, immutability, and audit-redaction tests**

Bind an `AuditLogger` test double that throws. Assert database rows and stored object are absent after failure. Directly update every immutable column and assert PostgreSQL rejects it. Assert normal review-state updates remain possible. Encode each evidence audit context and assert filename, hash, content, notes, and private path are absent.

- [ ] **Step 3: Run upload tests and verify RED**

Run: `php artisan test tests/Feature/Evidence/EvidenceUploadServiceTest.php tests/Feature/Evidence/EvidenceImmutabilityTest.php tests/Feature/Evidence/EvidenceAuditTest.php`

Expected: FAIL because upload and link services do not exist.

- [ ] **Step 4: Implement streaming storage and compensating cleanup**

Validate first, hash using `hash_file('sha256', $temporaryPath)`, generate `projects/{project-id}/{uuid}` plus the normalized extension, and call `Storage::disk('evidence')->putFileAs()` with private visibility. Wrap metadata, link, and audit creation in `DB::transaction()`. Use `try/catch (Throwable)` around the transaction and delete only the newly created opaque object before rethrowing.

On a duplicate digest, delete the just-written duplicate object, lock and return the existing project evidence, add the missing link idempotently, and emit only `evidence.linked`. Verify applicability with `ApplicabilityEvaluator` before linking.

- [ ] **Step 5: Extend the audit allowlist with identifier-only keys**

Add exact keys `evidence_id`, `finding_id`, `measure_id`, `old_status`, `new_status`, `link_type`, and `failure_kind`. Keep `sha256`, filenames, notes, descriptions, names, and emails disallowed.

- [ ] **Step 6: Run focused upload tests and verify GREEN**

Run: `php artisan test tests/Feature/Evidence/EvidenceUploadServiceTest.php tests/Feature/Evidence/EvidenceImmutabilityTest.php tests/Feature/Evidence/EvidenceAuditTest.php`

Expected: PASS with fake storage empty after every simulated failure.

- [ ] **Step 7: Commit evidence persistence**

```bash
git add app/Services/Evidence app/Services/Audit/AuditLogger.php tests/Feature/Evidence
git commit -m "feat: persist immutable project evidence"
```

---

### Task 4: Evidence HTTP authorization, review, linking, and integrity-checked download

**Files:**
- Create: `app/Policies/EvidenceFilePolicy.php`
- Create: `app/Http/Requests/Evidence/StoreEvidenceRequest.php`
- Create: `app/Http/Requests/Evidence/ReviewEvidenceRequest.php`
- Create: `app/Http/Controllers/EvidenceController.php`
- Create: `app/Services/Evidence/EvidenceReviewService.php`
- Create: `app/Services/Evidence/EvidenceDownloadService.php`
- Create: `app/Exceptions/EvidenceIntegrityException.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Evidence/EvidenceAuthorizationTest.php`
- Test: `tests/Feature/Evidence/EvidenceReviewTest.php`
- Test: `tests/Feature/Evidence/EvidenceDownloadTest.php`

**Interfaces:**
- Consumes: `EvidenceUploadService`, `EvidenceLinkService`, stored evidence metadata, and existing organization/project policy conventions.
- Produces: nested upload/review/download/link routes; `EvidenceReviewService::review(EvidenceFile $evidence, EvidenceReviewStatus $status, ?string $note, User $actor): EvidenceFile`; `EvidenceDownloadService::download(EvidenceFile $evidence): StreamedResponse`.

- [ ] **Step 1: Write negative authorization tests before exposing routes**

Cover guest redirect, customer-organization users, inactive internal users, cross-organization project substitution, foreign assessment question, foreign evidence ID, completed/archived project writes, and inactive-customer writes. Assert internal admins and consultants can read existing history for completed/archived projects and inactive customers. Assert every rejected upload/link/review leaves database and storage unchanged.

- [ ] **Step 2: Write failing review and download tests**

Assert self-review is allowed, rejection requires a note, verification note is optional, repeated decisions are audited once per actual transition, and audit failure rolls review state back. For downloads, assert attachment disposition and `nosniff`; missing, wrong-size, and wrong-hash objects return a generic integrity failure and create `evidence.integrity_failed` without leaking paths.

```php
$this->actingAs($actor)
    ->get($this->evidenceUrl($organization, $project, $evidence).'/download')
    ->assertOk()
    ->assertHeader('content-disposition', 'attachment; filename="policy.txt"')
    ->assertHeader('x-content-type-options', 'nosniff');
```

- [ ] **Step 3: Run HTTP tests and verify RED**

Run: `php artisan test tests/Feature/Evidence/EvidenceAuthorizationTest.php tests/Feature/Evidence/EvidenceReviewTest.php tests/Feature/Evidence/EvidenceDownloadTest.php`

Expected: FAIL because routes, requests, policy, and services do not exist.

- [ ] **Step 4: Implement policy and nested ownership guards**

`EvidenceFilePolicy::view()` requires an active internal admin/consultant and a customer project. `upload()`, `link()`, and `review()` additionally require active customer plus project status `draft` or `active`. Requests abort `404` for any parent mismatch before returning the policy decision.

- [ ] **Step 5: Implement transactional review and verified downloads**

Validate `status` with `Rule::enum(EvidenceReviewStatus::class)` and require `review_note` when rejected. In one transaction lock evidence, update only review fields, and audit old/new status. For download, make a first bounded streaming pass over `Storage::disk('evidence')->readStream()` to verify size and digest with `hash_equals()`, then return a `StreamedResponse` whose callback opens a second storage stream and copies it to the response. This preserves the filesystem abstraction and blocks output until integrity is known. Record an identifier-only integrity event and throw `EvidenceIntegrityException` on mismatch.

- [ ] **Step 6: Register exact nested routes**

```php
Route::post('/organizations/{organization}/projects/{project}/assessment/questions/{question}/evidence', [EvidenceController::class, 'store']);
Route::post('/organizations/{organization}/projects/{project}/evidence/{evidence}/questions/{question}', [EvidenceController::class, 'linkQuestion']);
Route::post('/organizations/{organization}/projects/{project}/findings/{finding}/evidence/{evidence}', [EvidenceController::class, 'linkFinding']);
Route::patch('/organizations/{organization}/projects/{project}/evidence/{evidence}/review', [EvidenceController::class, 'review']);
Route::get('/organizations/{organization}/projects/{project}/evidence/{evidence}/download', [EvidenceController::class, 'download']);
```

- [ ] **Step 7: Run HTTP evidence tests and verify GREEN**

Run: `php artisan test tests/Feature/Evidence`

Expected: PASS, including negative authorization and integrity cases.

- [ ] **Step 8: Commit the secure evidence HTTP workflow**

```bash
git add app/Policies/EvidenceFilePolicy.php app/Http/Requests/Evidence app/Http/Controllers/EvidenceController.php app/Services/Evidence app/Exceptions routes/web.php tests/Feature/Evidence
git commit -m "feat: add reviewed evidence workflow"
```

---

### Task 5: Finding proposal, decision, and closure workflow

**Files:**
- Create: `app/Policies/FindingPolicy.php`
- Create: `app/Services/Findings/FindingWorkflow.php`
- Create: `app/Http/Requests/Findings/StoreFindingRequest.php`
- Create: `app/Http/Requests/Findings/UpdateFindingRequest.php`
- Create: `app/Http/Requests/Findings/DecideFindingRequest.php`
- Create: `app/Http/Controllers/FindingController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Findings/FindingWorkflowTest.php`
- Test: `tests/Feature/Findings/FindingAuthorizationTest.php`
- Test: `tests/Feature/Findings/FindingAuditTest.php`

**Interfaces:**
- Consumes: applicable snapshot questions, `ProjectAnswer::compliance_status`, Task 1 partial unique index, and `AuditLogger`.
- Produces: `FindingWorkflow::propose(...)`, `update(...)`, `decide(...)`, and `close(...)`; nested finding routes used by Task 7 UI and Task 6 measures.

- [ ] **Step 1: Write failing eligibility and lifecycle tests**

Assert only currently applicable questions answered `partial` or `not_fulfilled` can receive a proposal. Assert unanswered, `fulfilled`, `not_applicable`, hidden, foreign, and non-assessment questions fail without writes. Assert title, description, and severity are required and bounded. Assert proposed content can change, accepted/rejected/closed content cannot, rejected decisions require notes, and one active finding per question is enforced.

- [ ] **Step 2: Write failing authorization and audit tests**

Repeat the complete internal-user and nested tenant matrix. Assert proposal, changed field names, accepted/rejected status, and closure are audited once without title, description, decision note, or answer content. Bind a throwing audit logger and assert every workflow change rolls back.

- [ ] **Step 3: Run finding tests and verify RED**

Run: `php artisan test tests/Feature/Findings`

Expected: FAIL because finding policy, requests, controller, and workflow are missing.

- [ ] **Step 4: Implement `FindingWorkflow` with locked transactional transitions**

Use these signatures:

```php
public function propose(IsmsProject $project, AssessmentQuestion $question, array $data, User $actor): Finding;
public function update(Finding $finding, array $data, User $actor): Finding;
public function decide(Finding $finding, FindingStatus $decision, ?string $note, User $actor): Finding;
public function close(Finding $finding, User $actor): Finding;
```

Load/lock parent assessment and answer, call `ApplicabilityEvaluator`, enforce status guards, set actor/timestamps, and record audit inside the same transaction. `decide()` accepts only `accepted` or `rejected`. `close()` requires accepted state, at least one measure, and zero measures outside `completed`/`cancelled`.

- [ ] **Step 5: Add requests, controller actions, and routes**

Create `store`, `update`, `decide`, and `close` endpoints nested below organization/project and assessment question or finding. Requests perform parent mismatch `404` checks first and then authorize through `FindingPolicy`. Redirect back with German success flash messages.

- [ ] **Step 6: Run finding tests and verify GREEN**

Run: `php artisan test tests/Feature/Findings`

Expected: PASS with exactly one audit event per real state change.

- [ ] **Step 7: Commit finding workflow**

```bash
git add app/Policies/FindingPolicy.php app/Services/Findings app/Http/Requests/Findings app/Http/Controllers/FindingController.php routes/web.php tests/Feature/Findings
git commit -m "feat: add assessment finding workflow"
```

---

### Task 6: Multi-measure remediation workflow

**Files:**
- Create: `app/Policies/MeasurePolicy.php`
- Create: `app/Services/Measures/MeasureWorkflow.php`
- Create: `app/Http/Requests/Measures/StoreMeasureRequest.php`
- Create: `app/Http/Requests/Measures/UpdateMeasureRequest.php`
- Create: `app/Http/Requests/Measures/TransitionMeasureRequest.php`
- Create: `app/Http/Controllers/MeasureController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Measures/MeasureWorkflowTest.php`
- Test: `tests/Feature/Measures/MeasureAuthorizationTest.php`
- Test: `tests/Feature/Measures/MeasureAuditTest.php`

**Interfaces:**
- Consumes: accepted findings, enum casts from Task 1, nested tenant checks, and `AuditLogger`.
- Produces: `MeasureWorkflow::create(...)`, `update(...)`, and `transition(...)`; measure routes and terminal-state behavior consumed by finding closure and UI.

- [ ] **Step 1: Write failing creation and validation tests**

Assert multiple measures can belong to one accepted finding. Reject proposed/rejected/closed findings, blank names/titles/descriptions, invalid priority, invalid email, and missing/invalid due date. Allow past dates because imported remediation work can already be overdue. Assert project ID is derived from the finding, never trusted from request data.

- [ ] **Step 2: Write failing transition tests**

Cover every allowed edge and every forbidden edge in the state graph. Assert cancellation requires a reason, completion stores actor/time, nonterminal content can change, and terminal content/status cannot change. Use a data provider:

```php
public static function allowedTransitions(): array
{
    return [
        ['planned', 'in_progress'],
        ['planned', 'cancelled'],
        ['in_progress', 'blocked'],
        ['in_progress', 'completed'],
        ['in_progress', 'cancelled'],
        ['blocked', 'in_progress'],
        ['blocked', 'cancelled'],
    ];
}
```

- [ ] **Step 3: Write failing authorization and redacted audit tests**

Exercise guest, customer user, inactive user, inactive customer, completed/archived project, and cross-project finding/measure substitutions. Assert audit contexts contain only IDs, status values, and changed field names; assert audit failure rolls back creation, edits, and transitions.

- [ ] **Step 4: Run measure tests and verify RED**

Run: `php artisan test tests/Feature/Measures`

Expected: FAIL because measure workflow files do not exist.

- [ ] **Step 5: Implement locked measure workflow and validation**

Use these signatures:

```php
public function create(Finding $finding, array $data, User $actor): Measure;
public function update(Measure $measure, array $data, User $actor): Measure;
public function transition(Measure $measure, MeasureStatus $target, ?string $reason, User $actor): Measure;
```

Keep an explicit `array<MeasureStatus, list<MeasureStatus>>` transition map. Lock before checking current status. Derive `project_id` from the finding. Store cancellation reason only for cancellation and completion actor/time only for completion. Audit inside each transaction.

- [ ] **Step 6: Add requests, controller actions, and nested routes**

Create routes for measure creation under a finding and update/transition under the project. Requests validate `owner_name` to 255 characters, optional `owner_email` with Laravel email validation and 255 maximum, required ISO date, enum priority/status, 10,000-character description/reason bounds, and full parent ownership.

- [ ] **Step 7: Run measure and finding-close tests and verify GREEN**

Run: `php artisan test tests/Feature/Measures tests/Feature/Findings/FindingWorkflowTest.php`

Expected: PASS, including closure only after every measure reaches a terminal state.

- [ ] **Step 8: Commit remediation workflow**

```bash
git add app/Policies/MeasurePolicy.php app/Services/Measures app/Http/Requests/Measures app/Http/Controllers/MeasureController.php routes/web.php tests/Feature/Measures tests/Feature/Findings/FindingWorkflowTest.php
git commit -m "feat: add finding remediation measures"
```

---

### Task 7: Project-wide evidence, finding, and measure registers

**Files:**
- Create: `app/Http/Controllers/EvidenceRegisterController.php`
- Create: `app/Http/Controllers/FindingRegisterController.php`
- Create: `app/Http/Controllers/MeasureRegisterController.php`
- Create: `resources/js/components/ProjectWorkNavigation.vue`
- Create: `resources/js/pages/evidence/Index.vue`
- Create: `resources/js/pages/findings/Index.vue`
- Create: `resources/js/pages/measures/Index.vue`
- Create: `resources/js/types/work-items.ts`
- Modify: `routes/web.php`
- Modify: `resources/js/pages/assessments/Show.vue`
- Test: `tests/Feature/WorkItems/WorkItemRegisterPagesTest.php`

**Interfaces:**
- Consumes: authorized project-scoped Eloquent relationships and all workflows from Tasks 4–6.
- Produces: three Inertia register props and reusable four-tab `ProjectWorkNavigation`.

- [ ] **Step 1: Write failing register page contract tests**

Seed one project with linked evidence, findings in each state, and measures in each state. Assert each page returns only its project resources, includes question/finding deep-link identifiers, exposes `canManage`, and supports validated status filters. Assert cross-project routes are `404`, customer users are forbidden, and completed/archived/inactive-customer pages set `canManage` false.

```php
$this->actingAs($actor)
    ->get($base.'/findings?status=accepted')
    ->assertInertia(fn (Assert $page): Assert => $page
        ->component('findings/Index')
        ->where('filters.status', 'accepted')
        ->has('findings', 1)
        ->where('findings.0.question_key', $question->question_key));
```

- [ ] **Step 2: Run page tests and verify RED**

Run: `php artisan test tests/Feature/WorkItems/WorkItemRegisterPagesTest.php`

Expected: FAIL because register controllers, routes, and pages do not exist.

- [ ] **Step 3: Implement project-scoped register queries**

Select only fields rendered by Vue. Eager-load question/finding labels and count terminal versus total measures. Validate filters against the corresponding enum; invalid filter values return validation errors rather than becoming raw query values. Order evidence newest first, findings by severity then proposal date, and measures by due date then priority.

- [ ] **Step 4: Build typed pages and shared navigation**

Define exact TypeScript unions mirroring PHP enums. `ProjectWorkNavigation` receives organization ID, project ID, and active tab and renders Bewertung, Nachweise, Feststellungen, and Maßnahmen links. Pages show filter controls, state labels, deep links, empty states, read-only indicators, and protected download/action links without embedding private paths or hashes.

- [ ] **Step 5: Run backend page tests and frontend checks**

Run:

```bash
php artisan test tests/Feature/WorkItems/WorkItemRegisterPagesTest.php
npm run lint:check
npm run format:check
npm run types:check
```

Expected: PASS with no untyped page props or formatting changes.

- [ ] **Step 6: Commit register interface**

```bash
git add app/Http/Controllers/*RegisterController.php resources/js/components/ProjectWorkNavigation.vue resources/js/pages/evidence resources/js/pages/findings resources/js/pages/measures resources/js/types/work-items.ts resources/js/pages/assessments/Show.vue routes/web.php tests/Feature/WorkItems/WorkItemRegisterPagesTest.php
git commit -m "feat: add project work item registers"
```

---

### Task 8: Question-context evidence, findings, and measures UI

**Files:**
- Create: `resources/js/components/QuestionEvidencePanel.vue`
- Create: `resources/js/components/QuestionFindingPanel.vue`
- Create: `resources/js/components/FindingMeasurePanel.vue`
- Create: `app/Services/WorkItems/QuestionWorkItemPresenter.php`
- Modify: `resources/js/components/AssessmentQuestionCard.vue`
- Modify: `resources/js/pages/assessments/Show.vue`
- Modify: `resources/js/types/assessment.ts`
- Modify: `app/Http/Controllers/AssessmentController.php`
- Test: `tests/Feature/WorkItems/AssessmentWorkItemPageTest.php`

**Interfaces:**
- Consumes: evidence upload/review/download, finding, and measure routes plus Task 7 TypeScript types/navigation.
- Produces: integrated option-A question workspace with server-derived eligibility and permissions.

- [ ] **Step 1: Write failing assessment-page integration tests**

Assert question props include linked evidence review state/download URL, active and historical findings, measure terminal/total counts, `can_upload_evidence`, and `can_propose_finding`. Confirm proposal eligibility is false for fulfilled, unanswered, hidden, and already-active-finding questions. Confirm read-only contexts still show history but no write capability.

- [ ] **Step 2: Run integration test and verify RED**

Run: `php artisan test tests/Feature/WorkItems/AssessmentWorkItemPageTest.php`

Expected: FAIL because `AssessmentController` does not serialize Slice 4 context.

- [ ] **Step 3: Add eager-loaded, server-derived question context**

Extend the assessment query to eager-load evidence links, findings, and measures without N+1 queries. Implement `QuestionWorkItemPresenter::for(AssessmentQuestion $question, bool $canManage): array` and delegate all evidence/finding/measure serialization to it. Compute finding eligibility on the server from applicability, compliance status, active-finding count, organization activity, and project state.

- [ ] **Step 4: Build the three focused Vue panels**

`QuestionEvidencePanel` uploads one file with Inertia progress, links an existing project evidence record, and shows linked files, status, protected download, and review form. `QuestionFindingPanel` shows history, links existing project evidence to a finding, and exposes an explicit proposal form only when eligible. `FindingMeasurePanel` shows accepted-finding progress and supports measure creation/edit/transition forms. Every form renders all returned field errors and one clear success state.

- [ ] **Step 5: Integrate panels without mixing answer form state**

Keep the existing answer `useForm` inside `AssessmentQuestionCard`; each new panel owns a separate form so upload/proposal/measure errors cannot overwrite answer input. Add `ProjectWorkNavigation` to the assessment page and retain category navigation and progress behavior.

- [ ] **Step 6: Run focused page and frontend verification**

Run:

```bash
php artisan test tests/Feature/WorkItems/AssessmentWorkItemPageTest.php tests/Feature/Assessment/AssessmentWorkflowTest.php
npm run lint:check
npm run format:check
npm run types:check
```

Expected: PASS; existing assessment behavior remains unchanged and the integrated panels are type-safe.

- [ ] **Step 7: Commit question-context interface**

```bash
git add app/Http/Controllers/AssessmentController.php app/Services/WorkItems resources/js/components resources/js/pages/assessments/Show.vue resources/js/types tests/Feature/WorkItems/AssessmentWorkItemPageTest.php
git commit -m "feat: integrate evidence and remediation into assessment"
```

---

### Task 9: Slice 4 security gate, documentation, full CI, review, and PR

**Files:**
- Modify: `README.md`
- Modify: `.gitignore`
- Modify: `.github/workflows/ci.yml`
- Test: all Slice 4 and existing tests.

**Interfaces:**
- Consumes: every prior task and the repository CI workflow.
- Produces: reproducible setup instructions, ignored visual-companion state, complete verification evidence, reviewed branch, and a PR targeting `main`.

- [ ] **Step 1: Add final security regression tests found during implementation review**

Review the complete diff against the spec and add focused tests for any missing tenant substitution, redaction, idempotency, cleanup, terminal-state, or integrity failure. Each new regression test must be observed failing for the intended reason before its smallest production fix.

- [ ] **Step 2: Document runtime requirements and ignore design-session state**

Document rebuilding the PHP container for `ext-zip`, the 50 MiB proxy/PHP limits, private evidence persistence, backup expectations for `storage/app/private/evidence`, and the absence of malware scanning. Add `.superpowers/` to `.gitignore` so local visual brainstorming sessions never enter commits.

- [ ] **Step 3: Run the complete local verification available in the environment**

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

Expected: all commands PASS. If local PHP or Docker is unavailable, record the exact unavailable command and require the corresponding GitHub Actions step to pass before completion.

- [ ] **Step 4: Commit final documentation and gate fixes**

```bash
git add README.md .gitignore .github/workflows/ci.yml tests app resources database config Dockerfile compose.yaml .docker
git commit -m "docs: finalize slice 4 security and operations"
```

- [ ] **Step 5: Push the feature branch and require fresh CI**

```bash
git push -u origin feat/slice-04-evidence-findings-measures
```

Expected: GitHub Actions passes backend formatting/static analysis/tests, migration rollback/re-migrate/seed, frontend lint/format/types/build, and PHP ZIP availability on the final commit SHA.

- [ ] **Step 6: Request independent code review and address findings with TDD**

Review from merge base `9aec597f88c21a73d37680f95bd569d91fd000e0` through final feature HEAD. Classify findings by severity. For every accepted defect, add a failing regression test, implement the minimal correction, rerun focused checks, and push a new final CI run.

- [ ] **Step 7: Create or update the pull request without merging `main`**

Use title `Slice 4: Sichere Nachweise, Feststellungen und Maßnahmen`. The body must summarize immutable private evidence, strict ZIP inspection, tenant boundaries, finding/measure workflows, audit redaction, test counts, and the final green CI URL. Target `main`; do not merge on the user's behalf unless explicitly requested.
