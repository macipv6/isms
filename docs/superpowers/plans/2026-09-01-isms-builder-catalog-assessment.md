# ISMS Builder Catalog & Assessment Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver a versioned starter catalog and a secure, resumable, rule-driven assessment for every ISMS project.

**Architecture:** Published central catalog data is copied transactionally into an immutable per-project snapshot when an assessment starts. Server-side services validate typed answers, evaluate applicability from snapshot rules, and derive progress; Vue/Inertia renders the resulting active question set without owning security or rule decisions.

**Tech Stack:** Laravel 13, PHP 8.4, Eloquent, PostgreSQL 18, PHPUnit 12, Vue 3, TypeScript, Inertia 3, Tailwind 4.

**Spec:** `docs/superpowers/specs/2026-09-01-isms-builder-catalog-assessment-design.md`

## Global Constraints

- Work only on `feat/slice-03-catalog-assessments`, based on merged `main` commit `262ceb3` or later.
- Use strict red-green-refactor cycles; production behavior follows a test that failed for the expected missing-feature reason.
- Backend RED/GREEN evidence may use GitHub Actions because the current host has no PHP runtime and Docker is not running.
- All backend checks run against PostgreSQL 18 in CI.
- Catalog text is internally authored and BSI-oriented; do not claim it is an official or complete BSI catalog.
- Catalog updates never mutate a project's existing snapshot.
- Evidence file upload and storage remain out of scope until Slice 4.
- No authoritative workflow depends on AI.
- Server policies and ownership checks are authoritative; Vue visibility is not a security control.
- Audit events never contain answer values, comments, or other free text.
- Every migration is reversible.
- Full backend and frontend CI must pass before merge.

---

### Task 1: Catalog schema, domain types, and starter catalog

