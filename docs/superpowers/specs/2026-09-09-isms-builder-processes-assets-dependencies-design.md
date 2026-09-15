# Slice 5: Processes, Assets & Dependencies Design

## Outcome

Slice 5 gives each customer project authoritative registers for business
processes and assets plus a directed, typed dependency graph. Internal admins
and consultants can maintain records manually or import them from CSV through
an atomic preview-and-confirm workflow. Later protection-needs, risk, BIA, and
BCM slices consume stable traversal interfaces instead of interpreting register
tables directly.

CSV is the first integration surface. Domain validation and upsert services are
transport-independent so a later authenticated API can reuse them without
moving business rules into controllers or parsers. API credentials, webhooks,
scheduled synchronization, and external connector operation are out of scope.

## Architectural choice

`BusinessProcess`, `Asset`, and `DependencyEdge` are separate relational domain
models with their own policies, services, requests, controllers, and tests.
Dependencies use explicit process and asset foreign-key columns rather than a
generic polymorphic relation. PostgreSQL check constraints and composite
foreign keys therefore enforce project ownership and the allowed endpoint
combinations at database level.

The allowed directed edge types are:

- business process depends on business process;
- business process depends on asset;
- asset depends on asset.

Asset-to-process edges, self-dependencies, duplicate active edges, and cycles
are forbidden. An edge is classified as `critical` or `supporting` and may have
a short reason. This deliberately small vocabulary is sufficient for later
protection-needs propagation and BCM impact traversal. Arbitrary relationship
types and a graphical network editor remain out of scope.

## Business-process register

`business_processes` stores:

- UUID primary key and project ID;
- stable project-local process key;
- name and optional description;
- optional free-form owner name and validated owner email;
- active/inactive state;
- creator and ordinary timestamps.

The stable key is trimmed, upper-cased, and restricted to 2–64 characters from
`A-Z`, `0-9`, `.`, `_`, and `-`, beginning with an alphanumeric character. It is
unique case-insensitively within a project and immutable after creation. Names
are required and limited to 160 characters; descriptions to 4,000 characters;
owner names to 160 characters; owner emails to 254 characters.

Records are deactivated and may later be reactivated; Slice 5 exposes no
physical deletion. Existing dependencies and audit history remain visible when
a process becomes inactive. Protection needs, BIA values, criticality scoring,
recovery objectives, and continuity strategies belong to later slices.

## Asset register

`assets` stores:

- UUID primary key and project ID;
- stable project-local asset key;
- name and optional description;
- asset type;
- optional free-form owner name and validated owner email;
- active/inactive state;
- creator and ordinary timestamps.

Asset types are `information`, `application`, `it_system`, `service`, and
`facility`. This covers the register categories needed by later risk and BCM
work without introducing vendor-specific inventory fields. The stable key,
name, description, ownership, lifecycle, and immutability rules match business
processes.

An asset is a project-scoped logical inventory record. Automated discovery,
device agents, licensing data, secrets, network scans, and CMDB synchronization
are out of scope.

## Dependency-edge schema and integrity

`dependency_edges` stores:

- UUID primary key and project ID;
- nullable source process ID and nullable source asset ID;
- nullable target process ID and nullable target asset ID;
- importance `critical` or `supporting`;
- optional reason limited to 1,000 characters;
- active/inactive state;
- creator and ordinary timestamps.

Exactly one source column and exactly one target column are populated. A check
constraint permits process-to-process, process-to-asset, and asset-to-asset
only. Composite foreign keys `(endpoint_id, project_id)` bind every endpoint to
the edge project. Partial unique indexes prevent duplicate active edges for
each allowed endpoint combination. The service rejects a same-node edge before
writing.

Changing either endpoint is not an edit: the existing edge is deactivated and
a new edge is created. Importance, reason, and active state may be changed.
New or reactivated edges require active endpoints and a writable project.
Deactivating a process or asset does not delete its edges, but active traversal
excludes any edge with an inactive endpoint.

