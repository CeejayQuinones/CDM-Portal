# Admission audit and concurrency — Step 6

Implemented only the audit foundation for the existing internal identity creation
service and an opt-in database concurrency verifier. No creation endpoint/UI or
other Admission workflow is exposed. The existing `admission_cycles` and
`admission_applicants` migrations and schemas are unchanged.

## Logging strategy

The portal has domain-specific `document_request_status_changes` records and a
private helper in `DocumentRequestWorkflowService`, not a general activity-log
service. Those records require Document Request subjects and use Registrar staff
actors. Reusing them would alter an unrelated domain. Existing Laravel/Monolog
diagnostic logs cannot commit or roll back atomically with applicant creation.

Use the approved, additive `admission_audit_events` table and centralized
`AdmissionAuditWriter::identityCreated()`. Existing Document Request and Monitoring
code and logging are untouched. The live verifier also compares all existing table
row fingerprints after cleanup, including their logging tables.

## Schema and event boundary

Migration: `2026_09_23_000001_create_admission_audit_events_table.php`.

| Column | Purpose |
| --- | --- |
| id | Unsigned bigint primary key |
| actor_user_id | Required FK to users.id; delete RESTRICT |
| applicant_id | Required FK to admission_applicants.id; delete RESTRICT |
| action | varchar(80), server-selected event name |
| metadata | Required JSON, assembled from persisted applicant fields |
| created_at | Timestamp; no updated_at or soft delete |

Lookup indexes cover `(applicant_id, created_at)` and `(actor_user_id, created_at)`.
Rollback drops only this new table. Application-model updates and deletes are
rejected. This is an application append-only boundary, not a tamper-proof database
ledger: privileged SQL/query-builder operations can bypass it. The verifier uses
such deletion only to remove its exact synthetic fixture IDs.

The only implemented event is `admission.identity_created`. It is called immediately
after applicant insertion within `AdmissionIdentityService::create()`'s existing
transaction. A writer exception propagates and rolls back both rows. Duplicate
creation rejection, failed number allocation, and read requests emit no event.

Example metadata:

```json
{"cycle_id": 123, "status": "draft", "version": 1}
```

The writer requires a transaction and a newly created applicant, checks that the
persisted subject belongs to the actor and is a draft, and derives metadata from a
fresh database read. It takes no action string, description, request body, or caller
metadata. Passwords, bearer tokens, exam answers, raw request payloads, profile
snapshots, contact information, source provenance and unrelated internal fields
are excluded. There is no generic future-event API. System actors, status/cycle
changes, Registrar/Admin decisions and Student conversion require separate approved
writers and workflow rules; they are not implemented.

Creation must continue to use the service. Direct model/query-builder inserts can
bypass its authorization, locking and audit boundary; they are not a supported
production creation interface. No backfill or audit of nonexistent past events was
performed.

## Deployment

The new audit migration was inspected with `migrate:status` and a path-scoped
`migrate --pretend`, then applied with normal path-scoped Laravel migration
execution. Its SQL creates only the audit table, indexes and its two foreign keys.
No Step 3 DDL, destructive migration command or seeder was run.

Other environments must deploy this migration before allowing the internal
creation service to write. If the audit storage is missing or unavailable, creation
fails rather than committing an unaudited applicant. The Step 4 GET remains read-only.

Local deployment is recorded in migration batch **12**. Both Step 3 migrations
remain in batch 11. A final schema read confirmed both audit foreign keys and both
lookup indexes. All three Admission tables were empty after fixture cleanup.

## Concurrency verification method

Run separately from the normal SQLite suite:

```sh
cd backend
php scripts/verify-admission-concurrency.php --run-with-fixtures
```

This is an explicit, non-production-only diagnostic. It requires the mysql driver
and the already deployed audit table. It performs no migrations, table alterations,
database drops or destructive seeders. The database account could not create a
separate disposable database, so the verified approach uses isolated synthetic
fixtures in the existing local database. It never uses an existing person's account
as the applicant or actor. Guest/Admin role definitions are read without modification.

Each run uses an unpredictable prefix, creates new users with random passwords,
profiles, an academic year and cycles, and records exact inserted IDs in a private
temporary ledger. Separate PHP processes bootstrap Laravel with independent database
connections and validate the fixture prefix before invoking the real creation
service. A readiness barrier coordinates each group.

