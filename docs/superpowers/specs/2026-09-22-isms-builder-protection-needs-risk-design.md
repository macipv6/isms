# Slice 6: Protection Needs & Risk Design

## Outcome

Slice 6 gives each ISMS project a reproducible BSI 200-3-oriented workflow for
protection-needs assessment and risk management. Internal admins and
consultants can assess confidentiality, integrity, and availability for
business processes and assets; inspect inherited recommendations through the
dependency graph; control justified downgrades; review elemental threats;
assess inherent and residual risk; connect findings, measures, and evidence;
and document management decisions.

The workflow is deterministic and authoritative. A project pins immutable
methodology and threat-catalog versions, and completed historical assessment
cycles remain readable after later changes. Slice 6 introduces no AI-based
classification, external API, monetary risk calculation, or automatic catalog
update.

## Standards basis

The default workflow and matrix are based on the official
[BSI Standard 200-3](https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/BSI_Standards/standard_200_3.pdf?__blob=publicationFile&v=2).
The baseline elemental threats come from the official
[IT-Grundschutz Compendium, Edition 2023](https://www.bsi.bund.de/SharedDocs/Downloads/DE/BSI/Grundschutz/IT-GS-Kompendium/IT_Grundschutz_Kompendium_Edition2023.pdf?__blob=publicationFile&v=4).
The application records these exact source editions and supports controlled
organization-specific methodology versions because BSI explicitly permits the
risk matrix to be adapted to institutional needs.

## Architectural choice

The slice is divided into five relational modules with narrow service
interfaces:

- `RiskMethodology` owns published frequency, impact, and risk-matrix versions.
- `ThreatCatalog` owns immutable baseline threat versions and project threats.
- `ProtectionNeed` owns direct CIA ratings, propagated recommendations,
  effective ratings, provenance, and downgrade decisions.
- `RiskAnalysis` owns trigger evaluation, threat review, scenarios, assessment
  cycles, treatment, and links to existing work items.
- `RiskDecision` owns internal and externally documented management decisions.

Controllers authorize and validate transport input, call domain services, and
return Inertia responses. They do not calculate propagation or risk. Services
use explicit process and asset foreign keys instead of generic polymorphic
targets. State transitions and their audit events are transactional.

This is preferred over one large risk record or JSON workflow because the
modules have different lifecycles, permissions, and invalidation rules. It also
keeps them reusable by later BCM and document-engine slices.

## Project risk context

Each project has one `project_risk_contexts` row containing:

- project ID;
- published risk-methodology version ID;
- published threat-catalog version ID;
- initializer and initialization timestamp;
- ordinary timestamps.

`project_risk_context_changes` records every initialization or migration with
the old and new methodology/catalog version IDs, actor, timestamp, and change
kind. Risk assessments also reference their exact methodology version and
project threats retain their exact snapshot origin, so reproduction does not
depend on the current context pointer.

Initialization copies the selected catalog entries into project-scoped threat
rows and pins the exact immutable methodology version. The copy is a project
snapshot: later global publication does not silently alter the project.
Initialization is idempotent, locks the project row, and is allowed only for a
writable project that does not already have a context.

Moving a project to a newer methodology or threat catalog is a separate,
explicit migration action. It creates the new snapshot, records the old and
new version IDs, re-evaluates triggers, and marks affected protection needs and
risk analyses for review. It never rewrites historical assessment cycles or
decisions.

## Versioned risk methodology

`risk_methodologies` identifies a methodology family by a stable key and name.
`risk_methodology_versions` stores a monotonically increasing version number,
status, description, publication metadata, and source reference. Child tables
store ordered frequency levels, impact levels, risk levels, and every cell of
the matrix through foreign keys rather than a JSON document.

The seeded default is a published BSI 200-3 profile with these ordered values:

- frequency: `rare` (at most once in five years), `medium` (once in five
  years to once per year), `frequent` (once per year to once per month), and
  `very_frequent` (several times per month);
- impact: `negligible`, `limited`, `considerable`, and `existential`, using the
  corresponding qualitative descriptions from BSI Standard 200-3;
- risk: `low`, `medium`, `high`, `very_high`.

The default matrix is:

| Impact / frequency | Rare | Medium | Frequent | Very frequent |
|---|---|---|---|---|
| Negligible | Low | Low | Low | Medium |
| Limited | Low | Low | High | High |
| Considerable | Medium | High | Very high | Very high |
| Existential | High | Very high | Very high | Very high |

The matrix is complete: publication fails when any frequency/impact
combination is absent, duplicated, or references an unknown risk level. This
mapping is represented as ordinary version data and is covered by a single
fixture test rather than hard-coded throughout the application.

Draft versions may be edited by internal admins. Publication validates the
complete version and then makes it immutable at service and database level.
An adaptation clones a published version into a new draft version. Published
versions are never edited or deleted, including the BSI default. Consultants
may inspect published versions and use them in projects but may not publish or
replace organization-wide methodology data.

## Versioned threat catalog

`threat_catalogs`, `threat_catalog_versions`, and `threat_catalog_entries`
provide the global baseline. Slice 6 seeds an immutable published BSI elemental
threat catalog containing the 47 entries from the selected IT-Grundschutz
edition. Each entry has a stable code, title, bounded description, affected CIA
objectives, order, source edition, and source reference.

The source edition and import provenance are explicit. Updating to a newer BSI
edition creates a new catalog version; it does not edit the seeded version.
Publication requires unique stable codes and at least one affected CIA
objective for every entry.

`project_threats` holds the project's snapshot. Baseline entries preserve
their source entry ID and copied display fields. Project-specific threats have
no baseline source and require:

- a project-local stable code and title;
- a concrete description and addition rationale;
- at least one affected CIA objective;
- a responsible person's free-form name and optional validated email;
- creator and creation timestamp.

Baseline snapshot threats cannot be edited or removed. A custom threat may be
edited only before it is referenced by a review or analysis. After first use,
a correction creates a replacement row and retires the prior row, preserving
history and marking dependent work for review.

## Protection-needs records

Protection needs use one `protection_needs` row per active target and CIA
objective. A target populates exactly one of `business_process_id` or
`asset_id`, with composite foreign keys binding the target to the project. The
objective is `confidentiality`, `integrity`, or `availability` and the ordered
level is `normal`, `high`, or `very_high`.

Each row stores:

- optional direct level and required direct rationale when directly rated;
- calculated inherited level;
- calculated effective level;
- workflow status and review marker;
- rating actor and timestamp;
- optimistic-lock version and ordinary timestamps.

The workflow status is `draft`, `assessed`, or `approved`. A separate
`needs_review` flag records invalidation without erasing the last approved
value. Draft rows may be incomplete. A target becomes assessed only when all
three objectives have direct values and rationales. Approval confirms the
complete target assessment, subject to any required downgrade decisions.

`protection_need_sources` stores propagation provenance. It records the target
protection need, originating rated process or asset, originating objective
value, and the first dependency edge on the effective path. Only sources tied
for the maximum recommendation need to be stored; recalculation replaces the
current provenance transactionally while history is captured by approval and
audit records.

## Propagation semantics

An active dependency edge `source -> target` means that the source depends on
the target. Effective protection needs propagate from the dependent source to
the supporting target. Propagation uses the maximum value per CIA objective
over the acyclic active dependency graph.

For each target and objective:

1. calculate the maximum effective rating of all active direct dependents;
2. retain the provenance of every direct or transitive source tied for that
   maximum;
3. compare the recommendation with the target's direct rating;
4. use the higher value unless an eligible downgrade has been approved.

A direct value higher than the inherited recommendation is immediately
effective. A direct value lower than inherited `high` or `very_high` is a
downgrade and does not lower the effective value until approved. This includes
`normal` below `high` or `very_high`, and `high` below `very_high`.

Propagation runs in topological order inside a transaction holding the project
row lock. A changed direct value, dependency edge, target lifecycle, or
approved downgrade recalculates only reachable supporting targets. A changed
effective value or maximum-source set marks the target approval and dependent
risk work as needing review. The graph service's cycle guard remains defense in
depth even though Slice 5 rejects cycles.

Inactive processes, assets, or edges do not participate in current
propagation. Their prior ratings and history remain readable. Reactivation
recalculates the affected subgraph before the write completes.

## Downgrade requests and four-eyes control

`protection_need_downgrade_requests` records the target protection need,
inherited recommendation, requested lower direct value, required rationale,
requester, status, and decision metadata. Status is `pending`, `approved`,
`rejected`, `superseded`, or `invalidated`.

A pending request may be approved in either of two ways:

- a different active internal admin or consultant records an internal
  approval; or
- an internal admin or consultant records a documented external management
  decision with decision-maker name, function, date, rationale, and optional
  linked verified evidence.

Internal self-approval is forbidden. For an external decision, the authenticated
internal user is the recorder and the named external person is the decision
maker; these identities are stored and audited separately. An external person
does not require an application account.

A request is invalidated when the direct rating, inherited value, provenance,
target lifecycle, or relevant graph changes. Until a current request is
approved, the higher inherited value remains effective. Ordinary ratings and
increases require no second person.

## Protection-needs CSV import

CSV imports only direct CIA ratings and rationales for existing processes and
assets. The exact case-insensitive headers are:

```text
object_type,object_key,confidentiality,confidentiality_rationale,integrity,integrity_rationale,availability,availability_rationale
```

`object_type` is `process` or `asset`; rating cells are `normal`, `high`, or
`very_high`. Object keys use the existing Slice 5 normalization and resolve
only within the selected project. Every rating requires its corresponding
non-empty rationale.

The existing secure preview-and-confirm import infrastructure is extended with
a `protection_needs` kind. It retains the Slice 5 UTF-8, comma/semicolon,
formula-cell, 5 MiB, 10,000-row, 30-minute expiry, ownership, fingerprint,
single-use, and bounded-preview rules. Preview shows normalized row results and
which rows would create, change, or leave direct ratings unchanged. It also
identifies resulting downgrade approvals as required but cannot approve them.

Confirmation locks the batch and project, revalidates current state, applies
the exact reviewed payload atomically, recalculates the affected graph, and
creates redacted audit events. Any invalid row, stale preview, or failed
recalculation rolls back the entire import. The import cannot create objects,
change inherited values, approve downgrades, create risk scenarios, select
treatments, or record risk acceptance.

The import and manual workflows call the same transport-independent domain
services so a later API can reuse the rules. Slice 6 exposes no external API.

## Risk-analysis triggers

`risk_analysis_triggers` represents why a process or asset requires analysis.
It uses explicit nullable process and asset foreign keys and has type:

- `elevated_protection_need`;
- `insufficient_baseline_coverage`;
- `nonstandard_scenario`;
- `manual`.

The first three types are mandatory; `manual` is optional. Trigger status is
`open`, `covered`, or `superseded`. Mandatory triggers cannot be dismissed.

An elevated-protection trigger is open whenever any current effective CIA
value is `high` or `very_high`. Insufficient baseline coverage is derived from
an accepted, not-closed finding that has been explicitly linked to the target
through `finding_process_links` or `finding_asset_links`. Those new link tables
use composite project foreign keys and do not alter the finding itself. A
nonstandard-scenario trigger is deliberately registered against a target with
a required rationale. Registering it makes the resulting analysis mandatory.
Manual triggers also require a rationale but may be superseded by their creator
while no completed cycle depends on them.

A mandatory trigger becomes covered only when linked to a current completed
risk-analysis cycle. When its source condition ceases, it becomes superseded;
it and its linked analyses remain as history. If the condition returns, the
service reopens or creates a trigger deterministically.

The project completion transition is rejected while any mandatory trigger is
open, linked only to stale work, or otherwise lacks a current completed cycle.
The UI explains the blocking target and trigger without exposing sensitive
scenario text in URLs.

## Threat overview

Every target with a mandatory trigger receives a threat overview. A
`risk_target_threat_reviews` row classifies each active project threat as
`applicable` or `not_applicable`. Not-applicable entries require a bounded
rationale. Applicable entries require at least one current risk analysis for
the target and threat before the overview is complete.

The interface supports filtering and batch navigation but not a blanket
"mark all not applicable" action. A catalog migration adds unreviewed rows for
new threats and marks the overview and completed analyses as needing review.
Retired threats remain visible in the historical snapshot.

## Risk-analysis identity and cycles

`risk_analyses` is the stable identity for one project target, one project
threat, and one concrete scenario. Multiple analyses may exist for the same
target and threat when the scenarios differ. Scenario titles must be unique
case-insensitively for the same target and threat; aggregate or group targets
are not permitted.

Each identity has one or more `risk_analysis_cycles`. A cycle stores:

- monotonically increasing cycle number;
- cause/event/impact scenario fields and affected CIA objectives;
- lifecycle status and review marker;
- creator, updater, completion actor, and timestamps;
- optimistic-lock version.

Statuses are:

- `draft` while the scenario is incomplete;
- `inherent_assessed` after the initial assessment;
- `treatment_in_progress` while treatment or measures are pending;
- `residual_assessed` after a permitted residual assessment;
- `awaiting_decision` when management acceptance is required;
- `completed` when all workflow requirements are satisfied.

Completed cycles are immutable. A material change marks the cycle stale but
does not reopen or overwrite it; reassessment creates the next cycle, seeded
from non-sensitive structured references and explicitly copied scenario fields
for the user to review. Exactly one non-completed current cycle is allowed per
analysis.

## Inherent and residual assessment

`risk_assessments` belongs to a cycle and has phase `inherent` or `residual`,
the selected methodology frequency and impact level IDs, the calculated risk
level ID, required bounded rationale, assessor, and timestamp. A cycle has at
most one assessment for each phase.

The service verifies that the selected levels and matrix cell belong to the
project's pinned methodology. It derives the risk category from the matrix;
clients never submit a category directly. The inherent assessment is performed
before any additional Slice 6 treatment credit.

A residual assessment is allowed only after treatment prerequisites are met:

- `reduce`: every linked measure is `completed` and its completion is still
  current;
- `avoid`: the service records a verified target/scenario cessation statement;
- `transfer`: transfer details and evidence of the effective arrangement are
  recorded;
- `accept`: no reduction is claimed, so residual frequency and impact may not
  be lower than the inherent values without selecting a different treatment.

Planned, in-progress, blocked, or cancelled measures do not reduce residual
risk. Removing a credited measure link, changing a required prerequisite, or
rejecting previously verified supporting evidence marks the cycle stale and
invalidates its decision.

## Treatment and work-item links

`risk_treatments` belongs one-to-one with the current cycle and has action
`avoid`, `reduce`, `transfer`, or `accept`, plus a bounded rationale. Transfer
also records the responsible counterparty as free-form name and optional email
and requires at least one verified evidence link. Avoidance requires a
verification statement. Reduction requires one or more linked measures.

Explicit link tables connect a cycle to:

- existing findings;
- existing measures;
- existing evidence files;
- one or more originating triggers.

All link tables carry project IDs and composite foreign keys so cross-project
links cannot be persisted. Evidence used to support a completed cycle or
external decision must be `verified`. Findings and measures retain their Slice
4 lifecycles; the risk module does not duplicate their text, responsible
person, or status.

Dependency traversal may display upstream and downstream impact context for a
target. That graph information is computed for display and invalidation; it is
not copied into frequency, impact, or scenario fields.

## Management decisions

High or very-high residual risk requires a current management decision before
the cycle can complete. Lower residual risk may complete without a separate
decision. `risk_decisions` stores the cycle, decision status, decision channel,
rationale, decision date, recorder, and either an internal decision-maker user
or external decision-maker name and function. Optional decision evidence uses
an explicit link to verified project evidence.

Decision status is `accepted` or `rejected`. Rejection sends the cycle back to
treatment work; it never counts as risk acceptance. For an internal decision,
the decision maker must be a different active admin or consultant from the
cycle's last assessor. For an external decision, an internal user records the
named customer's decision. Recorder and decision maker remain distinct in
storage and audit context.

A material change to the scenario, effective protection need, dependency
provenance, threat, methodology, treatment, linked measure completion, residual
assessment, or supporting evidence invalidates the decision. The prior row is
retained with invalidation metadata. Completing a reassessment requires a new
decision when the new residual category is high or very high.

## Invalidation and recalculation

Invalidation is centralized in small services rather than scattered model
observers. Relevant commands publish in-process domain notifications after
locking and validation; the same transaction invokes the required recalculation
and invalidation handler.

The following changes are material:

- direct or inherited protection value or maximum-source provenance;
- active dependency topology or target lifecycle;
- project methodology or threat-catalog migration;
- threat replacement or CIA-objective correction;
- target-linked finding status or coverage link;
- scenario, treatment, work-item link, measure completion, or evidence review;
- risk assessment or management decision.

The handler marks only reachable protection needs, relevant triggers, threat
overviews, and linked analysis cycles. It never deletes prior values. A stale
completed cycle no longer covers a mandatory trigger, and project completion
remains blocked until a current replacement cycle is completed.

## Authorization and tenant boundary

Only authenticated, active users belonging to the internal organization with
the `admin` or `consultant` role may view project risk data. Both roles may
maintain direct ratings, create analyses, assess risks, and record treatments
for writable projects. Admin-only global actions are methodology and threat
catalog drafting/publication. Four-eyes and management decisions additionally
enforce the actor-separation rules described above.

Writes require an active customer organization and a project in `draft` or
`active` state. Completed or archived projects and inactive customers retain
read-only history. Every nested route verifies organization, project, target,
methodology, threat, trigger, cycle, treatment, work item, decision, evidence,
and import-batch ownership before returning or changing data.

Cross-organization and cross-project route substitution returns `404`; a known
resource with an unauthorized operation returns `403`. Policies and request
authorization are authoritative. Vue visibility is convenience only.

## Interface

Project navigation adds `Schutzbedarf` and `Risiken`.

The protection-needs page lists active and historical processes/assets with
direct, inherited, and effective CIA values. Expanding a row shows rationale,
maximum propagation sources, dependency paths, approval state, and stale
warnings. It provides manual rating, target approval, downgrade-request, and
CSV-import actions according to permissions. Import follows the existing
upload, preview, confirm pattern.

The risk overview groups mandatory open triggers, stale analyses, decisions,
and completed analyses by target. Filters cover target type, threat, risk
category, status, staleness, and decision requirement. A target workspace
provides its threat overview, graph impact context, scenarios, treatment,
linked findings/measures/evidence, and cycle history.

Global admin navigation adds read-only version lists for consultants and
draft/publish controls for admins. Project initialization and migration screens
display exact version names, publication dates, and impact warnings before
confirmation.

The UI uses tables and forms rather than a graphical network editor. Required
actions and project-completion blockers are prominent. Forms use independent
Inertia state, field-specific German errors, saved feedback, and explicit
conflict messages. Scenario, rationale, external-person, and evidence data is
never placed in query strings.

## Error handling and concurrency

Manual updates use the record version or `updated_at` for optimistic conflict
detection. A stale submission receives a domain-specific conflict and reload
instruction rather than overwriting another user's work.

Project-row locking serializes context initialization/migration, propagation,
imports, trigger refresh, cycle completion, and project completion. Narrow row
locks protect downgrade and management decisions from double decisions. Unique
constraints provide defense in depth for current cycles, project targets,
matrix cells, active requests, and link rows.

Validation and workflow errors use stable German messages without stack traces,
raw CSV rows, private storage paths, scenario contents, or personal details.
Failure during import, recalculation, invalidation, state transition, or audit
creation rolls back the entire database transaction. No queue infrastructure
is introduced in Slice 6; recalculation is synchronous and bounded to affected
graph nodes.

## Audit and redaction

Slice 6 adds event families for:

- methodology and threat-catalog drafting/publication;
- risk-context initialization and migration;
- protection-need rating, recalculation, approval, and invalidation;
- downgrade request and decision;
- protection-needs import preview, application, rejection, and expiry;
- risk trigger and threat review changes;
- risk-analysis creation, assessment, treatment, invalidation, and completion;
- work-item links and management decisions.

Allowed context contains project/resource/version IDs, target type and ID,
objective, old/new categorical values, old/new statuses, decision channel,
changed field names, and aggregate import counts. It excludes rationales,
scenario text, threat descriptions, names, roles, email addresses, evidence
metadata or content, finding/measure text, CSV rows, hashes, and dependency
reasons.

Recorder and decision-maker identifiers are separate for internal decisions.
External decisions record only that the channel was external and an opaque
decision ID in audit context; external personal data stays in the authorized
domain row. A failed audit write rolls back the authoritative state change.

## Verification strategy

Verification is risk-weighted to avoid redundant test volume. Focused
PostgreSQL backend tests cover:

- published-version immutability and complete matrix/catalog constraints;
- explicit target foreign keys and negative tenant/project substitution;
- maximum propagation, provenance, inactive nodes, and affected-subgraph
  recalculation;
- downgrade four-eyes rules, external decision recording, invalidation, and
  unchanged higher effective values before approval;
- the CSV happy path plus representative malformed, stale, unauthorized, and
  atomic-rollback cases;
- automatic mandatory triggers, threat-review completeness, stale coverage,
  and project-completion blocking;
- matrix-derived inherent/residual categories and treatment prerequisites;
- measure/evidence/finding links, management acceptance, actor separation, and
  invalidation;
- transactional audit cardinality, rollback, and redaction.

Tests use data providers and service-level matrix cases instead of repeating
the same behavior through every controller. Inertia page tests cover the main
props, read-only state, blocking warnings, permission-dependent actions, import
preview, and absence of sensitive query-string content. Trivial presentation
variants do not receive duplicate tests.

The final gate runs existing backend tests and formatting/static checks,
frontend lint/format/type checks and production build, reversible migrations,
fresh database seeding, and a focused security review. Main is not changed
directly; the slice is merged only through its feature branch after the gate
passes.

## Explicit exclusions

Slice 6 does not include:

- an external risk API, webhooks, or scheduled synchronization;
- automatic download or migration of future BSI publications;
- quantitative loss calculation, currency values, Monte Carlo analysis, or
  aggregate portfolio risk;
- risk targets spanning multiple processes/assets or multiple projects;
- a graphical dependency or heat-map editor;
- AI-generated ratings, scenarios, treatments, or decisions;
- email notifications or new background-queue infrastructure;
- physical deletion of published versions, historical cycles, or decisions.

## Acceptance criteria

Slice 6 is complete when an authorized internal user can initialize a project
with exact published methodology and threat versions; rate every process and
asset for CIA; inspect and approve justified inherited downgrades; import
direct ratings atomically; review applicable threats; complete every mandatory
BSI-oriented risk workflow; link existing findings, measures, and evidence;
and document required management decisions.

Propagation, risk categories, staleness, and project-completion gates must be
deterministic. Historical cycles and decisions must remain reproducible. No
cross-tenant reference, self-approval where four-eyes is required, planned-only
residual reduction, sensitive audit content, or partial import may be
accepted.
