# Task 2 report — manual process and asset workflows

## Commits

- `5829fe3acb9a4cfd5a06e28dcadfbf7a1121e8b1` — `test: cover process and asset register workflows` (RED test commit)
- `7d979d92228fde35432b10ec2fd3a928c2e91ac3` — `feat: manage project processes and assets` (implementation)

The implementation is based on `966b3ce7128023da1b42aeaa2a20a5e2d4bde56f`.

## Delivered files and behavior

- Added normalized, immutable project-local keys and process/asset service APIs with optional audit suppression for later import paths.
- Added transactional writable-project and record locks, duplicate detection, stale-update protection, lifecycle changes, and audit events with explicit customer organization ownership.
- Added process/asset policies, nested form requests/controllers, and named nested write routes.
- Extended the audit context allow-list only with stable identifiers, normalized keys, active-state metadata, and changed-field names.
- Added focused workflow, authorization/tenant-substitution, audit-redaction, no-op, and audit-rollback tests.

## Test evidence and blockers

The prescribed RED and GREEN focused command was attempted:

```text
php artisan test tests/Feature/Registers/RegisterWorkflowTest.php tests/Feature/Registers/RegisterAuthorizationTest.php tests/Feature/Registers/RegisterAuditTest.php
```

It could not start because this environment has no PHP executable (`zsh: command not found: php`). Composer is likewise unavailable, so neither focused PHPUnit execution nor formatting/static analysis through the project tooling can run locally. The intended RED result is feature-missing failures; the intended GREEN result is all three focused files passing. No substitute bulk test generation was performed.

`git diff --check 966b3ce7128023da1b42aeaa2a20a5e2d4bde56f..HEAD` completed successfully after implementation and again during final review.

## Self-review

Reviewed the entire `966b3ce7128023da1b42aeaa2a20a5e2d4bde56f..HEAD` diff, including tests, services, policies, requests, controllers, routes, and audit allow-list.

- Tenant substitutions are rejected by request ownership checks before authorization (`404`); known policy denials are `403`.
- Writes require active internal admin/consultant actors, active customer organizations, and draft/active projects in both policy and service layers.
- Mutating service operations lock the project and record in the same transaction, and audit recording occurs inside that transaction so an audit exception rolls back domain writes.
- Audit payloads contain only IDs, normalized keys, changed field names, and lifecycle values; no process/asset names, descriptions, owner names, or emails are included. Status no-ops return without auditing.
- Stable keys are normalized only during create and update requests do not allow keys, project IDs, creator IDs, or audit fields.

## Remaining concern

The only outstanding verification concern is environmental: run the three focused PHP tests and the repository formatter/static checks in CI or a PHP/Composer-capable workspace before integration.

## Correction round 1

CI run `34387019957`, job `102585737635` stopped at Pint before tests. It reported 16 style issues limited to Task 2 controllers, requests, policies, register services, routes import ordering, and focused tests. The correction mechanically expands compressed PHP statements and standardizes imports/braces without changing behavior. Local Pint remains blocked by the missing PHP/Composer runtime; `git diff --check` is used as the available local verification.

Formatting was applied in `a075e99130a06453160b35eb6518aa10f2d67fba`, `85fcaf0f6a60e4da8123ddfa05ba1300a7cec2a9`, and the final test-only style correction commit.

The final correction reorders the `routes/web.php` controller imports to Pint's expected ordering; it is mechanical and does not change routing behavior.

Correction round 2 addresses CI run `34387960745`, job `102588912578`: method separation and remaining spacing in `BusinessProcessService`, lexical route imports, and the unused test import/return separation. These are mechanical Pint-only changes.

Correction round 3 addresses CI run `34388253061`, job `102589899897`: `BusinessProcessService` now mirrors the spacing around transaction returns and closures used by the Pint-clean `AssetService`. No behavior changed.

## Correction round 4

CI run `34388564101`, job `102590948139`, passed Pint across all 212 files and PHPUnit. Larastan alone reported two errors: `AssetService::validate()` at line 87 and `BusinessProcessService::validate()` at line 85 lacked an iterable value type. In both methods, the one-line PHPDoc placed `@param` and `@return` together, causing `@return` to be parsed as the parameter description. The correction converts each to a multiline PHPDoc with distinct `@param  array<string, mixed>  $data` and `@return array<string, mixed>` tags, matching Pint spacing. No behavior changed.

## Correction round 5

CI run `34388928266`, job `102592135370`, passed Pint and Larastan; 266 tests passed and three Task 2 tests failed. The two register update failures came from strict comparisons of `toIso8601String()` output: identical instants expressed with different offsets were rejected as stale. Both `BusinessProcessService` and `AssetService` now parse the validated ISO-8601 expected value as `CarbonImmutable` and compare it to the locked model timestamp with Carbon equality semantics, preserving rejection of genuinely stale timestamps. The remaining failure at `RegisterAuthorizationTest.php:55` came from `Asset::factory()->for($foreignProject)`, which inferred an `ismsProject` relation; the factory call now explicitly targets the Asset model's `project` relation, matching the established Task 1 pattern. No tests were weakened.

## Correction round 6

CI run `34389411078`, job `102593739084`, passed Pint, Larastan, and 267 tests; only the two process update tests remained stale. The Asset factory relation correction was therefore confirmed. The prior Carbon `equalTo()` correction still compared sub-second precision, while the ISO-8601 concurrency token accepted by the service and deliberately used by the tests is generated with `toIso8601String()` at second precision. Both register services now compare the parsed expected token and locked timestamp as integer Unix timestamps, retaining timezone-independent instant matching at the supported token precision and rejecting the explicit year-2000 stale token. No tests were changed.

## Correction round 7

CI run `34389832678`, job `102595119682`, again isolated two stale-token process update failures after all other checks passed. PostgreSQL evidence showed the application writes Europe/Berlin wall time to `timestampsTz`, while the just-created Eloquent instance carries a different instant representation from a locked re-read; both failures use the service-returned new process immediately. Creation in both register services now refreshes the newly persisted model after a successful audit and before it is returned, providing the caller with canonical database state. Update comparison is restored to precise Carbon instant equality, so a different instant—including the explicit year-2000 stale token—continues to be rejected. Audit failures still occur before refresh inside the transaction and therefore retain rollback semantics. No tests were changed.

## Correction round 8

Review follow-up `3e40a12d369069f99c44ac7ba3eff54092347e5d` strengthens the audit rollback test: it now verifies the expected audit exception and asserts that neither the business-process record nor an audit event persisted. It also adds HTTP coverage for both process and asset update requests, showing that a natural-language timestamp accepted by Laravel's generic `date` rule is rejected, while the normal application-emitted ISO-8601 token is accepted.

The paired implementation replaces both generic `date` rules with one small ISO-8601 concurrency-token rule. It requires date/time seconds plus a timezone (`Z` or an explicit offset), accepts optional fractional seconds (up to PHP's supported microsecond precision), and verifies calendar validity. PHP and Composer are unavailable in this worktree, so the added RED and GREEN focused commands plus Pint cannot run locally; `git diff --check` remains the locally executable integrity check.