For same-user races the parent holds the user-row lock, starts both workers, and
checks server PROCESSLIST until both `FOR UPDATE` statements are in flight. Only
then does it release the lock. Three independent races exercise the duplicate
protection. Different-user tests use separate cycles to avoid the intentional cycle
lock serializing number allocation. Forced shared UUIDs exercise database uniqueness
and retry behavior deterministically rather than relying on accidental UUID collisions.

An injected writer failure occurs after the audit INSERT to prove both applicant
and audit roll back. Direct duplicate INSERT attempts also verify both existing
database uniqueness constraints independently of service validation.

Cleanup stops remaining workers and deletes only records linked to the run's exact
fixture IDs, in FK dependency order. Before/after fingerprints cover every existing
table's complete rows and schema. Only changing AUTO_INCREMENT counters are excluded
from schema comparison; their gaps are expected and are never reset. Concurrent
unrelated portal changes cause a preservation-check failure instead of being silently
ignored. If cleanup or preservation checks fail, the private fixture ledger is retained
for inspection. Abrupt process/host termination can interrupt cleanup; inspect that
run's IDs before manually recovering, never delete by a broad name pattern.

## Recorded real-database results

Verified on 2026-09-23 against **MariaDB 10.4.32**, InnoDB, Laravel's mysql driver,
at **REPEATABLE-READ**. This is the portal's actual database engine, not SQLite and
not an Oracle MySQL 8 verification. Repeat on the exact target engine/configuration
if deployment differs.

| Check | Result |
| --- | --- |
| Same user + same cycle, 3 races | Each yielded 1 creation, 1 clean cycle validation rejection; overlapping lock waits confirmed |
| Different users, 4 concurrent workers | 4 creations, 4 distinct applicant numbers |
| Forced shared UUID, 2 workers | Both succeeded with distinct numbers; attempts were 1 and 2 |
| Repeated forced collision | Exactly 3 number attempts, then unique-constraint exception; no applicant/audit left |
| Failure after audit insertion | 0 applicant rows and 0 audit rows left for the failed operation |
| Direct duplicate owner/cycle and number inserts | Both rejected by database uniqueness |
| Final successful fixture identities | 9 valid draft applicants, exactly 9 correctly linked audit events |
| Cleanup | All 9 applicants/events, 13 synthetic users/profiles, 11 cycles and 1 academic year removed |
| Preservation | All existing schema and data fingerprints matched after cleanup |

No Step 3 correctness defect requiring a schema change was found. Number exhaustion
still raises an internal database exception; a future HTTP boundary must translate
it into a generic response and never expose SQL or server details.

## Automated validation

- Focused Admission suite: 31 passed, 245 assertions.
- Full backend suite: 175 passed, 1,213 assertions.
- `vendor/bin/pint --test`: passed.
- `npm test`: all 10 test files passed.
- `npm run build`: passed.
- `git diff --check`: passed; new files also checked for trailing whitespace.

Focused tests cover audit linkage and shape, sensitive-data exclusion, dirty-field
exclusion, transaction/actor checks, inner and outer rollback, duplicate/read silence,
number collision recovery/exhaustion, append-only model behavior, and additive audit
migration rollback on disposable SQLite data. Existing logging regression coverage
is retained in the full suite.

## Changed files

- `backend/database/migrations/2026_09_23_000001_create_admission_audit_events_table.php`
- `backend/app/Models/Admission/AdmissionAuditEvent.php`
- `backend/app/Services/Admission/AdmissionAuditWriter.php`
- `backend/app/Services/Admission/AdmissionIdentityService.php`
- `backend/tests/Feature/AdmissionAuditTest.php`
- `backend/scripts/verify-admission-concurrency.php`
- `docs/ADMISSION_AUDIT_AND_CONCURRENCY.md`

## Remaining work and recommended Step 7

The audited service and measured concurrency prerequisites pass on the local engine.
This does not authorize exposing creation immediately. Step 7 should separately
implement and review a narrow Guest-only creation API/UI using this service, including
server-owned identity/cycle selection, active-account/profile/intake checks, throttling,
clear duplicate/retry behavior, safe error translation, and endpoint/UI integration
tests. No exam, result, recommendation, Registrar or conversion work is implied.

Before rollout, deploy the audit migration in that environment and repeat concurrency
verification against its engine and isolation settings. Same-cycle requests deliberately
serialize on the cycle lock; the small correctness test is not a throughput/load test.
Audit retention, reader authorization and stronger database-level tamper resistance
remain separate operational decisions. No audit reader or mutation endpoint is exposed.