**Files:**
- Create: `app/Enums/AnswerType.php`
- Create: `app/Enums/CatalogStatus.php`
- Create: `app/Enums/RuleAction.php`
- Create: `app/Enums/RuleOperator.php`
- Create: `app/Models/Framework.php`
- Create: `app/Models/CatalogVersion.php`
- Create: `app/Models/QuestionCategory.php`
- Create: `app/Models/CatalogQuestion.php`
- Create: `app/Models/QuestionOption.php`
- Create: `app/Models/QuestionRule.php`
- Create: `database/migrations/2026_09_01_030000_create_assessment_catalog_tables.php`
- Create: `database/seeders/AssessmentCatalogSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Test: `tests/Feature/Assessment/CatalogSchemaTest.php`
- Test: `tests/Feature/Assessment/StarterCatalogTest.php`

**Interfaces:**
- Produces: `CatalogVersion::publishedForFramework(string $frameworkKey): self`
- Produces: ordered `CatalogVersion->categories->questions` relations with options and outgoing rules.
- Produces: the published `BSI` catalog version `2026.1` with approximately 20 internally authored questions.

- [ ] **Step 1: Write failing schema tests**

  Add `CatalogSchemaTest` assertions for the six tables, UUID keys, foreign keys, unique `(framework_id, version)`, unique `(catalog_version_id, key)` categories, unique `(catalog_version_id, question_key)` questions, unique `(question_id, value)` options, and reversible migration behavior. Add a model test that calls `CatalogVersion::publishedForFramework('BSI')` and expects the published record rather than a draft.

- [ ] **Step 2: Verify RED in CI**

  Commit only the tests, push the branch, and inspect the CI failure. Expected: missing catalog tables/classes; no syntax or bootstrap failure.

- [ ] **Step 3: Add minimal catalog migration, enums, and models**

  Use string columns cast to backed enums. Keep `expected_value` as JSON. Add ordered relationships and this query contract:

  ```php
  public static function publishedForFramework(string $frameworkKey): self
  {
      return self::query()
          ->where('status', CatalogStatus::Published)
          ->whereHas('framework', fn (Builder $query) => $query->where('key', $frameworkKey))
          ->latest('published_at')
          ->sole();
  }
  ```

- [ ] **Step 4: Verify schema GREEN**

  Push the implementation and require `CatalogSchemaTest` plus the existing suite to pass in CI.

- [ ] **Step 5: Write failing starter-catalog tests**

  Seed `AssessmentCatalogSeeder` and assert literal behavior: one active BSI framework, one published `2026.1` version, 18–24 questions, at least eight categories, all stable question keys unique, all supported answer types represented, Microsoft 365 and backup trigger rules present, and repeated seeding leaves counts unchanged.

- [ ] **Step 6: Verify starter-catalog RED in CI**

  Expected: seeder class/data missing while schema tests remain green.

- [ ] **Step 7: Implement the idempotent starter catalog**

  Use `updateOrCreate` for framework/version/category/question/option/rule identities. Include representative questions for governance, organization, assets, access, cloud/Microsoft 365, backup/recovery, patching, logging, incident response, suppliers, and BCM. Use original German wording and mark relevant backup questions with `evidence_expected`.

- [ ] **Step 8: Verify starter-catalog GREEN and commit**

  Run the two focused tests and full backend CI. Commit as `feat: add versioned assessment catalog`.

### Task 2: Immutable project snapshot and deterministic rule evaluation

**Files:**
- Create: `app/Enums/AssessmentStatus.php`
- Create: `app/Models/ProjectAssessment.php`
- Create: `app/Models/AssessmentQuestion.php`
- Create: `app/Services/Assessment/AssessmentStarter.php`
- Create: `app/Services/Assessment/ApplicabilityEvaluator.php`
- Create: `database/migrations/2026_09_01_031000_create_project_assessment_snapshot_tables.php`
- Create: `database/factories/ProjectAssessmentFactory.php`
- Modify: `app/Models/IsmsProject.php`
- Test: `tests/Feature/Assessment/AssessmentSnapshotTest.php`
- Test: `tests/Unit/Assessment/ApplicabilityEvaluatorTest.php`

**Interfaces:**
- Produces: `AssessmentStarter::start(IsmsProject $project, User $actor): ProjectAssessment`.
- Produces: `ApplicabilityEvaluator::applicableQuestions(ProjectAssessment $assessment): Collection<int, AssessmentQuestion>`.
- Snapshot `options` and `rules` are JSON arrays with scalar values only.

- [ ] **Step 1: Write failing snapshot tests**

  Seed the starter catalog, start an assessment, and assert: one assessment per project; catalog version and starter are recorded; snapshot count equals active catalog questions; category labels, question text, options, and rules are copied; a second `start()` returns the same assessment; later edits to central catalog wording/options do not alter snapshot JSON or text.

- [ ] **Step 2: Verify snapshot RED in CI**

  Expected: missing assessment tables/classes.

- [ ] **Step 3: Implement snapshot tables, models, and transactional starter**

  Add a unique `project_id`, source catalog traceability, frozen scalar columns, and JSON casts. Lock the project row inside a transaction before resolving or creating the assessment. Eager-load categories, questions, options, and rules to avoid partial snapshots.

- [ ] **Step 4: Verify snapshot GREEN**

  Require `AssessmentSnapshotTest` and existing catalog tests to pass.

- [ ] **Step 5: Write failing rule evaluator tests**

  Build real assessment questions and answers. Assert these literal cases: no rule means visible; unanswered include trigger means hidden; boolean `true` reveals backup details; boolean `false` hides them; `contains` matches a selected value in JSON; all include rules must match; any matching exclude rule hides; answers on hidden questions do not influence whether that same question is returned.

- [ ] **Step 6: Verify rule RED in CI**

  Expected: evaluator missing or returning the wrong active set.

- [ ] **Step 7: Implement minimal deterministic evaluator**

  Read trigger values from persisted answers keyed by snapshot question key. Compare booleans, strings, numbers, and lists without executing expressions. Reject unknown operators/actions with a domain exception rather than guessing.

- [ ] **Step 8: Verify rule GREEN and commit**

  Run focused and full backend CI. Commit as `feat: snapshot project assessments`.

### Task 3: Typed answer persistence and derived progress

**Files:**
- Create: `app/Enums/ComplianceStatus.php`
- Create: `app/Models/ProjectAnswer.php`
- Create: `app/Services/Assessment/AnswerData.php`
- Create: `app/Services/Assessment/AnswerValidator.php`
- Create: `app/Services/Assessment/AnswerWriter.php`
- Create: `app/Services/Assessment/AssessmentProgress.php`
- Create: `database/migrations/2026_09_01_032000_create_project_answers_table.php`
- Modify: `app/Models/ProjectAssessment.php`
- Modify: `app/Models/AssessmentQuestion.php`
- Test: `tests/Feature/Assessment/AnswerPersistenceTest.php`
- Test: `tests/Unit/Assessment/AnswerValidatorTest.php`
- Test: `tests/Feature/Assessment/AssessmentProgressTest.php`

**Interfaces:**
- Consumes: snapshot answer type/options and `ApplicabilityEvaluator` from Task 2.
- Produces: `AnswerWriter::save(ProjectAssessment $assessment, AssessmentQuestion $question, AnswerData $data, User $actor): ProjectAnswer`.
- Produces: `AssessmentProgress::for(ProjectAssessment $assessment): array{answered:int,total:int,percentage:int,categories:list<array{key:string,name:string,answered:int,total:int,percentage:int}>}`.

- [ ] **Step 1: Write failing typed-validation tests**

  Name the break each case catches. Use literal cases for valid/invalid boolean, allowed/unknown single choice, unique allowed multiple choices, trimmed non-empty text, finite number, valid compliance status, and rejection of an answer for an inapplicable question.

- [ ] **Step 2: Verify validator RED in CI**

  Expected: validator and answer DTO missing.

- [ ] **Step 3: Implement typed validator and DTO**

  Normalize scalar values into `answer_value` and lists into `answer_json`. Do not trust client-provided answer type or options. Return Laravel validation errors keyed to `answer`, `compliance_status`, and `comment`.

- [ ] **Step 4: Verify validator GREEN**

  Require unit cases to pass without mocks.

- [ ] **Step 5: Write failing persistence tests**

  Assert one row per `(assessment_id, assessment_question_id)`, actor and timestamp recording, updating rather than duplicating, comment persistence, and preservation of a hidden dependent answer after its trigger changes.

- [ ] **Step 6: Verify persistence RED in CI**

  Expected: answer table/model/writer missing.

- [ ] **Step 7: Implement answer migration, relationships, and writer**

  Authorize applicability before `updateOrCreate`. Set `answered_by` and `answered_at` server-side. Leave reviewer fields nullable and do not expose review writes.

- [ ] **Step 8: Verify persistence GREEN**

  Require persistence, validator, snapshot, and rule tests to pass.

- [ ] **Step 9: Write failing progress tests**

  Assert literal numerator/denominator/percentage values for an empty assessment, partially answered categories, `not_applicable` counted as answered, newly revealed follow-ups increasing the denominator, and newly hidden answered follow-ups leaving the numerator and denominator.

- [ ] **Step 10: Verify progress RED, implement, and verify GREEN**

  Calculate from the evaluator's current question collection and persisted compliance statuses. Define zero-total percentage as `0`. Run focused and full backend CI.

- [ ] **Step 11: Commit**

  Commit as `feat: persist typed assessment answers`.

### Task 4: Secure assessment HTTP workflow and audit trail

**Files:**
- Create: `app/Http/Controllers/AssessmentController.php`
- Create: `app/Http/Controllers/AssessmentAnswerController.php`
- Create: `app/Http/Requests/Assessments/UpdateAssessmentAnswerRequest.php`
- Modify: `app/Policies/IsmsProjectPolicy.php`
- Modify: `app/Services/Audit/AuditLogger.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Assessment/AssessmentAuthorizationTest.php`
- Test: `tests/Feature/Assessment/AssessmentWorkflowTest.php`
- Test: `tests/Feature/Assessment/AssessmentAuditTest.php`
- Test: `tests/Feature/Assessment/AssessmentPagesTest.php`

**Interfaces:**
- Consumes: starter, evaluator, answer writer, and progress service.
- Produces routes:
  - `POST /organizations/{organization}/projects/{project}/assessment`
  - `GET /organizations/{organization}/projects/{project}/assessment`
  - `PUT /organizations/{organization}/projects/{project}/assessment/questions/{question}`

- [ ] **Step 1: Write negative authorization tests first**

  Assert unauthenticated redirect; inactive-user rejection; internal-organization `404`; project-through-wrong-organization `404`; question-through-wrong-project `404`; inactive-customer write forbidden; and no row/audit side effect after every rejected request. Assert active admin and consultant can start/view/save.

- [ ] **Step 2: Verify authorization RED in CI**

  Expected: missing routes. Confirm failures are not caused by a malformed fixture.

- [ ] **Step 3: Add policy abilities, nested ownership guards, and routes**

  Add `startAssessment`, `viewAssessment`, and `answerAssessment` policy methods for active admin/consultant users and active customer projects. Resolve nested ownership before calling services. Keep project editing admin-only.

- [ ] **Step 4: Verify authorization GREEN**

  Run the negative suite and confirm rejected requests have no database changes.

- [ ] **Step 5: Write failing workflow/page tests**

  Assert start redirects to the assessment page; a repeated start is idempotent; show returns `assessments/Show`; props contain frozen catalog version, grouped applicable questions, existing answers, total/category progress, and permissions; save validates and redirects back with a success flash.

- [ ] **Step 6: Verify workflow RED, implement controllers/request, and verify GREEN**

  Serialize only fields required by the page. The request accepts `answer`, `compliance_status`, and `comment`; the server supplies all ownership and actor fields.

- [ ] **Step 7: Write failing audit-redaction tests**

  Assert `assessment.started` occurs once and `assessment.answer_saved` occurs per successful write. Audit context may contain only `project_id`, `catalog_version`, `question_key`, and `changed_fields`; literal secret answer/comment strings must not appear in the JSON context.

- [ ] **Step 8: Verify audit RED, extend allow-list, and verify GREEN**

  Add only `catalog_version` and `question_key` to the audit safe-context list. Never add generic answer or comment keys.

- [ ] **Step 9: Run full backend CI and commit**

  Commit as `feat: add secure assessment workflow`.

### Task 5: Resumable Vue assessment interface

**Files:**
- Create: `resources/js/types/assessment.ts`
- Create: `resources/js/components/AssessmentProgress.vue`
- Create: `resources/js/components/AssessmentQuestionCard.vue`
- Create: `resources/js/pages/assessments/Show.vue`
- Modify: `resources/js/pages/organizations/Show.vue`
- Modify: `app/Http/Controllers/OrganizationController.php`
- Modify: `tests/Feature/Organizations/OrganizationPagesTest.php`
- Modify: `tests/Feature/Assessment/AssessmentPagesTest.php`

**Interfaces:**
- Consumes: grouped question and progress props from Task 4.
- Produces: category navigation and one-question-at-a-time `PUT` saves through Inertia.

- [ ] **Step 1: Extend failing Inertia page-contract tests**

  Assert each project summary contains `assessment_started`, `assessment_url`, and progress when present. Assert assessment page question payloads expose supported answer controls, options, saved values/status/comment, severity, help, and evidence expectation but no central-catalog mutable IDs or other-project data.

- [ ] **Step 2: Verify page-contract RED in CI**

  Expected: missing props/component contract.

- [ ] **Step 3: Implement minimal controller serialization**

  Eager-load assessment relations and calculate progress once per listed project. Keep URLs server-derived or deterministically constructed from already-authorized IDs.

- [ ] **Step 4: Verify page-contract GREEN**

  Require the focused page tests to pass in CI before adding Vue controls.

- [ ] **Step 5: Implement typed Vue components**

  Render one selected category, overall/category progress, help, evidence expectation, type-specific controls, compliance status, comment, and save button. Build a fresh Inertia form per question card and preserve page/category state on validation failure. Do not add evidence upload inputs.

- [ ] **Step 6: Add start/continue controls to project cards**

  `Bewertung starten` posts to the start route for authorized active projects. `Bewertung fortsetzen` links to the existing page. Keep edit controls and assessment controls visually distinct.

- [ ] **Step 7: Run local frontend gates**

  Run `npm run lint:check`, `npm run format:check`, `npm run types:check`, and `npm run build`. Expected: all exit `0` with no warnings introduced by Slice 3.

- [ ] **Step 8: Run full CI and commit**

  Commit as `feat: add resumable assessment interface`.

### Task 6: Security review and final verification

**Files:**
- Modify only files required by failures found in this gate.
- Review: all Slice 3 migrations, policies, controllers, requests, services, serializers, audit context, and Vue controls.

**Interfaces:**
- Produces: a merge-ready Slice 3 branch with traceable verification evidence.

- [ ] **Step 1: Review tenant and mass-assignment boundaries**

  Attempt organization ID, project ID, assessment ID, snapshot-question ID, actor ID, review fields, answer type, options, and audit-context injection. Add a failing regression test before fixing any discovered gap.

- [ ] **Step 2: Review deterministic rule edge cases**

  Mutate trigger values, unknown option values, multiple selections, hidden saved answers, empty catalogs, and repeated assessment starts. Add a failing regression test before each fix.

- [ ] **Step 3: Verify migration rollback**

  In CI or a PostgreSQL container, migrate from the Slice 2 schema through Slice 3, roll back the Slice 3 batch, and migrate again. Expected: no residual Slice 3 tables or failed constraints.

- [ ] **Step 4: Run complete quality gates**

  Require `composer test`, `npm run lint:check`, `npm run format:check`, `npm run types:check`, and `npm run build` to pass in the same CI revision.

- [ ] **Step 5: Inspect final diff and commit state**

  Confirm no `.env`, dependency directory, generated build output, evidence-file code, AI authority, answer content in audit logs, or unrelated user change is included. Confirm the branch is ahead of merged `main` only by Slice 3 commits.

- [ ] **Step 6: Request code review and prepare the PR**

  Review against the approved spec, address findings with RED/GREEN regression tests, then open a PR targeting `main` that summarizes schema, snapshot semantics, rule behavior, authorization, UI, and verification results.
