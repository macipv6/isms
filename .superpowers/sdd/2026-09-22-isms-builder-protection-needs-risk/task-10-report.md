# Task 10 — Risk and methodology Vue workspaces

Implemented project risk navigation and target workspaces with context initialization and explicit migration preview/confirmation, six safe overview filters, trigger creation/dismissal, threat review/custom threats, scenarios, inherent and residual assessment, treatment fields, finding/measure/evidence links, internal/external decisions, server-derived completion blockers, next cycles and immutable history. Forms have separate Inertia state, field-labelled validation summaries, saved feedback, stale warnings and conflict reload actions. Read-only projects and separation of assessment/decision actors are represented by server capabilities.

Added consultant read-only and admin clone/draft/publish methodology and catalog screens with exact versions, publication dates, source references, matrix and threat counts. Published rows have no edit action. Fixed the existing methodology draft request to validate `frequency_key`, `impact_key`, `risk_key`, matching the publisher's established contract. Preserved BSI CSV import changes from base e16278d.

Presenter extensions use project-scoped references and expose no evidence storage paths, hashes or review notes. Cycle pagination remains 25; target/resource references are paginated at 100 per kind, global versions at 10 and catalog entries at 100. Pinned project versions remain available even outside the latest version selection window. Safe query allowlists cover filter and pagination state; scenario, rationale, person and evidence data are submitted in request bodies. Graph context identifies the source and first edge and marks omitted intermediate traversal with an ellipsis.

Verification:

- `npm run check`: passed; formatting and lint/type-aware checks.
- `npm run types:check`: passed.
- UI-only production build: passed, 665 modules. A temporary Vite config kept Vue, Inertia, Tailwind and Laravel plugins and omitted only the PHP-dependent Wayfinder generator. Original `vite.config.ts` was unchanged; temporary config removed.
- Static PHP contract review checked model columns, policy capabilities, request/publisher keys, workflow statuses, relation selectors and project scoping.
- Added two focused feature contracts: risk workspace/pinned matrix/decision actor separation/filtering/read-only/privacy and methodology/catalog visibility/admin draft edit contract. They were not run: PHP and the Docker runtime are unavailable in this session. No runtime installation was attempted. No full backend-suite success is claimed.

Limits: frontend compilation is not browser interaction verification. Mandatory threat reviews are created by the existing trigger workflow; targets with only a manual trigger show unreviewed threats until a mandatory trigger initializes their review rows. Overview counts and displayed cycle history refer explicitly to the current filtered cycle page; pagination exposes additional history. Server services remain authoritative for mutations and prerequisite revalidation.

## Review follow-up — three P2 corrections

- Assessment component identity now uses cycle and phase only. A separate optimistic-version watcher follows successful link updates without replacing unsaved fields or validation errors. Failed conflict submissions retain their submitted version; the explicit reload action updates the version and clears only the conflict error. Successful assessments also synchronize the expected version.
- Risk presenter returns bounded persisted treatment rationale, counterparty name/email, cessation statement and cessation evidence ID. Treatment forms initialize from those saved fields. Authorized read-only workspaces and cycle history show saved treatment details as text, including a safe evidence-ID reference without private file metadata.
- Catalog publishing is guarded against dirty or processing drafts and active publication; entry selection establishes the selected saved entry as the clean default and cannot overwrite a dirty draft. Successful saves establish new defaults. The methodology publish guard now also blocks draft processing.

Follow-up verification: `npm run check`, `npm run types:check`, `node tests/Frontend/RiskWorkspaceStateTest.mjs`, and UI-only build passed (668 modules). The focused state checks compile the actual SFC setup code and use real Vue reactivity and Inertia `useForm`; they exercise input/error retention through version updates, conflict retention and explicit reload, catalog dirty/processing publication guards and saved treatment initialization. Added a backend page contract for read-only transfer and cessation details and secret evidence-field exclusion, but PHP/Docker remains unavailable, so backend tests were not executed. The temporary build config was removed; original `vite.config.ts` remains unchanged.