## Cycle prevention and traversal

The dependency graph must remain acyclic. Before creating or reactivating an
edge `source -> target`, the service performs a project-scoped traversal from
`target`; if `source` is reachable, the write is rejected. Edge validation and
the write occur inside a transaction holding a project-row lock, serializing
competing graph changes in one project. Database uniqueness remains defense in
depth for duplicate edges.

`DependencyGraph` exposes typed node references and these stable interfaces:

- direct dependencies of a process or asset;
- transitive dependencies in breadth-first order;
- direct dependents of a process or asset;
- transitive dependents in breadth-first order;
- affected business processes for a changed process or asset.

Results contain the typed node reference, depth, and traversed edge importance.
Each node appears once at its shortest depth. Traversal is project scoped,
deterministic, excludes inactive records by default, and still guards against a
cycle so legacy or manually corrupted data cannot cause an infinite loop.

## Manual workflows

Project registers support creating, editing, deactivating, and reactivating
processes and assets. The dependency register supports creating, editing edge
metadata, deactivating, and reactivating edges. Services own normalization,
uniqueness, lifecycle, graph, and audit rules; controllers only authorize,
validate transport input, call services, and return Inertia responses.

Optimistic stale-write protection uses the record's `updated_at` value. A form
submitting an older value receives a conflict response and must reload rather
than silently overwriting a concurrent edit.

## CSV formats

Slice 5 accepts three independent import kinds with exact, case-insensitive
headers. Column order is arbitrary and unknown or duplicate headers are errors.

Process CSV:

```text
key,name,description,owner_name,owner_email,active
```

Asset CSV:

```text
key,name,type,description,owner_name,owner_email,active
```

Dependency CSV:

```text
source_type,source_key,target_type,target_key,importance,reason,active
```

`source_type` and `target_type` are `process` or `asset`. `active` is required
and accepts `true` or `false` case-insensitively. Empty optional cells become
`null`; whitespace around values is removed. Stable keys are normalized before
duplicate detection. Dependency imports resolve endpoint keys only within the
selected project and obey the same type and cycle rules as manual changes.

Files must be UTF-8, optionally with a UTF-8 BOM. The parser accepts comma or
semicolon delimiters only when one produces the exact header set unambiguously.
Quoted fields and embedded line breaks use RFC 4180 rules. Files are limited to
5 MiB and 10,000 data rows, must contain a header and at least one data row, and
may not contain NUL bytes or spreadsheet-formula cells whose trimmed value
begins with `=`, `+`, `-`, or `@`. Blank trailing rows are ignored. Malformed
quoting, invalid encoding, duplicate keys or edges, and validation failures are
reported with stable one-based CSV row numbers.

## Atomic preview-and-confirm import

CSV import is deliberately two-step:

1. upload and parse the entire file;
2. validate every normalized row against domain rules and current project data;
3. show a preview categorized as new, changed, unchanged, or invalid;
4. allow confirmation only when there are no invalid rows;
5. atomically apply the exact reviewed normalized payload.

`register_import_batches` stores project ID, kind, uploader, SHA-256 of the
uploaded bytes, normalized JSON payload, preview summary, status, expiry, and
timestamps. The original CSV file is never retained. Batches expire after 30
minutes, are single-use, and are inaccessible across projects or users. Payload
JSON uses only the canonical columns for its import kind.

Preview performs no register writes. Confirmation locks both the batch and the
project, verifies the batch is pending, unexpired, owned by the acting user,
still belongs to a writable project, and then revalidates the normalized
payload against current database state. Any conflict or new validation failure
rejects the whole confirmation and leaves every register unchanged.

Confirmation upserts rows by normalized stable key. Existing fields and active
state are updated, new rows are created, and unchanged rows remain untouched.
Records or edges omitted from the CSV remain unchanged. Dependency import does
not implicitly create endpoints. The complete batch applies in one database
transaction with its audit event; the batch becomes `applied` only inside that
transaction. Repeated confirmation is idempotent and never applies twice.

