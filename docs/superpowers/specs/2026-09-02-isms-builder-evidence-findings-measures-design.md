# Slice 4: Evidence, Findings & Measures Design

## Outcome

Slice 4 turns assessment gaps into a traceable remediation workflow. Internal
consultants can upload immutable evidence, link it to assessment questions and
findings, review it, propose findings from assessment gaps, accept or reject
those proposals, and manage multiple measures through completion.

The assessment question remains the primary working context. Project-wide
evidence, finding, and measure registers provide complementary overview and
filtering. Vendor-specific parsers, OCR, malware scanning, automatic content
analysis, and physical evidence deletion remain out of scope.

## Architectural choice

Evidence, findings, and measures are separate relational modules. Each has its
own model, status enum, policy boundary, service layer, requests, controllers,
and tests. Explicit link tables preserve traceability without using a generic
polymorphic workflow table.

This structure is preferred over a shared work-item table because the three
domains have different invariants and lifecycles. Full event sourcing is also
unnecessary: authoritative state stays in ordinary relational tables while the
existing append-only audit log records security-sensitive transitions.

## Evidence storage and metadata

Evidence originals use a dedicated private Laravel filesystem disk. The V1
deployment stores files below `storage/app/private/evidence`; the application
depends only on Laravel's filesystem contract so the disk can later be changed
to S3 or Azure-compatible object storage without changing domain services.

`evidence_files` stores:

- project ID;
- opaque storage path;
- original filename for authorized display and download;
- detected MIME type and approved file kind;
- byte size and lowercase SHA-256 digest;
- review status;
- uploader and upload timestamp;
- nullable reviewer, review timestamp, and review note;
- ordinary creation and update timestamps.

The allowed review states are `pending_review`, `verified`, and `rejected`.
Self-review is explicitly allowed for active internal admins and consultants.
A rejection requires a review note; verification may include one. Later review
decisions replace the current review state while the append-only audit trail
preserves the transition metadata.

Storage path, original filename, detected type, size, digest, uploader, and
upload timestamp are immutable after creation. A PostgreSQL trigger rejects
updates to those columns. Slice 4 exposes no physical deletion endpoint. A
rejected file is retained with its original bytes and metadata.

Within one project, `(project_id, sha256)` is unique. Uploading identical bytes
returns the existing evidence record and may add a new question or finding link
instead of storing another copy. The same bytes may exist independently in a
different project. A uniqueness race removes the losing temporary object and
uses the winning record.

## File validation and ZIP inspection

The maximum request file size is 50 MiB. PHP-FPM, container configuration, the
Laravel validator, and tests use the same effective limit. Approved formats
are PDF, PNG, JPEG, plain text, CSV, DOCX, XLSX, and ZIP. The server validates
the client extension, detects the MIME type from the content, and accepts only
approved extension/MIME combinations. Macro-enabled Office formats, scripts,
executables, disk images, and generic binary uploads are rejected.

ZIP support uses PHP's ZIP extension and a dedicated inspector. A ZIP is
rejected if any of these conditions apply:

- more than 200 non-directory entries;
- more than 250 MiB total uncompressed size;
- any encrypted entry;
- an absolute path, drive-qualified path, NUL byte, or path traversal segment;
- a symbolic link or other non-regular entry;
- a nested archive or compressed package;
- an executable, script, macro-enabled Office file, installer, disk image, or
  other blocked extension.

DOCX and XLSX remain approved Office documents even though their internal file
format is ZIP-based; the archive rules above apply to user-supplied `.zip`
files. The inspector reads central-directory metadata and never extracts an
archive into the application tree.

The uploader generates an opaque UUID-based path and never incorporates the
original filename. Upload coordination follows this order:

1. validate request and inspect the file;
2. stream to a new private path while calculating SHA-256 and size;
3. create metadata and requested links in a database transaction;
4. create a redacted audit event in that same transaction;
5. remove the new object if database or audit persistence fails.

## Evidence links and relational integrity

`evidence_question_links` connects an evidence file to a snapshot assessment
question. `evidence_finding_links` connects an evidence file to a finding. A
file may have multiple links of either kind, and duplicate links are forbidden.

Link rows carry the project and assessment identifiers required for composite
foreign keys. Supporting composite unique constraints on projects,
assessments, questions, evidence files, and findings make it impossible to
persist a link whose resources belong to different projects or assessments.
Application ownership checks remain in place as defense in depth.

Only currently applicable snapshot questions can receive a new evidence link.
Existing links are retained if a later trigger answer hides a question, so the
historical evidence relationship is not silently destroyed.

## Finding workflow

`findings` stores:

- project, project assessment, and snapshot question IDs;
- title and description;
- severity;
- status;
- proposer and proposal timestamp;
- nullable decision maker, decision timestamp, and decision note;
- nullable closer and closing timestamp;
- creation and update timestamps.

Statuses are `proposed`, `accepted`, `rejected`, and `closed`. A proposal is
allowed only when the referenced snapshot question is currently applicable and
its saved compliance status is `partial` or `not_fulfilled`. Finding text is
entered explicitly; answer or comment content is never copied automatically.
Finding severity is required and uses `low`, `medium`, `high`, or `critical`.

A proposed finding may be edited, accepted, or rejected. Rejection requires a
decision note. Accepted, rejected, and closed finding content is immutable; a
later correction is represented by a new proposal. A PostgreSQL partial unique
index permits at most one `proposed` or `accepted` finding per snapshot
question while retaining rejected and closed history.

