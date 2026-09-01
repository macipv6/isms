# ISMS Builder V1 Implementation Roadmap

> **For agentic workers:** Each numbered slice below gets its own executable implementation plan. Complete and verify one slice before starting the next.

**Goal:** Deliver the approved ISMS Builder V1 as a sequence of independently testable increments instead of one oversized implementation batch.

**Architecture:** Modular Laravel monolith with Vue/Inertia UI, PostgreSQL, Docker, Entra ID authentication, strict organization/project boundaries, and service-layer modules for assessment, evidence, risk, BCM, documents, and AI. Deterministic domain logic remains authoritative; AI is added only after the authoritative workflows are stable.

**Tech Stack:** Laravel 13, PHP 8.4, Vue 3, TypeScript, Inertia 3, Vite 8, Tailwind 4, PostgreSQL 18, Docker Compose, Microsoft Entra ID.

**Spec:** `docs/superpowers/specs/2026-09-01-isms-builder-v1-design.md`

## Delivery order

| Plan | Slice | Independently testable outcome | Depends on |
|---|---|---|---|
| 01 | Foundation | Dockerized app, PostgreSQL, Entra single-tenant login, local allow-list, protected dashboard, immutable audit foundation | — |
| 02 | Organizations & projects | Customer CRUD and ISMS-project CRUD with negative cross-organization authorization tests | 01 |
| 03 | Catalog & assessment engine | Versioned catalog, project snapshots, dynamic rules, resumable assessment UI | 02 |
| 04 | Evidence, findings & measures | Secure evidence upload/hash/linking, review states, finding proposals, measure workflow | 03 |
| 05 | Processes, assets & dependencies | Business-process register, asset register, typed dependency graph and traversal | 02 |
| 06 | Protection needs & risk | CIA protection needs, inheritance/overrides, versioned risk methodology, 200-3 workflow | 03, 04, 05 |
| 07 | BCM/BIA & exercises | BIA impact-over-time, RTO/RPO, dependency checks, continuity strategies, emergency roles, exercises | 04, 05, 06 |
| 08 | Framework/catalog updates | Staging, delta classification, review/publish lifecycle, explicit project migration | 03 |
| 09 | Document engine | Controlled templates, traceable data snapshots, document lifecycle and diff-based regeneration | 04, 06, 07, 08 |
| 10 | AI gateway | Least-context AI adapter, structured draft outputs, missing-information handling, AI audit metadata | 09 |

## Cross-cutting gates

Every slice must satisfy these gates before the next plan begins:

1. `composer test` passes against PostgreSQL.
2. `npm run lint:check`, `npm run format:check`, and `npm run types:check` pass.
3. New organization/project resources have negative authorization tests before being exposed in UI.
4. Migrations are reversible unless the migration intentionally establishes an append-only security primitive; such exceptions must be documented in the migration.
5. No authoritative workflow depends on AI.
6. No UI-only authorization checks are accepted as security controls.
7. Security-sensitive state changes create audit events once the audit foundation exists.
8. A slice is committed only after its focused tests and full CI checks pass.

## Plan boundaries

### Plan 01 — Foundation

Use `docs/superpowers/plans/2026-09-01-isms-builder-foundation.md`.

### Plan 02 — Organizations & projects

Owns customer organizations, project lifecycle, organization/project access policies, and project selector/dashboard. It must not introduce question-catalog concepts.

### Plan 03 — Catalog & assessment engine

Owns framework metadata, catalog/question versions, answer types, rules, project snapshots, answer persistence, assessment progress, and dynamic applicability. It must not own evidence file storage.

### Plan 04 — Evidence, findings & measures

Owns immutable evidence originals, SHA-256 hashes, link tables, verification states, finding proposal/acceptance, and measure workflow. Vendor-specific parsers remain out of scope.

### Plan 05 — Processes, assets & dependencies

Owns business-process and asset registers plus typed dependency edges. It provides graph traversal interfaces consumed by protection-needs and BCM plans.

### Plan 06 — Protection needs & risk

Owns CIA ratings, methodology thresholds, propagated recommendations, justified overrides, risk-analysis triggers, threat selections, inherent/residual risk, treatment, and management acceptance state.

### Plan 07 — BCM/BIA & exercises

Owns impact-over-time records, recovery objectives, resource dependencies, RTO consistency warnings, continuity strategies, emergency organization, playbooks, exercises and test results.

### Plan 08 — Framework/catalog updates

Owns import staging metadata, change classification, mapping impact review, framework/catalog publication state, and explicit project migration with answer re-review classification.

### Plan 09 — Document engine

Owns controlled templates, source-snapshot references, draft/review/approved lifecycle, rendering interfaces, and regeneration diff behavior. Initial rendering target is HTML plus PDF/DOCX adapters behind interfaces; exact renderer is selected inside that plan.

### Plan 10 — AI gateway

Owns provider abstraction, purpose-specific context builders, sensitive-data filtering, server-side prompt templates, structured response schemas, accepted/rejected draft metadata, and AI-specific audit trail. It may call existing domain services but may not write authoritative approvals.
