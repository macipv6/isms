# Slice 3: Catalog & Assessment Engine Design

## Outcome

Slice 3 turns an ISMS project into a resumable assessment. It ships a small,
internally authored starter catalog, freezes the selected catalog version per
project, evaluates deterministic applicability rules, persists answers, and
shows progress in a Vue/Inertia interface.

The slice does not upload or store evidence files. It records only whether a
question expects evidence; file handling belongs to Slice 4.

## Architectural choice

The central catalog is versioned. Starting an assessment copies every question,
option, category label, and rule needed by that project into an immutable
project snapshot. Answers point to snapshot questions, never directly to the
mutable catalog.

This guarantees that publishing or importing a later catalog version cannot
silently change an active assessment. Explicit project migration remains the
responsibility of Slice 8.

The alternatives were rejected for these reasons:

- Live catalog references would let later catalog changes alter existing
  assessments and their progress.
- Full event sourcing would provide more history than Slice 3 needs while
  making ordinary reads and validation unnecessarily complex.

## Catalog model

The catalog consists of:

- `frameworks`: stable metadata for the BSI-oriented framework.
- `catalog_versions`: immutable published releases such as `2026.1`.
- `question_categories`: ordered thematic groups inside a version.
- `catalog_questions`: stable question keys, wording, help, answer type,
  severity, evidence expectation, active state, and order.
- `question_options`: ordered choices and optional scores.
- `question_rules`: deterministic conditions that include or exclude a target
  question based on another question's stored value.

The first release contains approximately 20 representative, internally
authored questions. It is BSI-oriented but is not presented as an official or
complete reproduction of a BSI catalog.

The starter topics are governance, organization, asset management, identity
and access, Microsoft 365/cloud, backup and recovery, patching, logging,
incident response, suppliers, and business continuity.

Supported answer types are:

- `boolean`
- `single_choice`
- `multiple_choice`
- `text`
- `number`

## Project snapshot

An explicit `POST` action starts an assessment. A database transaction creates
one `project_assessments` row and copies the published catalog into
`assessment_questions`. A unique project constraint makes the action
idempotent and prevents competing snapshots.

Each snapshot question stores the frozen category, wording, answer type,
severity, evidence expectation, options, and rules as project-owned data. The
source catalog IDs remain traceability references, not runtime dependencies.

Starting an assessment creates an `assessment.started` audit event containing
the project ID and catalog version, but no question or answer content.

## Rules and applicability

Rules are evaluated only by server-side deterministic code. The initial
operators are `equals`, `not_equals`, and `contains`; the actions are `include`
and `exclude`.

A question with one or more `include` rules is hidden until every include rule
matches. A matching `exclude` rule always hides the question. Questions without
rules are applicable by default.

The initial catalog demonstrates at least these flows:

- Microsoft 365 absent: Microsoft 365 detail questions stay hidden.
- Backups present: frequency, retention, offline copy, restore test, and
  evidence-expectation questions become applicable.

Changing a trigger answer never deletes a dependent answer. Hidden answers are
excluded from progress and omitted from the active question list; if the
question becomes applicable again, its saved answer returns.

## Answers and progress

`project_answers` stores one answer per snapshot question with:

- scalar `answer_value` or structured `answer_json`
- optional consultant comment
- compliance status: `fulfilled`, `partial`, `not_fulfilled`, or
  `not_applicable`
- answering user and timestamp
- nullable reviewer and review timestamp reserved for later workflow

Server-side validation depends on the snapshot answer type and allowed options.
An answer cannot be written for another project, another organization route, or
a currently inapplicable question.

Progress is derived, not manually stored. The denominator is the number of
currently applicable active snapshot questions. The numerator is the number of
those questions with a compliance status. The service returns total and
per-category answered, total, and percentage values.

Saving an answer creates `assessment.answer_saved`. The audit context includes
only the project ID, question key, and names of changed fields. Answer values,
comments, and free text are never copied into audit context.

## Authorization and tenant boundary

Only authenticated, active internal users with the `admin` or `consultant`
role may view and answer assessments for active customer organizations.
Starting an assessment is allowed to both roles because consultants perform the
assessment work. Project settings remain admin-only as defined by Slice 2.

Every route contains organization, project, assessment, and question ownership
checks. Cross-organization and cross-project substitutions return `404` before
any write. Policies remain the authoritative permission layer; hiding controls
in Vue is only presentation.

Inactive organizations retain readable history only where existing Slice 2
policy allows it; assessment start and answer writes are forbidden.

## Interface

The customer project card exposes `Bewertung starten` or `Bewertung fortsetzen`.
The assessment page shows:

- project and catalog-version context
- overall progress
- category progress and navigation
- one thematic category at a time
- visible questions with help, severity, evidence expectation, answer control,
  compliance status, comment, and explicit save action
- clear saved and validation feedback through Inertia

The page is resumable because every answer is saved independently. It does not
render evidence upload controls.

## Verification gates

- Schema, seed catalog, snapshot immutability, rules, validation, progress,
  audit redaction, and authorization have backend tests against PostgreSQL.
- Negative cross-organization and cross-project tests precede UI exposure.
- Migrations have complete `down()` methods.
- Frontend lint, format, type checking, and production build pass.
- Full CI must be green before Slice 3 is proposed for merge.