Expired and applied payloads can be removed by a scheduled cleanup command.
The preview page never places imported row contents, names, owner data, or error
values in URLs.

## Authorization and tenant boundary

Only authenticated, active users belonging to an internal organization with
the `admin` or `consultant` role may view or manage Slice 5 registers. Writes
require an active customer organization and a project in `draft` or `active`
state. Completed or archived projects and inactive customers retain read-only
history for internal users.

Every nested route verifies organization, project, process, asset, edge, and
import-batch ownership before returning or changing data. Cross-organization
or cross-project route substitution returns `404`; a known resource with an
unauthorized operation returns `403`. Policies and request authorization are
authoritative; Vue controls only reflect permissions.

## Audit events and redaction

Slice 5 adds these customer-owned event families:

- `business_process.created`, `business_process.updated`, and
  `business_process.status_changed`;
- `asset.created`, `asset.updated`, and `asset.status_changed`;
- `dependency.created`, `dependency.updated`, and
  `dependency.status_changed`;
- `register_import.previewed`, `register_import.applied`,
  `register_import.rejected`, and `register_import.expired`.

Allowed context contains project ID, resource IDs, normalized stable keys,
import kind, batch ID, row counts, old/new active state or importance, and
changed field names. Names, descriptions, reasons, owner names, owner emails,
CSV rows, source files, hashes, and detailed validation values are excluded.
Meaningful state changes and their audit events share one transaction. A
failed audit rolls back the domain change.

## Interface

The project navigation adds `Prozesse`, `Assets`, and `Abhängigkeiten` alongside
the existing assessment and work-item registers.

Process and asset pages provide searchable, filterable tables, active/inactive
state, detail/edit panels, and manual lifecycle actions. Their import action
opens an upload screen followed by a preview showing aggregate counts and a
bounded row table with field-level errors. Confirmation is unavailable when
any row is invalid or the batch expired.

The dependency page uses a table rather than a graphical network. It shows
typed source and target keys/names, importance, reason, and state; filters by
endpoint type, importance, and state; and can expand a selected node into its
direct and transitive dependencies and affected processes. This makes graph
behavior inspectable and accessible without adding a visualization library.

Read-only project states keep registers, inactive history, previews already
created, and traversal results legible but expose no write or confirmation
controls. Forms use separate Inertia state and display field-specific errors,
conflicts, import progress, expiry, and saved feedback.

## Error handling and concurrency

Validation errors use stable German messages and field or CSV row coordinates.
CSV parser errors do not expose server paths, raw rows, or exception details.
Oversized input is rejected before fully buffering it in memory. Preview
payloads and UI props are bounded even at the 10,000-row limit.

Project-row locking serializes imports, manual upserts, lifecycle changes, and
dependency graph writes that could invalidate a preview or introduce a cycle.
Duplicate keys, duplicate edges, stale updates, reused batches, expired
batches, and concurrent project changes produce explicit conflict responses.
No partial CSV result is ever committed.

## Verification gates

PostgreSQL backend tests cover schema checks, composite foreign keys, endpoint
combinations, partial uniqueness, normalization, lifecycle rules, all three
CSV formats, delimiter and encoding handling, size/row/formula limits, atomic
rollback, preview expiry and ownership, idempotent confirmation, stale preview
revalidation, cycle prevention, traversal depth/order, audit rollback and
redaction, and negative tenant substitution.

Frontend contract tests cover register props, read-only behavior, independent
forms, import preview categories, bounded error display, filters, traversal
results, and absence of personal/import contents from query strings. The final
gate runs backend formatting/static analysis/tests, reversible migrations and
catalog seeding, frontend lint/format/types, production build, and an
independent security review before a pull request targets `main`.

