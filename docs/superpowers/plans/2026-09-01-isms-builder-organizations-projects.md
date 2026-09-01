# Organizations & Projects Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Deliver customer organization CRUD and ISMS-project CRUD with strict organization boundaries, auditability, and a usable consultant UI.

**Architecture:** Extend the existing Laravel/Inertia monolith. Organizations remain the tenant boundary; projects belong to exactly one organization. Authorization is enforced server-side with policies and negative cross-organization tests. Security-sensitive writes emit append-only audit events.

**Tech Stack:** Laravel 13, PHP 8.4, Vue 3, TypeScript, Inertia 3, Vite 8, Tailwind 4, PostgreSQL 18.

**Spec:** Approved ISMS Builder V1 design from the project conversation; roadmap boundary is `docs/superpowers/plans/2026-09-01-isms-builder-v1-roadmap.md` Plan 02.

## Global Constraints

- Do not introduce question-catalog concepts in this slice.
- Every organization/project resource must have negative cross-organization authorization tests before UI exposure.
- No UI-only authorization control counts as a security control.
- Organization and project writes must create audit events.
- Existing Entra authentication and active-user middleware remain authoritative.
- Full CI must pass before merge.

---

### Task 1: Complete organization customer profile

**Files:**
- Create: `database/migrations/2026_09_01_010000_add_customer_profile_to_organizations_table.php`
- Modify: `app/Models/Organization.php`
- Modify: `database/factories/OrganizationFactory.php`
- Test: `tests/Feature/Organizations/OrganizationSchemaTest.php`

**Produces:** organization fields `address`, `contact_name`, `contact_email`, `contact_phone`, `notes` in addition to existing name/slug/industry/employee_count/is_active.

- [ ] Write a failing schema/model test asserting the new nullable columns exist and are mass assignable.
- [ ] Run the focused test and confirm failure because columns are absent.
- [ ] Add reversible migration, fillable fields and factory defaults.
- [ ] Run focused test and full backend tests.
- [ ] Commit `feat: extend organization customer profile`.

### Task 2: Organization authorization and CRUD backend

**Files:**
- Create: `app/Policies/OrganizationPolicy.php`
- Create: `app/Http/Requests/Organizations/StoreOrganizationRequest.php`
- Create: `app/Http/Requests/Organizations/UpdateOrganizationRequest.php`
- Create: `app/Http/Controllers/OrganizationController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Organizations/OrganizationCrudTest.php`
- Test: `tests/Feature/Organizations/OrganizationAuthorizationTest.php`

**Interfaces:**
- Admin may create/update organizations.
- Consultant may list/view active organizations available to the internal tenant but may not mutate customer records unless policy allows admin.
- Organization routes are authenticated and active-user protected.

- [ ] Write failing tests for list/create/update/deactivate and validation.
- [ ] Write failing negative authorization tests showing a consultant cannot mutate and an unrelated organization ID cannot be used to bypass policy.
- [ ] Implement requests, policy, controller and routes minimally.
- [ ] Verify focused tests and backend suite.
- [ ] Commit `feat: add organization management backend`.

### Task 3: Organization audit trail

**Files:**
- Modify: `app/Http/Controllers/OrganizationController.php`
- Test: `tests/Feature/Organizations/OrganizationAuditTest.php`

**Produces audit events:** `organization.created`, `organization.updated`, `organization.deactivated` with organization ID and changed field names, excluding sensitive arbitrary note content from metadata.

- [ ] Write failing audit tests.
- [ ] Verify RED.
- [ ] Emit audit events only after successful persistence.
- [ ] Verify audit tests and append-only audit suite.
- [ ] Commit `feat: audit organization changes`.

### Task 4: ISMS project domain and lifecycle

**Files:**
- Create: `app/Enums/ProjectStatus.php`
- Create: `app/Models/IsmsProject.php`
- Create: `database/factories/IsmsProjectFactory.php`
- Create: `database/migrations/2026_09_01_020000_create_isms_projects_table.php`
- Modify: `app/Models/Organization.php`
- Test: `tests/Feature/Projects/ProjectSchemaTest.php`

**Project fields:** UUID id, organization_id, name, description, framework (`BSI`), approach (`basis_absicherung`), bcm_level (`aufbau_bcms`), status, scope_text, started_at, target_date, completed_at, created_by, timestamps.

- [ ] Write failing model/schema/relationship tests.
- [ ] Verify RED.
- [ ] Implement migration, enum, model, factory, organization relation.
- [ ] Verify focused tests and backend suite.
- [ ] Commit `feat: add ISMS project domain`.

### Task 5: Project authorization, CRUD and cross-organization isolation

**Files:**
- Create: `app/Policies/IsmsProjectPolicy.php`
- Create: `app/Http/Requests/Projects/StoreProjectRequest.php`
- Create: `app/Http/Requests/Projects/UpdateProjectRequest.php`
- Create: `app/Http/Controllers/IsmsProjectController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Projects/ProjectCrudTest.php`
- Test: `tests/Feature/Projects/ProjectAuthorizationTest.php`
- Test: `tests/Feature/Projects/ProjectAuditTest.php`

**Authorization rule:** Every project action resolves project.organization_id server-side; a request may never select or mutate a project through a different organization route. `organization_id` is not accepted as a free update field.

- [ ] Write failing project CRUD/validation tests.
- [ ] Write failing tests for cross-organization route tampering and direct project access.
- [ ] Write failing audit tests for create/update/status changes.
- [ ] Implement policy/controller/requests/routes and audit calls.
- [ ] Verify all focused and backend tests.
- [ ] Commit `feat: add secure ISMS project lifecycle`.

### Task 6: Consultant UI for organizations and projects

**Files:**
- Create: `resources/js/pages/organizations/Index.vue`
- Create: `resources/js/pages/organizations/Create.vue`
- Create: `resources/js/pages/organizations/Show.vue`
- Create: `resources/js/pages/organizations/Edit.vue`
- Create: `resources/js/pages/projects/Create.vue`
- Create: `resources/js/pages/projects/Edit.vue`
- Create: `resources/js/types/organization.ts`
- Create: `resources/js/types/project.ts`
- Modify: `resources/js/pages/Dashboard.vue`
- Modify: `resources/js/layouts/AppLayout.vue`
- Test: `tests/Feature/Organizations/OrganizationPagesTest.php`
- Test: `tests/Feature/Projects/ProjectPagesTest.php`

**UI outcome:** Dashboard links to customer list; customer page shows profile plus projects; admin sees create/edit controls; consultant read-only state is reflected but never relied on for authorization.

- [ ] Write failing Inertia page/prop tests.
- [ ] Verify RED.
- [ ] Implement typed Vue pages and navigation.
- [ ] Run backend page tests plus `npm run lint:check`, `npm run format:check`, `npm run types:check`, `npm run build`.
- [ ] Commit `feat: add organization and project UI`.

### Task 7: Final security and CI gate

- [ ] Run full `composer test` against PostgreSQL.
- [ ] Run frontend lint, format, typecheck and production build.
- [ ] Confirm negative authorization tests cover organization mutation and cross-organization project access.
- [ ] Confirm audit tests cover organization/project writes and token material is never logged.
- [ ] Open a PR from `feat/slice-02-organizations-projects` to `main` with verification evidence.
