# Task 3 report: atomic evidence upload and linking

## Delivered

- Added `EvidenceUploadService` for validated uploads into the private `evidence`
  disk, with SHA-256 derived from the validated temporary file and opaque
  `projects/{project-id}/{uuid}.{normalized-extension}` object names.
- Added `EvidenceLinkService` for idempotent question and finding links. It
  validates actor, customer/project write state, project/assessment ownership,
  and question applicability before question links are written.
- Duplicate uploads coordinate through a locked project row and the existing
  `(project_id, sha256)` unique constraint. The winning row/object is reused;
  only the losing attempt's just-created object is deleted, and a newly added
  link emits `evidence.linked` rather than a second upload event.
- Metadata, link, and audit rows are kept in one transaction. A transaction or
  audit failure rolls back database rows and invokes compensating cleanup only
  for the new object created by that upload attempt. Reused evidence is never
  passed to cleanup.
- Extended the audit context allowlist only with `evidence_id`, `finding_id`,
  `measure_id`, `old_status`, `new_status`, `link_type`, and `failure_kind`.
  Filenames, digests, content, notes, descriptions, names, emails, and private
  paths remain excluded.

## Test-first evidence

Commit `461e4af` (`test: specify atomic evidence upload workflow`) was created
before the production services. It specifies new upload persistence, duplicate
reuse, hidden/foreign question rejection before persistence, storage failure,
audit-failure cleanup, finding-link idempotency, and audit redaction. The
existing Task 1 schema test already exhaustively exercises each immutable
evidence metadata column and mutable review state, so no duplicate immutability
matrix was added here.

## Verification

- Passed: `git diff --check` before both commits and for the staged production
  changes.
- PHP and Composer are unavailable in this worktree environment (`php` is not
  on `PATH`), so the focused PHPUnit command was deliberately not run locally,
  following the requested lean cadence. CI remains the required PHP/PostgreSQL
  gate.

## Commits

- `461e4af test: specify atomic evidence upload workflow`
- `feat: persist immutable project evidence` (this report is committed with the
  production implementation)

## Concerns

The code has not received local PHPUnit or PostgreSQL execution because the
runtime is unavailable. CI should run the focused evidence tests against the
actual private fake disk and PostgreSQL transaction/locking behavior before the
slice is accepted.