An accepted finding can be closed only after it has at least one measure and
every measure is in a terminal `completed` or `cancelled` state. Rejected and
closed findings are terminal in Slice 4.

## Measure workflow

`measures` stores:

- project and finding IDs;
- title and description;
- priority;
- required responsible person's free-form name and optional validated email
  address;
- required due date;
- status;
- creator and creation timestamp;
- nullable completion actor and completion timestamp;
- nullable cancellation reason;
- ordinary creation and update timestamps.

Priorities are `low`, `medium`, `high`, and `critical`. Statuses are `planned`,
`in_progress`, `blocked`, `completed`, and `cancelled`. Only accepted findings
may receive measures. The supported transitions are:

- `planned` to `in_progress` or `cancelled`;
- `in_progress` to `blocked`, `completed`, or `cancelled`;
- `blocked` to `in_progress` or `cancelled`.

`completed` and `cancelled` are terminal. Cancellation requires a reason.
Completing a measure records the acting internal user and timestamp. Measure
content can be edited before completion or cancellation, but every edit and
status change is audited without copying responsible names, email addresses,
descriptions, or reasons into audit context.

## Authorization and tenant boundary

Only authenticated, active users belonging to an internal organization and
holding the `admin` or `consultant` role may view, upload, link, review,
download, propose, decide, or manage Slice 4 resources. Both roles have the
same Slice 4 workflow permissions, including self-review.

Writes require an active customer organization and a project in `draft` or
`active` state. Completed or archived projects and inactive customers retain
read-only access to existing history for internal users. Every nested route
verifies organization, project, assessment, question, evidence, finding, and
measure ownership before reading file bytes or changing state.
Cross-organization and cross-project route substitution returns `404`; a known
resource with an unauthorized operation returns `403`.

Policies and request authorization are authoritative. Vue controls reflect
permissions but never replace server checks.

## Download integrity

Evidence has no public URL. Authorized downloads are streamed through a
controller with `Content-Disposition: attachment`, a safe filename parameter,
`X-Content-Type-Options: nosniff`, and the recorded content type.

Before a download begins, the service verifies object existence, byte size,
and SHA-256 against stored metadata. Missing or mismatched objects are not
returned. The user receives a generic integrity error that does not expose the
private path, and `evidence.integrity_failed` records only evidence and project
IDs plus the failure kind.

## Audit events and atomicity

Slice 4 adds these audit event families:

- `evidence.uploaded`, `evidence.linked`, `evidence.reviewed`, and
  `evidence.integrity_failed`;
- `finding.proposed`, `finding.updated`, `finding.accepted`,
  `finding.rejected`, and `finding.closed`;
- `measure.created`, `measure.updated`, and `measure.status_changed`.

Allowed context contains only resource IDs, project ID, old/new status, link
type, integrity failure kind, and changed field names. Original filenames,
file contents, hashes, answer content, finding text, review notes, responsible
people, email addresses, and cancellation reasons are excluded.

Database state transitions and their audit events share one transaction. If
audit persistence fails, the authoritative transition rolls back. File upload
adds compensating object cleanup because filesystem writes cannot participate
in the database transaction.

## Interface

The assessment remains the primary workspace. Each question card shows linked
evidence with review state, offers an upload/link action, and exposes
`Feststellung vorschlagen` only for eligible assessment gaps. An accepted
finding shows its measures and completion summary directly in the question
context.

A project navigation bar adds four registers:

- `Bewertung` retains the existing category-focused questionnaire;
- `Nachweise` lists evidence with type, size, review state, links, download,
  and review actions;
- `Feststellungen` lists and filters proposed, accepted, rejected, and closed
  findings with deep links to their source question;
- `Maßnahmen` lists and filters measures by status, priority, responsible
  person, and due date with deep links to their finding and question.

Forms display upload progress, validation errors, review requirements, and
clear saved feedback through Inertia. Read-only history remains legible when
the customer is inactive. No sensitive field is placed in a query string.

## Error handling

Validation errors are returned as field-specific Inertia errors. File-system,
archive, integrity, and domain-transition failures use stable German messages
without stack traces, internal paths, or MIME detector details. Unexpected
storage failure never creates metadata; unexpected metadata or audit failure
never leaves a newly uploaded unreferenced object.

Concurrent duplicate uploads, duplicate active findings, stale status
transitions, and repeated link submissions are handled idempotently or rejected
with a domain-specific conflict response. Controllers delegate these decisions
to small domain services instead of embedding workflow logic.

## Verification gates

Backend tests against PostgreSQL cover:

- schema constraints, composite tenant integrity, and reversible migrations;
- every approved and rejected file class plus the 50 MiB boundary;
- all ZIP limits and blocked entry types without extracting files;
- streaming hash calculation, immutable metadata, duplicate reuse, and cleanup
  after database or audit failure;
- evidence linking, self-review, protected downloads, missing files, and
  integrity mismatches;
- finding eligibility, uniqueness, edits, decisions, and closing rules;
- measure validation, status transitions, cancellation, and terminal states;
- negative customer-user, cross-organization, cross-project, cross-assessment,
  and cross-resource authorization cases;
- audit cardinality, redaction, and transactional rollback.

Inertia page tests verify question-context controls, all four project
registers, read-only presentation, validation feedback, and deep-link data.
Frontend lint, formatting, TypeScript checks, and production build must pass.
CI installs the PHP ZIP extension and repeats migration rollback and starter
catalog seeding before Slice 4 is proposed for merge.
